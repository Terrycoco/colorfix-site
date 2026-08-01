<?php
declare(strict_types=1);

namespace App\Services;

use App\Entities\Palette;
use App\Repos\PdoSavedPaletteRepository;
use App\Repos\PdoPlaylistInstanceRepository;
use RuntimeException;
use InvalidArgumentException;

class PaletteViewerService
{
    private PdoSavedPaletteRepository $savedRepo;
    private PhotoRenderingService $renderService;
    private ?PdoPlaylistInstanceRepository $playlistInstanceRepo;

    public function __construct(
        PdoSavedPaletteRepository $savedRepo,
        PhotoRenderingService $renderService,
        ?PdoPlaylistInstanceRepository $playlistInstanceRepo = null
    ) {
        $this->savedRepo = $savedRepo;
        $this->renderService = $renderService;
        $this->playlistInstanceRepo = $playlistInstanceRepo;
    }

    public function getApplied(int $paletteId, ?int $playlistInstanceId = null): array
    {
        throw new RuntimeException('Applied palettes have been removed');
    }

    public function getSaved(string $hash, ?int $setId = null): array
    {
        $hash = trim($hash);
        if ($hash === '') {
            throw new InvalidArgumentException('hash required');
        }

        $full = $this->savedRepo->getFullPaletteByHashAndSet($hash, $setId);
        return $this->buildSavedPalettePayload($full, $hash);
    }

    public function getSavedById(int $id, ?int $setId = null): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('saved palette id required');
        }

        $full = $this->savedRepo->getFullPalette($id, $setId);
        return $this->buildSavedPalettePayload($full, '');
    }

    private function buildSavedPalettePayload(?array $full, string $hash): array
    {
        if (!$full) {
            throw new RuntimeException('Palette not found');
        }

        $palette = $full['palette'] ?? [];
        $members = $full['members'] ?? [];
        $photos = $full['photos'] ?? [];
        $sets = $full['sets'] ?? [];
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
            if (($photo['photo_type'] ?? '') === 'before' && !empty($photo['rel_path'])) {
                $insets[] = [
                    'url' => $photo['rel_path'],
                    'alt_text' => $photo['alt_text'] ?? null,
                    'caption' => 'Before',
                ];
            }
        }
        foreach ($photos as $photo) {
            if (($photo['photo_type'] ?? '') === 'zoom' && !empty($photo['rel_path'])) {
                $insets[] = [
                    'url' => $photo['rel_path'],
                    'alt_text' => $photo['alt_text'] ?? null,
                    'caption' => $photo['caption'] ?? null,
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

        $publicTitle = $this->firstNonEmpty([
            $palette['display_title'] ?? null,
            $palette['nickname'] ?? null,
            'ColorFix Palette',
        ]);

        $meta = [
            'source' => 'saved',
            'id' => $palette['id'] ?? null,
            'hash' => $palette['palette_hash'] ?? $hash,
            'title' => $publicTitle,
            'nickname' => $palette['nickname'] ?? null,
            'display_title' => $palette['display_title'] ?? null,
            'notes' => $palette['notes'] ?? '',
            'photo_url' => $fullPhoto['rel_path'] ?? '',
            'photo_alt' => $fullPhoto['alt_text'] ?? null,
            'inset_photos' => $insets,
            'kicker' => $kickerText,
            'palette_type' => $palette['palette_type'] ?? null,
            'set_id' => $fullPhoto['saved_palette_set_id'] ?? ($sets[0]['id'] ?? null),
            'available_sets' => array_map(fn(array $set): array => [
                'id' => isset($set['id']) ? (int)$set['id'] : null,
                'slug' => $set['slug'] ?? null,
                'title' => $publicTitle,
                'is_default' => isset($set['is_default']) ? (int)$set['is_default'] : 0,
            ], $sets),
        ];

        return (new Palette($meta, $swatches))->toArray();
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string)($value ?? ''));
            if ($text !== '') {
                return $text;
            }
        }
        return 'ColorFix Palette';
    }
}
