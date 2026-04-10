<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPhotoLibraryRepository;

class PhotoLibraryService
{
    public function __construct(private PdoPhotoLibraryRepository $repo) {}

    private function guessSourceTypeForRelPath(string $relPath): string
    {
        $path = strtolower($relPath);
        return match (true) {
            str_contains($path, '/photos/articles/') => 'article',
            str_contains($path, '/photos/pins/') => 'pin',
            str_contains($path, '/photos/progressions/') => 'progression',
            str_contains($path, '/photos/uploads/saved-palettes/') => 'saved_palette_photo',
            str_contains($path, '/photos/rendered/') => 'saved_palette_photo',
            str_contains($path, '/photos/clients/') => 'client',
            default => 'photo_base',
        };
    }

    private function guessTitleForRelPath(string $relPath): string
    {
        $name = pathinfo($relPath, PATHINFO_FILENAME);
        $name = preg_replace('/[_-]+/', ' ', (string)$name) ?? '';
        return trim($name);
    }

    public function syncSavedPalettePhoto(array $photo, array $overrides = []): int
    {
        $photoType = strtolower((string)($photo['photo_type'] ?? ''));
        $triggerMode = strtolower((string)($photo['trigger_mode'] ?? 'any'));
        if (!in_array($triggerMode, ['any', 'none', 'color'], true)) {
            $triggerMode = 'any';
        }
        $sourceType = $photoType === 'before' ? 'saved_before' : 'saved_palette_photo';
        $sourceId = isset($photo['id']) ? (int)$photo['id'] : null;
        $relPath = (string)($photo['rel_path'] ?? '');
        if ($relPath === '' || !$sourceId) {
            return 0;
        }

        $tags = $overrides['tags'] ?? null;
        if ($photoType === 'before') {
            $tags = trim((string)($tags ?? ''));
            $tags = $tags === '' ? 'before' : $tags . ',before';
        }

        $data = [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'rel_path' => $relPath,
            'title' => $overrides['title'] ?? ($photo['caption'] ?? null),
            'tags' => $tags ?: null,
            'alt_text' => $overrides['alt_text'] ?? ($photo['alt_text'] ?? null),
            'show_in_gallery' => array_key_exists('show_in_gallery', $overrides)
                ? (int)$overrides['show_in_gallery']
                : ($triggerMode === 'none' || $photoType === 'before' ? 0 : 1),
            'has_palette' => array_key_exists('has_palette', $overrides)
                ? (int)$overrides['has_palette']
                : ($triggerMode === 'none' || $photoType === 'before' ? 0 : 1),
        ];

        $canonicalId = $this->repo->findCanonicalIdByRelPath($relPath);
        if ($canonicalId) {
            return $canonicalId;
        }

        $existingId = $this->repo->findIdBySourceAndRel($sourceType, $sourceId, $relPath);
        if (!$existingId) {
            $fallbackType = $sourceType === 'saved_before' ? 'saved_palette_photo' : 'saved_before';
            $existingId = $this->repo->findIdBySourceAndRel($fallbackType, $sourceId, $relPath);
        }
        if (!$existingId) {
            $existingId = $this->repo->findIdBySource($sourceType, $sourceId);
        }
        if (!$existingId) {
            $fallbackType = $sourceType === 'saved_before' ? 'saved_palette_photo' : 'saved_before';
            $existingId = $this->repo->findIdBySource($fallbackType, $sourceId);
        }
        if ($existingId) {
            $this->repo->update($existingId, $data);
            return $existingId;
        }
        return $this->repo->insert($data);
    }

    public function deleteSavedPalettePhoto(int $photoId): void
    {
        if ($photoId <= 0) {
            return;
        }
        $this->repo->deleteBySource('saved_palette_photo', $photoId);
        $this->repo->deleteBySource('saved_before', $photoId);
    }

    public function syncAppliedPalettePhoto(array $palette, string $relPath, array $overrides = []): int
    {
        $paletteId = isset($palette['id']) ? (int)$palette['id'] : 0;
        $relPath = trim($relPath);
        if ($paletteId <= 0 || $relPath === '') {
            return 0;
        }
        $sourceType = 'applied_palette';
        $title = $palette['display_title'] ?? $palette['title'] ?? null;
        $data = [
            'source_type' => $sourceType,
            'source_id' => $paletteId,
            'rel_path' => $relPath,
            'title' => $overrides['title'] ?? $title,
            'tags' => $overrides['tags'] ?? ($palette['tags'] ?? null),
            'alt_text' => $overrides['alt_text'] ?? ($palette['alt_text'] ?? null),
        ];

        $existingId = $this->repo->findIdBySourceAndRel($sourceType, $paletteId, $relPath);
        if ($existingId) {
            $updateData = ['rel_path' => $relPath];
            if (array_key_exists('title', $overrides)) $updateData['title'] = $overrides['title'];
            if (array_key_exists('tags', $overrides)) $updateData['tags'] = $overrides['tags'];
            if (array_key_exists('alt_text', $overrides)) $updateData['alt_text'] = $overrides['alt_text'];
            $this->repo->update($existingId, $updateData);
            return $existingId;
        }

        $data['show_in_gallery'] = array_key_exists('show_in_gallery', $overrides) ? (int)$overrides['show_in_gallery'] : 0;
        $data['has_palette'] = array_key_exists('has_palette', $overrides) ? (int)$overrides['has_palette'] : 1;
        return $this->repo->insert($data);
    }

