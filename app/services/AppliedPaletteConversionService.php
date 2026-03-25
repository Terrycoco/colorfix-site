<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoAppliedPaletteRepository;
use App\Repos\PdoPhotoRepository;
use App\Repos\PdoSavedPaletteRepository;
use InvalidArgumentException;
use RuntimeException;

final class AppliedPaletteConversionService
{
    public function __construct(
        private PdoAppliedPaletteRepository $appliedRepo,
        private PdoSavedPaletteRepository $savedRepo,
        private PdoPhotoRepository $photoRepo,
        private PhotoRenderingService $renderService,
        private PhotoLibraryService $photoLibrary
    ) {}

    public function convertPalette(int $appliedPaletteId): array
    {
        if ($appliedPaletteId <= 0) {
            throw new InvalidArgumentException('palette_id required');
        }

        $palette = $this->appliedRepo->findById($appliedPaletteId);
        if (!$palette) {
            throw new RuntimeException('Applied palette not found');
        }

        $members = $this->buildMembers($palette->entries ?? []);
        if (!$members) {
            throw new RuntimeException('Applied palette has no usable colors');
        }

        $brand = $this->resolveBrand($palette->entries ?? []);
        $paletteHash = hash('sha256', 'ap-convert:' . $appliedPaletteId . ':' . $brand . ':' . implode(',', array_column($members, 'color_id')));
        $marker = 'converted_from_applied_palette_id:' . $appliedPaletteId;
        $existing = $this->savedRepo->getSavedPaletteByPrivateNotes($marker);
        if (!$existing) {
            $existing = $this->savedRepo->getSavedPaletteByHashAndBrand($paletteHash, $brand);
        }
        if ($existing) {
            $savedPaletteId = (int)$existing['id'];
            $this->savedRepo->updateSavedPalette($savedPaletteId, [
                'palette_hash' => $paletteHash,
                'brand' => $brand,
                'nickname' => $palette->displayTitle ?: ($palette->title ?: ('Applied Palette #' . $appliedPaletteId)),
                'notes' => $palette->notes ?: null,
                'private_notes' => $marker,
                'kicker_id' => $palette->kickerId,
                'palette_type' => 'exterior',
            ]);
            $this->savedRepo->replaceMembers($savedPaletteId, $members);

            $renderInfo = $this->renderService->cacheAppliedPalette($palette);
            $renderRel = trim((string)($renderInfo['render_rel_path'] ?? ''));
            if ($renderRel === '') {
                throw new RuntimeException('Failed to cache applied palette render');
            }
            $beforeRel = $this->findPreparedBaseRelPath($palette->photoId);
            $photoIds = $this->upsertPalettePhotos($savedPaletteId, $renderRel, $beforeRel, $palette->displayTitle ?: ($palette->title ?: null), $palette->altText ?: null);

            return [
                'saved_palette_id' => $savedPaletteId,
                'applied_palette_id' => $appliedPaletteId,
                'status' => 'updated',
                'render_rel_path' => $renderRel,
                'before_rel_path' => $beforeRel,
                'full_photo_id' => $photoIds['full_photo_id'],
                'before_photo_id' => $photoIds['before_photo_id'],
            ];
        }

        $savedPaletteId = $this->savedRepo->createSavedPalette([
            'palette_hash' => $paletteHash,
            'brand' => $brand,
            'nickname' => $palette->displayTitle ?: ($palette->title ?: ('Applied Palette #' . $appliedPaletteId)),
            'notes' => $palette->notes ?: null,
            'private_notes' => $marker,
            'kicker_id' => $palette->kickerId,
            'palette_type' => 'exterior',
        ]);

        $this->savedRepo->addMembers($savedPaletteId, $members);

        $renderInfo = $this->renderService->cacheAppliedPalette($palette);
        $renderRel = trim((string)($renderInfo['render_rel_path'] ?? ''));
        if ($renderRel === '') {
            throw new RuntimeException('Failed to cache applied palette render');
        }

        $beforeRel = $this->findPreparedBaseRelPath($palette->photoId);
        $photoIds = $this->upsertPalettePhotos($savedPaletteId, $renderRel, $beforeRel, $palette->displayTitle ?: ($palette->title ?: null), $palette->altText ?: null);

        return [
            'saved_palette_id' => $savedPaletteId,
            'applied_palette_id' => $appliedPaletteId,
            'status' => 'created',
            'render_rel_path' => $renderRel,
            'before_rel_path' => $beforeRel,
            'full_photo_id' => $photoIds['full_photo_id'],
            'before_photo_id' => $photoIds['before_photo_id'],
        ];
    }

    public function convertMany(?array $paletteIds = null): array
    {
        $ids = $paletteIds !== null ? $this->normalizeIds($paletteIds) : array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $this->appliedRepo->listAllBasic()
        );

