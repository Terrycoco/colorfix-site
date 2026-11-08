<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Services;


use App\Services\PhotoLibraryService;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use PDO;

final class PlaylistPhotoLibrarySyncService
{
    private PdoPlaylistRepository $playlistRepo;
    private PdoPhotoLibraryRepository $photoLibraryRepo;
    private PhotoLibraryService $photoLibraryService;

    public function __construct(private PDO $pdo)
    {
        $this->playlistRepo = new PdoPlaylistRepository($pdo);
        $this->photoLibraryRepo = new PdoPhotoLibraryRepository($pdo);
        $this->photoLibraryService = new PhotoLibraryService($this->photoLibraryRepo);
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


        return $item;
    }



    public function backfill(?int $playlistId = null, bool $apply = false): array
    {
        $rows = $this->playlistRepo->listItemRows($playlistId);
        $results = [];
        $updated = 0;
        $created = 0;
        $missing = 0;

        foreach ($rows as $row) {
            $resolved = $this->resolveLibraryReference($row, $apply);
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
    private function resolveLibraryReference(array $row, bool $allowCreate = true): array
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

            if (!$allowCreate || preg_match('~^/photos/(?:exteriors/|rendered/ap_)|/PHO_[^/]+/(?:prepared|masks?|repaired)/~i',$normalizedPath)) {
                return ['photo_library_id'=>null,'image_url'=>$imageUrl,'created'=>false,'status'=>'unregistered_path'];
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

    /**
     * @param array<string, mixed> $libraryRow
     * @return array{photo_library_id?:int,rel_path?:string,status:string,repaired?:bool,error?:string}
     */
    /**
     * @return array{photo_library_id:int,rel_path:string,status:string,repaired:bool}|null
     */
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

    private function normalizeStoredReference(string $relPath, int $photoLibraryId): string
    {
        $cleanPath = $this->normalizeLibraryPath($relPath);
        if ($cleanPath === '') {
            return '';
        }
        return 'photo:' . $photoLibraryId . '|' . $cleanPath;
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