    public function deleteAppliedPalettePhoto(int $paletteId): void
    {
        if ($paletteId <= 0) {
            return;
        }
        $this->repo->deleteBySource('applied_palette', $paletteId);
    }

    public function syncAppliedPaletteAttachmentPhoto(array $photo, array $overrides = []): int
    {
        $photoType = strtolower((string)($photo['photo_type'] ?? ''));
        $triggerMode = strtolower((string)($photo['trigger_mode'] ?? 'any'));
        if (!in_array($triggerMode, ['any', 'none', 'color'], true)) {
            $triggerMode = 'any';
        }
        $sourceType = $photoType === 'before' ? 'applied_before' : 'applied_palette_photo';
        $sourceId = isset($photo['id']) ? (int)$photo['id'] : null;
        $relPath = (string)($photo['rel_path'] ?? '');
        if ($relPath === '' || !$sourceId) {
            return 0;
        }

        $tags = $overrides['tags'] ?? null;
        if ($photoType === 'before') {
            $tags = trim((string)($tags ?? ''));
            $tags = $tags === '' ? 'before' : $tags . ',before';
        }

        $data = [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'rel_path' => $relPath,
            'title' => $overrides['title'] ?? ($photo['caption'] ?? null),
            'tags' => $tags ?: null,
            'alt_text' => $overrides['alt_text'] ?? ($photo['alt_text'] ?? null),
            'show_in_gallery' => array_key_exists('show_in_gallery', $overrides)
                ? (int)$overrides['show_in_gallery']
                : ($triggerMode === 'none' || $photoType === 'before' ? 0 : 1),
            'has_palette' => array_key_exists('has_palette', $overrides)
                ? (int)$overrides['has_palette']
                : ($triggerMode === 'none' || $photoType === 'before' ? 0 : 1),
        ];

        $existingId = $this->repo->findIdBySourceAndRel($sourceType, $sourceId, $relPath);
        if (!$existingId) {
            $fallbackType = $sourceType === 'applied_before' ? 'applied_palette_photo' : 'applied_before';
            $existingId = $this->repo->findIdBySourceAndRel($fallbackType, $sourceId, $relPath);
        }
        if ($existingId) {
            $this->repo->update($existingId, $data);
            return $existingId;
        }
        return $this->repo->insert($data);
    }

    public function deleteAppliedPaletteAttachmentPhoto(int $photoId): void
    {
        if ($photoId <= 0) {
            return;
        }
        $this->repo->deleteBySource('applied_palette_photo', $photoId);
        $this->repo->deleteBySource('applied_before', $photoId);
    }

    public function syncExtraPhoto(int $photoId, string $role, string $relPath, array $overrides = []): int
    {
        $role = trim($role);
        $relPath = trim($relPath);
        if ($photoId <= 0 || $relPath === '') {
            return 0;
        }
        $sourceType = 'extra_photo';
        $data = [
            'source_type' => $sourceType,
            'source_id' => $photoId,
            'rel_path' => $relPath,
            'title' => $overrides['title'] ?? ($role !== '' ? $role : null),
            'tags' => $overrides['tags'] ?? null,
            'alt_text' => $overrides['alt_text'] ?? null,
            'show_in_gallery' => array_key_exists('show_in_gallery', $overrides) ? (int)$overrides['show_in_gallery'] : 0,
            'has_palette' => array_key_exists('has_palette', $overrides) ? (int)$overrides['has_palette'] : 0,
        ];

        $existingId = null;
        if (!empty($data['title'])) {
            $existingId = $this->repo->findIdBySourceAndTitle($sourceType, $photoId, (string)$data['title']);
        }
        if (!$existingId) {
            $existingId = $this->repo->findIdBySourceAndRel($sourceType, $photoId, $relPath);
        }
        if ($existingId) {
            $this->repo->update($existingId, $data);
            return $existingId;
        }
        return $this->repo->insert($data);
    }

