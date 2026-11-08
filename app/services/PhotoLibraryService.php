<?php
declare(strict_types=1);

namespace App\Services;

use App\PHOTOS\Repos\PdoPhotoLibraryRepository;

class PhotoLibraryService
{
    public function __construct(private PdoPhotoLibraryRepository $repo) {}

    public function cleanupDetachedPalettePhotos(array $photos): array
    {
        return (new \App\PHOTOS\Services\PhotoLifecycleService($this->repo->connection()))->cleanupDetachedPalettePhotos($photos);
    }

    private function guessSourceTypeForRelPath(string $relPath): string
    {
        $path = strtolower($relPath);
        return match (true) {
            str_contains($path, '/photos/articles/') => 'article',
            str_contains($path, '/photos/art/') => 'art',
            str_contains($path, '/photos/pins/') => 'pin',
            str_contains($path, '/photos/progressions/') => 'progression',
            str_contains($path, '/photos/uploads/saved-palettes/') => 'saved_palette_photo',
            str_contains($path, '/photos/clients/') => 'client',
            default => 'extra_photo',
        };
    }

    private function guessTitleForRelPath(string $relPath): string
    {
        $name = pathinfo($relPath, PATHINFO_FILENAME);
        $name = preg_replace('/[_-]+/', ' ', (string)$name) ?? '';
        return trim($name);
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
            'photo_permission_status' => array_key_exists('photo_permission_status', $overrides) ? $overrides['photo_permission_status'] : null,
            'rel_path' => $relPath,
            'title' => $overrides['title'] ?? null,
            'tags' => $overrides['tags'] ?? null,
            'alt_text' => $overrides['alt_text'] ?? null,
            'show_in_gallery' => array_key_exists('show_in_gallery', $overrides) ? (int)$overrides['show_in_gallery'] : 0,
            'has_palette' => array_key_exists('has_palette', $overrides) ? (int)$overrides['has_palette'] : 0,
        ];
        return $this->repo->insert($data);
    }

    public function retireMissingRelPath(string $relPath, array $overrides = []): int
    {
        $relPath = trim($relPath);
        if ($relPath === '') {
            return 0;
        }

        $existingId = $this->repo->findIdByRelPath($relPath);
        if ($existingId) {
            $this->repo->update($existingId, ['is_inactive' => 1]);
            return $existingId;
        }

        $sourceType = trim((string)($overrides['source_type'] ?? ''));
        if ($sourceType === '') {
            $sourceType = $this->guessSourceTypeForRelPath($relPath);
        }

        $title = array_key_exists('title', $overrides)
            ? (string)$overrides['title']
            : $this->guessTitleForRelPath($relPath);

        $id = $this->createStandalone($sourceType, $relPath, [
            'title' => $title !== '' ? $title : null,
            'tags' => $overrides['tags'] ?? null,
            'alt_text' => $overrides['alt_text'] ?? null,
            'show_in_gallery' => 0,
            'has_palette' => 0,
        ]);

        if ($id > 0) {
            $this->repo->update($id, ['is_inactive' => 1]);
        }

        return $id;
    }

    public function dedupeSavedPaletteRows(bool $apply = false): array
    {
        return (new \App\PHOTOS\Services\PhotoDuplicateService($this->repo->connection()))->dedupeSavedPaletteRows($apply);
    }

    public function dedupeAllRelPathRows(bool $apply = false): array
    {
        return (new \App\PHOTOS\Services\PhotoDuplicateService($this->repo->connection()))->dedupeAllRelPathRows($apply);
    }

    public function reconcileFilesystemRows(bool $apply = false, ?string $onlyRelPath = null): array
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
        $onlyRelPath = $onlyRelPath !== null ? trim($onlyRelPath) : null;
        if ($onlyRelPath === '') {
            $onlyRelPath = null;
        }

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
            // Discover current uploads only. Never resurrect retired masks, bases, variants or rendered AP files.
            if (!preg_match('~^/photos/(?:library|articles|art|pins|progressions|clients|uploads/saved-palettes|replacements)/~', $relPath)) { continue; }
            if ($onlyRelPath !== null && $relPath !== $onlyRelPath) {
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
                    'has_palette' => 0,
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
