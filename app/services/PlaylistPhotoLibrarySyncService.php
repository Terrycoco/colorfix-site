<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPhotoLibraryRepository;
use App\Repos\PdoPhotoRepository;
use App\Repos\PdoPlaylistRepository;
use PDO;
use Throwable;

final class PlaylistPhotoLibrarySyncService
{
    private PdoPlaylistRepository $playlistRepo;
    private PdoPhotoLibraryRepository $photoLibraryRepo;
    private PhotoLibraryService $photoLibraryService;
    private PdoPhotoRepository $photoRepo;
    private PhotoRenderingService $photoRenderingService;

    public function __construct(private PDO $pdo)
    {
        $this->playlistRepo = new PdoPlaylistRepository($pdo);
        $this->photoLibraryRepo = new PdoPhotoLibraryRepository($pdo);
        $this->photoLibraryService = new PhotoLibraryService($this->photoLibraryRepo);
        $this->photoRepo = new PdoPhotoRepository($pdo);
        $this->photoRenderingService = new PhotoRenderingService($this->photoRepo, $pdo);
    }

    public function normalizeItemForSave(array $item): array
    {
        $normalized = $this->resolveLibraryReference([
            'playlist_item_id' => isset($item['playlist_item_id']) ? (int)$item['playlist_item_id'] : 0,
            'playlist_id' => isset($item['playlist_id']) ? (int)$item['playlist_id'] : 0,
            'photo_library_id' => isset($item['photo_library_id']) && $item['photo_library_id'] !== '' ? (int)$item['photo_library_id'] : null,
            'image_url' => isset($item['image_url']) ? (string)$item['image_url'] : '',
            'title' => isset($item['title']) ? (string)$item['title'] : null,
            'item_type' => isset($item['item_type']) ? (string)$item['item_type'] : null,
        ]);

        $item['photo_library_id'] = $normalized['photo_library_id'] ?? null;
        $item['image_url'] = $normalized['image_url'] ?? ($item['image_url'] ?? '');

        $attached = $this->resolveAttachedSavedPaletteForPhoto((int)($item['photo_library_id'] ?? 0));
        if ($attached !== null) {
            $item['palette_hash'] = $attached['palette_hash'];
            $item['saved_palette_set_id'] = $attached['saved_palette_set_id'];
        }

        return $item;
    }