    public function syncAssetPhoto(int $photoId, string $relPath, array $overrides = []): int
    {
        $relPath = trim($relPath);
        if ($photoId <= 0 || $relPath === '') {
            return 0;
        }
        $sourceType = 'photo_asset';
        $data = [
            'source_type' => $sourceType,
            'source_id' => $photoId,
            'rel_path' => $relPath,
            'title' => $overrides['title'] ?? null,
            'tags' => $overrides['tags'] ?? null,
            'alt_text' => $overrides['alt_text'] ?? null,
            'show_in_gallery' => array_key_exists('show_in_gallery', $overrides) ? (int)$overrides['show_in_gallery'] : 0,
            'has_palette' => array_key_exists('has_palette', $overrides) ? (int)$overrides['has_palette'] : 0,
        ];

        $existingId = $this->repo->findIdBySourceAndRel($sourceType, $photoId, $relPath);
        if ($existingId) {
            $this->repo->update($existingId, $data);
            return $existingId;
        }
        return $this->repo->insert($data);
    }

    public function createStandalone(string $sourceType, string $relPath, array $overrides = []): int
    {
        $sourceType = trim($sourceType);
        $relPath = trim($relPath);
        if ($sourceType === '' || $relPath === '') {
            return 0;
        }
        $data = [
            'source_type' => $sourceType,
            'source_id' => array_key_exists('source_id', $overrides) ? $overrides['source_id'] : null,
            'client_id' => array_key_exists('client_id', $overrides) ? $overrides['client_id'] : null,
            'rel_path' => $relPath,
            'title' => $overrides['title'] ?? null,
            'tags' => $overrides['tags'] ?? null,
            'alt_text' => $overrides['alt_text'] ?? null,
            'show_in_gallery' => array_key_exists('show_in_gallery', $overrides) ? (int)$overrides['show_in_gallery'] : 0,
            'has_palette' => array_key_exists('has_palette', $overrides) ? (int)$overrides['has_palette'] : 0,
        ];
        return $this->repo->insert($data);
    }

    public function deleteExtraPhoto(int $photoId, string $role): void
    {
        if ($photoId <= 0) {
            return;
        }
        $role = trim($role);
        if ($role === '') {
            $this->repo->deleteBySource('extra_photo', $photoId);
            return;
        }
        $this->repo->deleteBySourceAndTitle('extra_photo', $photoId, $role);
    }

    public function dedupeSavedPaletteRows(bool $apply = false): array
    {
        $rows = $this->repo->listSavedPaletteSourceRows();

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

            $canonicalId = $this->repo->findCanonicalIdByRelPath($relPath);
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
                    $this->repo->reassignAllUsages($photoLibraryId, $canonicalId);
                    $this->repo->deleteById($photoLibraryId);
                    $result['deduped']++;
                    $result['deleted']++;
                }
            }
        }

        return $result;
    }

    public function dedupeAllRelPathRows(bool $apply = false): array
    {
        $rows = $this->repo->listAllRowsWithRelPath();

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

            $canonicalId = $this->repo->findCanonicalIdByRelPath($relPath);
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
                    $this->repo->reassignAllUsages($photoLibraryId, $canonicalId);
                    $this->repo->deleteById($photoLibraryId);
                    $result['deduped']++;
                    $result['deleted']++;
                }
            }
        }

        return $result;
    }

    public function reconcileFilesystemRows(bool $apply = false): array
    {
        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 3)), '/');
        $photosRoot = $docRoot . '/photos';
        if (!is_dir($photosRoot)) {
            return [
                'checked' => 0,
                'missing' => 0,
                'created' => 0,
                'items' => [],
                'error' => 'Photos directory not found',
            ];
        }

        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($photosRoot, \FilesystemIterator::SKIP_DOTS)
        );

        $result = [
            'checked' => 0,
            'missing' => 0,
            'created' => 0,
            'items' => [],
        ];

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $ext = strtolower((string)$fileInfo->getExtension());
            if (!in_array($ext, $allowedExt, true)) {
                continue;
            }

            $absPath = $fileInfo->getPathname();
            $relPath = str_replace('\\', '/', substr($absPath, strlen($docRoot)));
            if ($relPath === '' || !str_starts_with($relPath, '/photos/')) {
                continue;
            }

            $result['checked']++;

            $existingId = $this->repo->findIdByRelPath($relPath);
            if ($existingId) {
                continue;
            }

            $result['missing']++;
            $sourceType = $this->guessSourceTypeForRelPath($relPath);
            $title = $this->guessTitleForRelPath($relPath);
            $createdId = null;

            if ($apply) {
                $createdId = $this->createStandalone($sourceType, $relPath, [
                    'title' => $title !== '' ? $title : null,
                    'show_in_gallery' => 0,
                    'has_palette' => in_array($sourceType, ['saved_palette_photo'], true) ? 1 : 0,
                ]);
                if ($createdId > 0) {
                    $result['created']++;
                }
            }

            $result['items'][] = [
                'rel_path' => $relPath,
                'source_type' => $sourceType,
                'title' => $title,
                'created_photo_library_id' => $createdId,
            ];
        }

        usort($result['items'], static function (array $a, array $b): int {
            return strcmp((string)($a['rel_path'] ?? ''), (string)($b['rel_path'] ?? ''));
        });

        return $result;
    }
}
