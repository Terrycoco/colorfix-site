<?php
declare(strict_types=1);

namespace App\Services;

use App\Entities\Palette;
use App\Repos\PdoAppliedPaletteRepository;
use App\Repos\PdoSavedPaletteRepository;
use App\Repos\PdoPlaylistInstanceRepository;
use RuntimeException;
use InvalidArgumentException;

class PaletteViewerService
{
    private PdoAppliedPaletteRepository $appliedRepo;
    private PdoSavedPaletteRepository $savedRepo;
    private PhotoRenderingService $renderService;
    private ?PdoPlaylistInstanceRepository $playlistInstanceRepo;

    public function __construct(
        PdoAppliedPaletteRepository $appliedRepo,
        PdoSavedPaletteRepository $savedRepo,
        PhotoRenderingService $renderService,
        ?PdoPlaylistInstanceRepository $playlistInstanceRepo = null
    ) {
        $this->appliedRepo = $appliedRepo;
        $this->savedRepo = $savedRepo;
        $this->renderService = $renderService;
        $this->playlistInstanceRepo = $playlistInstanceRepo;
    }

    public function getApplied(int $paletteId, ?int $playlistInstanceId = null): array
    {
        if ($paletteId <= 0) {
            throw new InvalidArgumentException('palette_id required');
        }

        $palette = $this->appliedRepo->findById($paletteId);
        if (!$palette) {
            throw new RuntimeException('Palette not found');
        }

        $render = $this->renderService->renderAppliedPalette($palette);
        $kickerText = $palette->kickerId
            ? $this->appliedRepo->getKickerText($palette->kickerId)
            : null;
        if ($playlistInstanceId && $this->playlistInstanceRepo) {
            $instance = $this->playlistInstanceRepo->getById($playlistInstanceId);
            if ($instance && $instance->kickerId) {
                $instanceKicker = $this->appliedRepo->getKickerText($instance->kickerId);
                if ($instanceKicker) {
                    $kickerText = $instanceKicker;
                }
            }
        }

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
                'int_only' => isset($entry['color_int_only']) ? (int)$entry['color_int_only'] : 0,
            ];
        }

        $meta = [
            'source' => 'applied',
            'id' => $palette->id ?? null,
            'title' => $palette->displayTitle ?: ($palette->title ?: 'ColorFix Palette'),
            'notes' => $palette->notes ?: '',
            'photo_url' => $render['render_url'] ?? '',
            'photo_alt' => $palette->altText ?: null,
            'inset_photos' => [],
            'kicker' => $kickerText,
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
        $kickerText = !empty($palette['kicker_id'])
            ? $this->savedRepo->getKickerText((int)$palette['kicker_id'])
            : null;

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
                $insets[] = [
                    'url' => $photo['rel_path'],
                    'alt_text' => $photo['alt_text'] ?? null,
                ];
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
                'int_only' => isset($member['color_int_only']) ? (int)$member['color_int_only'] : 0,
            ];
        }

        $meta = [
            'source' => 'saved',
            'id' => $palette['id'] ?? null,
            'hash' => $palette['palette_hash'] ?? $hash,
            'title' => $palette['nickname'] ?? 'Saved Palette',
            'notes' => $palette['notes'] ?? '',
            'photo_url' => $fullPhoto['rel_path'] ?? '',
            'photo_alt' => $fullPhoto['alt_text'] ?? null,
            'inset_photos' => $insets,
            'kicker' => $kickerText,
            'palette_type' => $palette['palette_type'] ?? null,
        ];

        return (new Palette($meta, $swatches))->toArray();
    }
}