    /**
     * @return array{palette_hash:string,saved_palette_set_id:int}|null
     */
    private function resolveAttachedSavedPaletteForPhoto(int $photoLibraryId): ?array
    {
        if ($photoLibraryId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                spalette.palette_hash,
                sps.id AS saved_palette_set_id
             FROM saved_palette_set_photos spsp
             JOIN saved_palette_sets sps
               ON sps.id = spsp.saved_palette_set_id
             JOIN saved_palettes spalette
               ON spalette.id = sps.saved_palette_id
             WHERE spsp.photo_library_id = :photo_library_id
             ORDER BY sps.is_default DESC, spsp.id ASC
             LIMIT 1"
        );
        $stmt->execute(['photo_library_id' => $photoLibraryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $paletteHash = trim((string)($row['palette_hash'] ?? ''));
        $setId = (int)($row['saved_palette_set_id'] ?? 0);
        if ($paletteHash === '' || $setId <= 0) {
            return null;
        }

        return [
            'palette_hash' => $paletteHash,
            'saved_palette_set_id' => $setId,
        ];
    }

    public function backfill(?int $playlistId = null, bool $apply = false): array
    {
        $rows = $this->playlistRepo->listItemRows($playlistId);
        $results = [];
        $updated = 0;
        $created = 0;
        $missing = 0;

        foreach ($rows as $row) {
            $resolved = $this->resolveLibraryReference($row);
            $changed = (
                (int)($row['photo_library_id'] ?? 0) !== (int)($resolved['photo_library_id'] ?? 0)
                || (string)($row['image_url'] ?? '') !== (string)($resolved['image_url'] ?? '')
            );
            if (($resolved['created'] ?? false) === true) {
                $created++;
            }
            if (($resolved['photo_library_id'] ?? 0) <= 0) {
                $missing++;
            }
            if ($apply && $changed && ($resolved['photo_library_id'] ?? 0) > 0) {
                $this->playlistRepo->updateItemImageReference(
                    (int)$row['playlist_item_id'],
                    (string)$resolved['image_url'],
                    (int)$resolved['photo_library_id']
                );
                $updated++;
            }
            $results[] = [
                'playlist_item_id' => (int)$row['playlist_item_id'],
                'playlist_id' => (int)$row['playlist_id'],
                'title' => (string)($row['title'] ?? ''),
                'before_photo_library_id' => $row['photo_library_id'] !== null ? (int)$row['photo_library_id'] : null,
                'after_photo_library_id' => ($resolved['photo_library_id'] ?? 0) > 0 ? (int)$resolved['photo_library_id'] : null,
                'before_image_url' => (string)($row['image_url'] ?? ''),
                'after_image_url' => (string)($resolved['image_url'] ?? ''),
                'changed' => $changed,
                'created' => (bool)($resolved['created'] ?? false),
                'status' => (string)($resolved['status'] ?? 'unresolved'),
            ];
        }

        return [
            'total' => count($rows),
            'updated' => $updated,
            'created' => $created,
            'missing' => $missing,
            'items' => $results,
        ];
    }

    public function repairBrokenLibraryReferences(?int $playlistId = null, bool $apply = false): array
    {
        $rows = $this->playlistRepo->listItemRows($playlistId);
        $results = [];
        $checked = 0;
        $repaired = 0;
        $updated = 0;
        $broken = 0;

        foreach ($rows as $row) {
            $photoLibraryId = (int)($row['photo_library_id'] ?? 0);
            if ($photoLibraryId <= 0) {
                continue;
            }

            $checked++;
            $libraryRow = $this->photoLibraryRepo->findById($photoLibraryId);
            if (!$libraryRow) {
                $broken++;
                $results[] = [
                    'playlist_item_id' => (int)$row['playlist_item_id'],
                    'playlist_id' => (int)$row['playlist_id'],
                    'photo_library_id' => $photoLibraryId,
                    'source_type' => null,
                    'before_rel_path' => null,
                    'after_rel_path' => null,
                    'changed' => false,
                    'repaired' => false,
                    'status' => 'missing_photo_library_row',
                ];
                continue;
            }

            $beforeRelPath = trim((string)($libraryRow['rel_path'] ?? ''));
            if ($this->libraryPathExists($beforeRelPath)) {
                continue;
            }

            $broken++;
            $repair = $this->attemptRepairBrokenLibraryRow($libraryRow);
            $afterPhotoLibraryId = (int)($repair['photo_library_id'] ?? 0);
            $afterRelPath = (string)($repair['rel_path'] ?? '');
            $rowRepaired = !empty($repair['repaired']) && $afterPhotoLibraryId > 0 && $this->libraryPathExists($afterRelPath);
            $changed = false;

            if ($rowRepaired) {
                $repaired++;
                $canonical = $this->normalizeStoredReference($afterRelPath, $afterPhotoLibraryId);
                $changed = (
                    $afterPhotoLibraryId !== $photoLibraryId
                    || (string)($row['image_url'] ?? '') !== $canonical
                );
                if ($apply && $changed) {
                    $this->playlistRepo->updateItemImageReference(
                        (int)$row['playlist_item_id'],
                        $canonical,
                        $afterPhotoLibraryId
                    );
                    $updated++;
                }
            }

            $results[] = [
                'playlist_item_id' => (int)$row['playlist_item_id'],
                'playlist_id' => (int)$row['playlist_id'],
                'photo_library_id' => $photoLibraryId,
                'source_type' => (string)($libraryRow['source_type'] ?? ''),
                'before_rel_path' => $beforeRelPath !== '' ? $beforeRelPath : null,
                'after_rel_path' => $afterRelPath !== '' ? $afterRelPath : null,
                'changed' => $changed,
                'repaired' => $rowRepaired,
                'status' => (string)($repair['status'] ?? 'broken_unrepaired'),
                'error' => $repair['error'] ?? null,
            ];
        }

        return [
            'checked' => $checked,
            'broken' => $broken,
            'repaired' => $repaired,
            'updated' => $updated,
            'items' => $results,
        ];
    }

    /**
     * @param array{
     *   playlist_item_id?:int,
     *   playlist_id?:int,
     *   photo_library_id?:int|null,
     *   image_url?:string|null,
     *   title?:string|null,
     *   item_type?:string|null
     * } $row
     * @return array{
     *   photo_library_id:?int,
     *   image_url:string,
     *   created:bool,
     *   status:string
     * }
     */
    private function resolveLibraryReference(array $row): array
    {
        $photoLibraryId = isset($row['photo_library_id']) ? (int)$row['photo_library_id'] : 0;
        $imageUrl = trim((string)($row['image_url'] ?? ''));
        $title = $this->cleanNullableString($row['title'] ?? null);

        if ($photoLibraryId > 0) {
            $existing = $this->photoLibraryRepo->findById($photoLibraryId);
            if ($existing) {
                $canonical = $this->normalizeStoredReference((string)($existing['rel_path'] ?? ''), $photoLibraryId);
                return [
                    'photo_library_id' => $photoLibraryId,
                    'image_url' => $canonical !== '' ? $canonical : $imageUrl,
                    'created' => false,
                    'status' => 'existing_id',
                ];
            }
        }

        [$parsedPhotoId, $parsedPhotoUrl] = $this->parsePhotoRef($imageUrl);
        if ($parsedPhotoId > 0) {
            $existing = $this->photoLibraryRepo->findById($parsedPhotoId);
            if ($existing) {
                return [
                    'photo_library_id' => $parsedPhotoId,
                    'image_url' => $this->normalizeStoredReference((string)($existing['rel_path'] ?? ''), $parsedPhotoId),
                    'created' => false,
                    'status' => 'photo_ref_existing_id',
                ];
            }
            $photoLibraryId = $parsedPhotoId;
            $imageUrl = $parsedPhotoUrl;
        }

        $assetId = $this->extractAssetRef($imageUrl);
        if ($assetId !== '') {
            $resolved = $this->resolveAssetPhoto($assetId, $title);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $normalizedPath = $this->normalizeLibraryPath($imageUrl);
        if ($normalizedPath !== '') {
            $existingId = $this->photoLibraryRepo->findIdByRelPath($normalizedPath);
            if ($existingId) {
                return [
                    'photo_library_id' => $existingId,
                    'image_url' => $this->normalizeStoredReference($normalizedPath, $existingId),
                    'created' => false,
                    'status' => 'matched_rel_path',
                ];
            }

            $createdId = $this->photoLibraryService->createStandalone('playlist_item', $normalizedPath, [
                'title' => $title,
                'show_in_gallery' => 0,
                'has_palette' => 0,
            ]);
            if ($createdId > 0) {
                return [
                    'photo_library_id' => $createdId,
                    'image_url' => $this->normalizeStoredReference($normalizedPath, $createdId),
                    'created' => true,
                    'status' => 'created_from_path',
                ];
            }
        }

        return [
            'photo_library_id' => null,
            'image_url' => $imageUrl,
            'created' => false,
            'status' => 'unresolved',
        ];
    }

    private function resolveAssetPhoto(string $assetId, ?string $title): ?array
    {
        $photo = $this->photoRepo->getPhotoByAssetId($assetId);
        if (!$photo) {
            return null;
        }

        $relPath = $this->pickAssetRelPath((int)$photo['id']);
        if ($relPath === '') {
            return null;
        }

        $photoLibraryId = $this->photoLibraryService->syncAssetPhoto((int)$photo['id'], $relPath, [
            'title' => $title,
            'show_in_gallery' => 0,
            'has_palette' => 0,
        ]);
        if ($photoLibraryId <= 0) {
            return null;
        }

        return [
            'photo_library_id' => $photoLibraryId,
            'image_url' => $this->normalizeStoredReference($relPath, $photoLibraryId),
            'created' => false,
            'status' => 'asset_ref',
        ];
    }

    private function pickAssetRelPath(int $photoId): string
    {
        $variants = $this->photoRepo->listVariants($photoId);
        $bestPath = '';
        $bestScore = 999;

        foreach ($variants as $variant) {
            $kind = (string)($variant['kind'] ?? '');
            $role = (string)($variant['role'] ?? '');
            $path = trim((string)($variant['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $score = 999;
            if ($kind === 'prepared' && $role === '') {
                $score = 0;
            } elseif ($kind === 'prepared_base') {
                $score = 1;
            } elseif ($kind === 'repaired' && $role === '') {
                $score = 2;
            } elseif ($kind === 'repaired_base') {
                $score = 3;
            } elseif ($kind === 'thumb') {
                $score = 4;
            }

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestPath = $path;
            }
        }

        return $bestPath;
    }

    /**
     * @param array<string, mixed> $libraryRow
     * @return array{photo_library_id?:int,rel_path?:string,status:string,repaired?:bool,error?:string}
     */
    private function attemptRepairBrokenLibraryRow(array $libraryRow): array
    {
        $sourceType = trim((string)($libraryRow['source_type'] ?? ''));
        $sourceId = isset($libraryRow['source_id']) ? (int)$libraryRow['source_id'] : 0;

        try {
            if ($sourceType === 'client') {
                $clientRelPath = trim((string)($libraryRow['rel_path'] ?? ''));
                $relinkedClientRow = $this->repairSavedPaletteLikeClientRow($clientRelPath);
                if ($relinkedClientRow !== null) {
                    return $relinkedClientRow;
                }
            }

            if ($sourceType === 'applied_palette' && $sourceId > 0) {
                return ['status' => 'legacy_applied_palette_removed'];
            }

            if (in_array($sourceType, ['applied_palette_photo', 'applied_before'], true) && $sourceId > 0) {
                return ['status' => 'legacy_applied_palette_photo_removed'];
            }
        } catch (Throwable $e) {
            return [
                'status' => 'repair_failed',
                'error' => $e->getMessage(),
            ];
        }

        return ['status' => 'unsupported_source_type'];
    }

    /**
     * @return array{photo_library_id:int,rel_path:string,status:string,repaired:bool}|null
     */
    private function repairSavedPaletteLikeClientRow(string $relPath): ?array
    {
        $relPath = trim($relPath);
        if (!preg_match('#^/photos/uploads/saved-palettes/(\d+)/#', $relPath, $matches)) {
            return null;
        }

        $exactSavedPaletteId = $this->photoLibraryRepo->findIdBySourceTypeAndRelPath('saved_palette_photo', $relPath);
        if ($exactSavedPaletteId) {
            $exactRow = $this->photoLibraryRepo->findById($exactSavedPaletteId);
            $exactRelPath = trim((string)($exactRow['rel_path'] ?? ''));
            if ($exactRelPath !== '' && $this->libraryPathExists($exactRelPath)) {
                return [
                    'photo_library_id' => $exactSavedPaletteId,
                    'rel_path' => $exactRelPath,
                    'status' => 'relinked_saved_palette_exact',
                    'repaired' => true,
                ];
            }
        }

        $folderPrefix = '/photos/uploads/saved-palettes/' . (int)$matches[1] . '/';
        $latestSavedPaletteId = $this->photoLibraryRepo->findLatestIdBySourceTypeAndRelPrefix('saved_palette_photo', $folderPrefix);
        if (!$latestSavedPaletteId) {
            return null;
        }

        $latestRow = $this->photoLibraryRepo->findById($latestSavedPaletteId);
        $latestRelPath = trim((string)($latestRow['rel_path'] ?? ''));
        if ($latestRelPath === '' || !$this->libraryPathExists($latestRelPath)) {
            return null;
        }

        return [
            'photo_library_id' => $latestSavedPaletteId,
            'rel_path' => $latestRelPath,
            'status' => 'relinked_saved_palette_latest',
            'repaired' => true,
        ];
    }

    /**
     * @return array{0:int,1:string}
     */
    private function parsePhotoRef(string $value): array
    {
        if (!str_starts_with($value, 'photo:')) {
            return [0, $value];
        }
        $rest = substr($value, strlen('photo:'));
        $splitAt = strpos($rest, '|');
        if ($splitAt === false) {
            return [(int)trim($rest), ''];
        }
        return [
            (int)trim(substr($rest, 0, $splitAt)),
            trim(substr($rest, $splitAt + 1)),
        ];
    }

    private function extractAssetRef(string $value): string
    {
        if (!str_starts_with($value, 'asset:')) {
            return '';
        }
        return trim(substr($value, strlen('asset:')));
    }

    private function normalizeStoredReference(string $relPath, int $photoLibraryId): string
    {
        $cleanPath = $this->normalizeLibraryPath($relPath);
        if ($cleanPath === '') {
            return '';
        }
        return 'photo:' . $photoLibraryId . '|' . $cleanPath;
    }

    private function libraryPathExists(string $relPath): bool
    {
        $cleanPath = $this->normalizeLibraryPath($relPath);
        if ($cleanPath === '' || !str_starts_with($cleanPath, '/')) {
            return false;
        }

        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2)), '/');
        $primary = $docRoot . $cleanPath;
        if (is_file($primary)) {
            return true;
        }

        $fallback = dirname(__DIR__, 2) . $cleanPath;
        return is_file($fallback);
    }

    private function normalizeLibraryPath(string $value): string
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, 'asset:') || str_starts_with($value, 'photo:')) {
            return '';
        }

        if (preg_match('~^https?://~i', $value)) {
            $parts = parse_url($value);
            $host = strtolower(trim((string)($parts['host'] ?? '')));
            $currentHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
            $path = trim((string)($parts['path'] ?? ''));
            if ($path !== '' && str_starts_with($path, '/') && ($host === '' || ($currentHost !== '' && $host === $currentHost))) {
                return $path;
            }
            return $value;
        }

        $path = strtok($value, '?#');
        return $path !== false ? trim((string)$path) : $value;
    }

    private function cleanNullableString(mixed $value): ?string
    {
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }
}
