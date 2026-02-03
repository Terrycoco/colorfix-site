<?php
declare(strict_types=1);

namespace App\Services;

use App\Entities\Palette;
use App\Repos\PdoAppliedPaletteRepository;
use App\Repos\PdoSavedPaletteRepository;
use RuntimeException;
use InvalidArgumentException;

class PaletteViewerService
{
    private PdoAppliedPaletteRepository $appliedRepo;
    private PdoSavedPaletteRepository $savedRepo;
    private PhotoRenderingService $renderService;

    public function __construct(
        PdoAppliedPaletteRepository $appliedRepo,
        PdoSavedPaletteRepository $savedRepo,
        PhotoRenderingService $renderService
    ) {
        $this->appliedRepo = $appliedRepo;
        $this->savedRepo = $savedRepo;
        $this->renderService = $renderService;
    }

    public function getApplied(int $paletteId): array
    {
        if ($paletteId <= 0) {
            throw new InvalidArgumentException('palette_id required');
        }

        $palette = $this->appliedRepo->findById($paletteId);
        if (!$palette) {
            throw new RuntimeException('Palette not found');
        }

        $render = $this->renderService->renderAppliedPalette($palette);

        $entries = $palette->entries ?? [];
        $swatches = [];
        foreach ($entries as $entry) {
            $swatches[] = [
                'id' => $entry['color_id'] ?? null,
                'name' => $entry['color_name'] ?? null,
                'code' => $entry['color_code'] ?? null,
                'brand' => $entry['color_brand'] ?? null,
                'brand_name' => $entry['color_brand_name'] ?? ($entry['brand_name'] ?? null),
                'hex6' => $entry['color_hex6'] ?? null,
                'role' => $entry['mask_role'] ?? null,
            ];
        }

        $meta = [
            'source' => 'applied',
            'id' => $palette->id ?? null,
            'title' => $palette->displayTitle ?: ($palette->title ?: 'ColorFix Palette'),
            'notes' => $palette->notes ?: '',
            'photo_url' => $render['render_url'] ?? '',
            'inset_photos' => [],
        ];

        return (new Palette($meta, $swatches))->toArray();
    }

    public function getSaved(string $hash): array
    {
        $hash = trim($hash);
        if ($hash === '') {
            throw new InvalidArgumentException('hash required');
        }

        $full = $this->savedRepo->getFullPaletteByHash($hash);
        if (!$full) {
            throw new RuntimeException('Palette not found');
        }

        $palette = $full['palette'] ?? [];
        $members = $full['members'] ?? [];
        $photos = $full['photos'] ?? [];

        $fullPhoto = null;
        foreach ($photos as $photo) {
            if (($photo['photo_type'] ?? '') === 'full') {
                $fullPhoto = $photo;
                break;
            }
        }
        if (!$fullPhoto && !empty($photos)) {
            $fullPhoto = $photos[0];
        }

        $insets = [];
        foreach ($photos as $photo) {
            if (($photo['photo_type'] ?? '') === 'zoom' && !empty($photo['rel_path'])) {
                $insets[] = $photo['rel_path'];
            }
        }

        $swatches = [];
        foreach ($members as $member) {
            $swatches[] = [
                'id' => $member['color_id'] ?? null,
                'name' => $member['color_name'] ?? null,
                'code' => $member['color_code'] ?? null,
                'brand' => $member['color_brand'] ?? null,
                'brand_name' => $member['color_brand_name'] ?? ($member['brand_name'] ?? null),
                'hex6' => $member['color_hex6'] ?? null,
                'role' => $member['role'] ?? null,
            ];
        }

        $meta = [
            'source' => 'saved',
            'id' => $palette['id'] ?? null,
            'hash' => $palette['palette_hash'] ?? $hash,
            'title' => $palette['nickname'] ?? 'Saved Palette',
            'notes' => $palette['notes'] ?? '',
            'photo_url' => $fullPhoto['rel_path'] ?? '',
            'inset_photos' => $insets,
        ];

        return (new Palette($meta, $swatches))->toArray();
    }
}
