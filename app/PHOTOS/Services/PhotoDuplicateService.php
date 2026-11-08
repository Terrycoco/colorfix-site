<?php
declare(strict_types=1);
namespace App\PHOTOS\Services;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use PDO;

final class PhotoDuplicateService
{
    private PdoPhotoLibraryRepository $repo;
    private PhotoLifecycleService $lifecycle;
    public function __construct(private PDO $pdo)
    {
        $this->repo = new PdoPhotoLibraryRepository($pdo);
        $this->lifecycle = new PhotoLifecycleService($pdo);
    }
    private function parentDirKey(string $path): string
    {
        return rtrim(dirname(preg_replace('/\?.*$/', '', trim($path)) ?? $path), '/');
    }
    public function candidates(): array
    {
        $rows = $this->pdo->query("SELECT photo_library_id, source_type, source_id, title, rel_path, updated_at FROM photo_library WHERE rel_path IS NOT NULL AND rel_path <> '' AND COALESCE(is_inactive,0)=0 ORDER BY rel_path,photo_library_id")->fetchAll(PDO::FETCH_ASSOC);
        $usage = new PhotoLibraryUsageService($this->repo);
        $paths = []; $folders = [];
        foreach ($rows as $row) {
            $paths[$row['rel_path']] = ($paths[$row['rel_path']] ?? 0) + 1;
            if ($row['source_type'] === 'saved_before') {
                $key = $this->parentDirKey($row['rel_path']);
                $folders[$key] = ($folders[$key] ?? 0) + 1;
            }
        }
        foreach ($rows as &$row) {
            $candidate = $paths[$row['rel_path']] > 1 || ($row['source_type'] === 'saved_before' && ($folders[$this->parentDirKey($row['rel_path'])] ?? 0) > 1);
            $row['usage_count'] = $candidate ? count($usage->getUsageSummary((int)$row['photo_library_id'])) : 0;
        }
        unset($row);
        $exactGroups = [];
        $beforeFolderGroups = [];

        foreach ($rows as $row) {
            $relPath = trim((string)($row['rel_path'] ?? ''));
            if ($relPath === '') {
                continue;
            }

            $item = [
                'photo_library_id' => (int)$row['photo_library_id'],
                'source_type' => (string)($row['source_type'] ?? ''),
                'source_id' => $row['source_id'] !== null ? (int)$row['source_id'] : null,
                'title' => (string)($row['title'] ?? ''),
                'rel_path' => $relPath,
                'updated_at' => $row['updated_at'] ?? null,
                'usage_count' => (int)($row['usage_count'] ?? 0),
            ];

            $exactGroups[$relPath][] = $item;

            if (($item['source_type'] ?? '') === 'saved_before') {
                $dirKey = $this->parentDirKey($relPath);
                if ($dirKey !== '') {
                    $beforeFolderGroups[$dirKey][] = $item;
                }
            }
        }

        $items = [];

        foreach ($exactGroups as $relPath => $groupRows) {
            if (count($groupRows) < 2) {
                continue;
            }
            usort($groupRows, static fn(array $a, array $b): int => ($b['usage_count'] ?? 0) <=> ($a['usage_count'] ?? 0) ?: (($a['photo_library_id'] ?? 0) <=> ($b['photo_library_id'] ?? 0)));
            $items[] = [
                'match_type' => 'exact',
                'reason' => 'Exact duplicate file path',
                'group_key' => $relPath,
                'duplicate_count' => count($groupRows),
                'items' => $groupRows,
            ];
        }

        foreach ($beforeFolderGroups as $dirKey => $groupRows) {
            if (count($groupRows) < 2) {
                continue;
            }

            $distinctPaths = array_values(array_unique(array_map(static fn(array $row): string => (string)$row['rel_path'], $groupRows)));
            if (count($distinctPaths) < 2) {
                continue;
            }

            usort($groupRows, static fn(array $a, array $b): int => ($b['usage_count'] ?? 0) <=> ($a['usage_count'] ?? 0) ?: (($a['photo_library_id'] ?? 0) <=> ($b['photo_library_id'] ?? 0)));
            $items[] = [
                'match_type' => 'maybe_same',
                'reason' => 'May be the same before photo in the same source folder',
                'group_key' => $dirKey,
                'duplicate_count' => count($groupRows),
                'items' => $groupRows,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $typeScore = static fn(string $type): int => $type === 'exact' ? 0 : 1;
            $cmp = $typeScore((string)($a['match_type'] ?? '')) <=> $typeScore((string)($b['match_type'] ?? ''));
            if ($cmp !== 0) return $cmp;
            return (string)($a['group_key'] ?? '') <=> (string)($b['group_key'] ?? '');
        });

        return ['group_count' => count($items), 'items' => $items];
    }