        $results = [];
        foreach ($ids as $paletteId) {
            if ($paletteId <= 0) {
                continue;
            }
            try {
                $results[] = $this->convertPalette($paletteId);
            } catch (\Throwable $e) {
                $results[] = [
                    'applied_palette_id' => $paletteId,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'count' => count($results),
            'created' => count(array_filter($results, static fn(array $row): bool => ($row['status'] ?? '') === 'created')),
            'updated' => count(array_filter($results, static fn(array $row): bool => ($row['status'] ?? '') === 'updated')),
            'existing' => count(array_filter($results, static fn(array $row): bool => ($row['status'] ?? '') === 'exists')),
            'errors' => count(array_filter($results, static fn(array $row): bool => ($row['status'] ?? '') === 'error')),
            'items' => $results,
        ];
    }

    private function buildMembers(array $entries): array
    {
        $membersByColor = [];
        $orderByColor = [];
        $order = 0;
        foreach ($entries as $entry) {
            $colorId = (int)($entry['color_id'] ?? 0);
            $role = trim((string)($entry['mask_role'] ?? ''));
            if ($colorId <= 0 || $role === '') {
                continue;
            }
            if (!isset($membersByColor[$colorId])) {
                $membersByColor[$colorId] = [];
                $orderByColor[$colorId] = $order++;
            }
            if (!in_array($role, $membersByColor[$colorId], true)) {
                $membersByColor[$colorId][] = $role;
            }
        }
        $members = [];
        foreach ($membersByColor as $colorId => $roles) {
            $members[] = [
                'color_id' => (int)$colorId,
                'order_index' => $orderByColor[$colorId] ?? 0,
                'role' => implode(', ', $roles),
            ];
        }
        usort($members, static fn(array $a, array $b): int => ($a['order_index'] ?? 0) <=> ($b['order_index'] ?? 0));
        return $members;
    }

    private function resolveBrand(array $entries): string
    {
        foreach ($entries as $entry) {
            $brand = strtolower(trim((string)($entry['color_brand'] ?? '')));
            if ($brand !== '') {
                return $brand;
            }
        }
        return 'de';
    }

    private function findPreparedBaseRelPath(int $photoId): ?string
    {
        if ($photoId <= 0) {
            return null;
        }
        $variants = $this->photoRepo->listVariants($photoId);
        foreach ($variants as $variant) {
            $kind = (string)($variant['kind'] ?? '');
            $role = strtolower(trim((string)($variant['role'] ?? '')));
            if ($kind === 'prepared_base') {
                return (string)($variant['path'] ?? '');
            }
            if ($kind === 'prepared' && ($role === '' || $role === 'base')) {
                return (string)($variant['path'] ?? '');
            }
        }
        return null;
    }

    private function normalizeIds(array $paletteIds): array
    {
        $ids = [];
        foreach ($paletteIds as $paletteId) {
            $id = (int)$paletteId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    private function upsertPalettePhotos(
        int $savedPaletteId,
        string $renderRel,
        ?string $beforeRel,
        ?string $fullTitle,
        ?string $altText
    ): array {
        $photos = $this->savedRepo->getPhotosForPalette($savedPaletteId);
        $fullRow = null;
        $beforeRow = null;
        foreach ($photos as $photo) {
            $photoType = strtolower((string)($photo['photo_type'] ?? 'full'));
            if ($photoType === 'before') {
                $beforeRow = $photo;
                continue;
            }
            if ($fullRow === null) {
                $fullRow = $photo;
            }
        }

        if ($fullRow) {
            $this->savedRepo->updatePhoto((int)$fullRow['id'], $savedPaletteId, [
                'rel_path' => $renderRel,
                'photo_type' => 'full',
                'trigger_mode' => 'any',
                'trigger_color_id' => null,
                'caption' => null,
                'alt_text' => $altText,
                'order_index' => 1,
            ]);
            $fullPhotoId = (int)$fullRow['id'];
        } else {
            $fullPhotoId = $this->savedRepo->addPhoto($savedPaletteId, $renderRel, null, $altText, 1);
        }
        $fullUpdated = $this->savedRepo->getPhotoById($fullPhotoId);
        if ($fullUpdated) {
            $this->photoLibrary->syncSavedPalettePhoto($fullUpdated, [
                'title' => $fullTitle,
                'alt_text' => $altText,
            ]);
        }

        $beforePhotoId = null;
        if ($beforeRel !== null && $beforeRel !== '') {
            if ($beforeRow) {
                $this->savedRepo->updatePhoto((int)$beforeRow['id'], $savedPaletteId, [
                    'rel_path' => $beforeRel,
                    'photo_type' => 'before',
                    'trigger_mode' => 'none',
                    'trigger_color_id' => null,
                    'caption' => 'Before',
                    'alt_text' => $altText,
                    'order_index' => 2,
                ]);
                $beforePhotoId = (int)$beforeRow['id'];
            } else {
                $beforePhotoId = $this->savedRepo->addPhoto($savedPaletteId, $beforeRel, 'Before', $altText, 2);
                $this->savedRepo->updatePhoto($beforePhotoId, $savedPaletteId, [
                    'photo_type' => 'before',
                    'trigger_mode' => 'none',
                    'trigger_color_id' => null,
                    'caption' => 'Before',
                    'alt_text' => $altText,
                    'order_index' => 2,
                ]);
            }
            $beforeUpdated = $this->savedRepo->getPhotoById($beforePhotoId);
            if ($beforeUpdated) {
                $this->photoLibrary->syncSavedPalettePhoto($beforeUpdated, [
                    'title' => 'Before',
                    'alt_text' => $altText,
                ]);
            }
        }

        return [
            'full_photo_id' => $fullPhotoId,
            'before_photo_id' => $beforePhotoId,
        ];
    }
}
