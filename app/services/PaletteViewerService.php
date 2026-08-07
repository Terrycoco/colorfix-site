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

    public function getSaved(string $hash, ?int $setId = null, string $paletteViewerKey = 'full_palette'): array
    {
        $hash = trim($hash);
        if ($hash === '') {
            throw new InvalidArgumentException('hash required');
        }

        $full = $this->savedRepo->getFullPaletteByHashAndSet($hash, $setId);
        return $this->buildSavedPalettePayload($full, $hash, $paletteViewerKey);
    }

    public function getSavedById(int $id, ?int $setId = null, string $paletteViewerKey = 'full_palette'): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('saved palette id required');
        }

        $full = $this->savedRepo->getFullPalette($id, $setId);
        return $this->buildSavedPalettePayload($full, '', $paletteViewerKey);
    }

    private function buildSavedPalettePayload(?array $full, string $hash, string $paletteViewerKey): array
    {
        $paletteViewerKey = $this->normalizePaletteViewerKey($paletteViewerKey);
        if ($paletteViewerKey === 'none') {
            throw new RuntimeException('Palette viewer unavailable');
        }

        if ($paletteViewerKey === 'concept') {
            return $this->buildSavedConceptPalettePayload($full, $hash);
        }

        return $this->buildSavedFullPalettePayload($full, $hash, $paletteViewerKey);
    }

    private function buildSavedConceptPalettePayload(?array $full, string $hash): array
    {
        return $this->buildSavedFullPalettePayload($full, $hash, 'concept', false);
    }

    private function buildSavedFullPalettePayload(
        ?array $full,
        string $hash,
        string $paletteViewerKey,
        bool $includeSwatches = true
    ): array
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
        $setId = isset($fullPhoto['saved_palette_set_id'])
            ? (int)$fullPhoto['saved_palette_set_id']
            : (isset($sets[0]['id']) ? (int)$sets[0]['id'] : 0);

        $viewerContent = null;
        $paletteId = isset($palette['id']) ? (int)$palette['id'] : 0;
        if ($paletteId > 0 && $setId > 0) {
            $viewerContent = $this->savedRepo->getViewerContentForSet($paletteId, $setId, $paletteViewerKey);
            if (!$viewerContent && $paletteViewerKey !== 'full_palette') {
                $viewerContent = $this->savedRepo->getViewerContentForSet($paletteId, $setId, 'full_palette');
            }
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
        if ($includeSwatches) {
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
        }

        $publicTitle = $this->firstNonEmpty([
            $viewerContent['title'] ?? null,
            $palette['display_title'] ?? null,
            $palette['nickname'] ?? null,
            'ColorFix Palette',
        ]);
        $kickerText = $viewerContent && !empty($viewerContent['kicker_text'])
            ? (string)$viewerContent['kicker_text']
            : $kickerText;

        $meta = [
            'source' => 'saved',
            'palette_viewer_key' => $paletteViewerKey,
            'template_key' => $paletteViewerKey,
            'id' => $palette['id'] ?? null,
            'hash' => $palette['palette_hash'] ?? $hash,
            'title' => $publicTitle,
            'nickname' => $palette['nickname'] ?? null,
            'display_title' => $publicTitle,
            'intro' => $viewerContent['intro'] ?? '',
            'notes' => $viewerContent['notes'] ?? ($palette['notes'] ?? ''),
            'cta_label' => $viewerContent['cta_label'] ?? '',
            'playlist_url' => $viewerContent['playlist_url'] ?? '',
            'photo_url' => $fullPhoto['rel_path'] ?? '',
            'photo_alt' => $fullPhoto['alt_text'] ?? null,
            'inset_photos' => $insets,
            'kicker_text' => $kickerText,
            'palette_type' => $palette['palette_type'] ?? null,
            'set_id' => $setId > 0 ? $setId : null,
            'available_sets' => array_map(fn(array $set): array => [
                'id' => isset($set['id']) ? (int)$set['id'] : null,
                'slug' => $set['slug'] ?? null,
                'title' => $publicTitle,
                'is_default' => isset($set['is_default']) ? (int)$set['is_default'] : 0,
            ], $sets),
        ];

        return (new Palette($meta, $swatches))->toArray();
    }

    private function normalizePaletteViewerKey(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['full_palette', 'concept', 'client', 'painter', 'none'], true) ? $value : 'full_palette';
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
