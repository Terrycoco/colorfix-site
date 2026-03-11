<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPhotoLibraryRepository;

class PhotoLibraryService
{
    public function __construct(private PdoPhotoLibraryRepository $repo) {}

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

        $existingId = $this->repo->findIdBySourceAndRel($sourceType, $sourceId, $relPath);
        if (!$existingId) {
            $fallbackType = $sourceType === 'saved_before' ? 'saved_palette_photo' : 'saved_before';
            $existingId = $this->repo->findIdBySourceAndRel($fallbackType, $sourceId, $relPath);
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
