<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPhotoLibraryRepository;

class PhotoLibraryService
{
    public function __construct(private PdoPhotoLibraryRepository $repo) {}

    public function syncSavedPalettePhoto(array $photo, array $overrides = []): int
    {
        $sourceType = 'saved_palette_photo';
        $sourceId = isset($photo['id']) ? (int)$photo['id'] : null;
        $relPath = (string)($photo['rel_path'] ?? '');
        if ($relPath === '' || !$sourceId) {
            return 0;
        }

        $data = [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'rel_path' => $relPath,
            'title' => $overrides['title'] ?? ($photo['caption'] ?? null),
            'tags' => $overrides['tags'] ?? null,
            'alt_text' => $overrides['alt_text'] ?? ($photo['alt_text'] ?? null),
            'show_in_gallery' => array_key_exists('show_in_gallery', $overrides) ? (int)$overrides['show_in_gallery'] : 1,
            'has_palette' => array_key_exists('has_palette', $overrides) ? (int)$overrides['has_palette'] : 1,
        ];

        $existingId = $this->repo->findIdBySourceAndRel($sourceType, $sourceId, $relPath);
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

    public function createStandalone(string $sourceType, string $relPath, array $overrides = []): int
    {
        $sourceType = trim($sourceType);
        $relPath = trim($relPath);
        if ($sourceType === '' || $relPath === '') {
            return 0;
        }
        $data = [
            'source_type' => $sourceType,
            'source_id' => null,
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
}