    public function dedupeSavedPaletteRows(bool $apply = false): array
    {
        $rows = array_values(array_filter($this->repo->listSavedPaletteSourceRows(), fn(array $r): bool => empty($this->repo->findById((int)$r['photo_library_id'])['is_inactive'])));

        $groups = [];
        foreach ($rows as $row) {
            $relPath = (string)($row['rel_path'] ?? '');
            if ($relPath === '') {
                continue;
            }
            $groups[$relPath][] = $row;
        }

        $result = [
            'checked' => count($rows),
            'deduped' => 0,
            'deleted' => 0,
            'items' => [],
        ];

        foreach ($groups as $relPath => $groupRows) {
            if (count($groupRows) < 2) {
                continue;
            }

            $stmt = $this->pdo->prepare("SELECT photo_library_id FROM photo_library WHERE rel_path=? AND COALESCE(is_inactive,0)=0 ORDER BY CASE WHEN source_type IN ('saved_palette_photo','saved_before') THEN 1 ELSE 0 END, updated_at DESC, created_at DESC, photo_library_id DESC LIMIT 1");
            $stmt->execute([$relPath]);
            $canonicalId = (int)$stmt->fetchColumn();
            if (!$canonicalId) {
                continue;
            }

            foreach ($groupRows as $row) {
                $photoLibraryId = (int)($row['photo_library_id'] ?? 0);
                if ($photoLibraryId <= 0 || $photoLibraryId === $canonicalId) {
                    continue;
                }

                $result['items'][] = [
                    'rel_path' => $relPath,
                    'from_photo_library_id' => $photoLibraryId,
                    'to_photo_library_id' => $canonicalId,
                    'source_type' => (string)($row['source_type'] ?? ''),
                    'changed' => $photoLibraryId !== $canonicalId,
                ];

                if ($apply) {
                    try {
                        $this->lifecycle->merge($photoLibraryId, $canonicalId, true);
                        $result['deduped']++;
                        $result['retired'] = ($result['retired'] ?? 0) + 1;
                    } catch (\DomainException $e) {
                        $result['items'][count($result['items']) - 1]['blocked_reason'] = $e->getMessage();
                        $result['items'][count($result['items']) - 1]['changed'] = false;
                    }
                }
            }
        }

        return $result;
    }

    public function dedupeAllRelPathRows(bool $apply = false): array
    {
        $rows = array_values(array_filter($this->repo->listAllRowsWithRelPath(), fn(array $r): bool => empty($this->repo->findById((int)$r['photo_library_id'])['is_inactive'])));

        $groups = [];
        foreach ($rows as $row) {
            $relPath = trim((string)($row['rel_path'] ?? ''));
            if ($relPath === '') {
                continue;
            }
            $groups[$relPath][] = $row;
        }

        $result = [
            'checked' => count($rows),
            'deduped' => 0,
            'deleted' => 0,
            'items' => [],
        ];

        foreach ($groups as $relPath => $groupRows) {
            if (count($groupRows) < 2) {
                continue;
            }

            $stmt = $this->pdo->prepare("SELECT photo_library_id FROM photo_library WHERE rel_path=? AND COALESCE(is_inactive,0)=0 ORDER BY CASE WHEN source_type IN ('saved_palette_photo','saved_before') THEN 1 ELSE 0 END, updated_at DESC, created_at DESC, photo_library_id DESC LIMIT 1");
            $stmt->execute([$relPath]);
            $canonicalId = (int)$stmt->fetchColumn();
            if (!$canonicalId) {
                continue;
            }

            foreach ($groupRows as $row) {
                $photoLibraryId = (int)($row['photo_library_id'] ?? 0);
                if ($photoLibraryId <= 0 || $photoLibraryId === $canonicalId) {
                    continue;
                }

                $result['items'][] = [
                    'rel_path' => $relPath,
                    'from_photo_library_id' => $photoLibraryId,
                    'to_photo_library_id' => $canonicalId,
                    'source_type' => (string)($row['source_type'] ?? ''),
                    'changed' => true,
                ];

                if ($apply) {
                    try {
                        $this->lifecycle->merge($photoLibraryId, $canonicalId, true);
                        $result['deduped']++;
                        $result['retired'] = ($result['retired'] ?? 0) + 1;
                    } catch (\DomainException $e) {
                        $result['items'][count($result['items']) - 1]['blocked_reason'] = $e->getMessage();
                        $result['items'][count($result['items']) - 1]['changed'] = false;
                    }
                }
            }
        }

        return $result;
    }

}
