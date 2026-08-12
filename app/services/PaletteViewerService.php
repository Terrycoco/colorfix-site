<?php
declare(strict_types=1);

namespace App\Services;

use App\Entities\Palette;
use App\Entities\PaletteViewer;
use App\Repos\PdoSavedPaletteRepository;
use App\Repos\PdoPaletteViewerRepository;
use App\Repos\PdoPaletteViewerPhotoRepository;
use App\Repos\PdoPlaylistInstanceRepository;
use App\Repos\PdoProjectColorPlanRepository;
use RuntimeException;
use InvalidArgumentException;

class PaletteViewerService
{
    private PdoSavedPaletteRepository $savedRepo;
    private PhotoRenderingService $renderService;
    private ?PdoPlaylistInstanceRepository $playlistInstanceRepo;
    private ?PdoProjectColorPlanRepository $projectColorPlanRepo;
    private ?PdoPaletteViewerRepository $paletteViewerRepo;
    private ?PdoPaletteViewerPhotoRepository $paletteViewerPhotoRepo;

    public function __construct(
        PdoSavedPaletteRepository $savedRepo,
        PhotoRenderingService $renderService,
        ?PdoPlaylistInstanceRepository $playlistInstanceRepo = null,
        ?PdoProjectColorPlanRepository $projectColorPlanRepo = null,
        ?PdoPaletteViewerRepository $paletteViewerRepo = null,
        ?PdoPaletteViewerPhotoRepository $paletteViewerPhotoRepo = null
    ) {
        $this->savedRepo = $savedRepo;
        $this->renderService = $renderService;
        $this->playlistInstanceRepo = $playlistInstanceRepo;
        $this->projectColorPlanRepo = $projectColorPlanRepo;
        $this->paletteViewerRepo = $paletteViewerRepo;
        $this->paletteViewerPhotoRepo = $paletteViewerPhotoRepo;
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

    public function getPaletteViewer(int $paletteViewerId): PaletteViewer
    {
        if ($paletteViewerId <= 0) {
            throw new InvalidArgumentException('palette viewer id required');
        }
        if (!$this->paletteViewerRepo instanceof PdoPaletteViewerRepository) {
            throw new RuntimeException('Palette Viewer repository unavailable');
        }

        $viewer = $this->paletteViewerRepo->findById($paletteViewerId);
        if (!$viewer) {
            throw new RuntimeException("Palette Viewer {$paletteViewerId} not found");
        }

        return $viewer;
    }

    public function getCanonicalById(int $paletteViewerId): array
    {
        $viewer = $this->getPaletteViewer($paletteViewerId);
        if (!$viewer->isActive) {
            throw new RuntimeException("Palette Viewer {$paletteViewerId} is inactive");
        }
        if (!$this->paletteViewerPhotoRepo instanceof PdoPaletteViewerPhotoRepository) {
            throw new RuntimeException('Palette Viewer Photo repository unavailable');
        }

        $palette = $this->savedRepo->getSavedPaletteById($viewer->savedPaletteId);
        if (!$palette) {
            throw new RuntimeException("Saved Palette {$viewer->savedPaletteId} not found");
        }

        $members = $this->savedRepo->getMembersForPalette($viewer->savedPaletteId);
        $photos = $this->paletteViewerPhotoRepo->findByViewerId($paletteViewerId);

        return $this->buildCanonicalSavedPalettePayload($viewer, $palette, $members, $photos);
    }

    public function getProjectColorPlan(int $colorPlanId, string $paletteViewerKey): array
    {
        if ($colorPlanId <= 0) {
            throw new InvalidArgumentException('project color plan id required');
        }
        if (!$this->projectColorPlanRepo instanceof PdoProjectColorPlanRepository) {
            throw new RuntimeException('Project Color Plan viewer repository unavailable');
        }

        $paletteViewerKey = $this->normalizePaletteViewerKey($paletteViewerKey);
        if ($paletteViewerKey === 'none') {
            throw new RuntimeException('Palette viewer unavailable');
        }

        $plan = $this->projectColorPlanRepo->findPlan($colorPlanId);
        if (!$plan) {
            throw new RuntimeException('Project Color Plan not found');
        }

        $viewerLookupKey = $paletteViewerKey === 'full_palette' ? 'client' : $paletteViewerKey;
        $viewers = $this->projectColorPlanRepo->viewersForPlan($colorPlanId);
        $viewer = is_array($viewers[$viewerLookupKey] ?? null) ? $viewers[$viewerLookupKey] : [];
        $viewerRow = is_array($viewer['row'] ?? null) ? $viewer['row'] : [];
        $photos = is_array($viewer['photos'] ?? null) ? $viewer['photos'] : [];
        $members = $this->projectColorPlanRepo->membersForPlan($colorPlanId);

        $fullPhoto = null;
        $insets = [];
        foreach ($photos as $photo) {
            $type = strtoupper(trim((string)($photo['photo_type'] ?? 'FULL')));
            $url = trim((string)($photo['rel_path'] ?? ''));
            if ($url === '') {
                continue;
            }

            if ($fullPhoto === null && $type === 'FULL') {
                $fullPhoto = $photo;
                continue;
            }

            if (in_array($type, ['BEFORE', 'INSET'], true)) {
                $insets[] = [
                    'url' => $url,
                    'alt_text' => $photo['photo_title'] ?? null,
                    'caption' => $type === 'BEFORE' ? 'Before' : null,
                ];
            }
        }
        if ($fullPhoto === null) {
            foreach ($photos as $photo) {
                if (trim((string)($photo['rel_path'] ?? '')) !== '') {
                    $fullPhoto = $photo;
                    break;
                }
            }
        }

        $schemeTitle = $this->firstNonEmpty([
            $viewerRow['scheme_title'] ?? null,
            $plan['scheme_title'] ?? null,
            $plan['nickname'] ?? null,
            $plan['area_name'] ?? null,
            'Color Plan',
        ]);

        if ($paletteViewerKey === 'concept') {
            $displayTitle = $this->firstNonEmpty([
                $viewerRow['concept_title'] ?? null,
                $plan['scheme_title'] ?? null,
                $plan['nickname'] ?? null,
                $plan['area_name'] ?? null,
                'Design Concept',
            ]);
            $intro = (string)($viewerRow['challenge'] ?? '');
            $notes = (string)($viewerRow['design_direction'] ?? '');
        } elseif ($paletteViewerKey === 'painter') {
            $displayTitle = $schemeTitle;
            $intro = '';
            $notes = (string)($viewerRow['overall_painter_note'] ?? '');
        } else {
            $displayTitle = $schemeTitle;
            $intro = '';
            $notes = (string)($viewerRow['final_design_description'] ?? '');
        }

        $swatches = [];
        if ($paletteViewerKey !== 'concept') {
            foreach ($members as $member) {
                $swatches[] = [
                    'id' => isset($member['color_id']) ? (int)$member['color_id'] : null,
                    'name' => $member['color_name'] ?? null,
                    'code' => $member['color_code'] ?? null,
                    'brand' => $member['color_brand'] ?? null,
                    'brand_name' => $member['color_brand_name'] ?? null,
                    'hex6' => $member['hex6'] ?? null,
                    'role' => $member['role_name'] ?? null,
                    'role_name' => $member['role_name'] ?? null,
                    'sheen' => $member['sheen'] ?? null,
                    'note' => $member['note'] ?? null,
                ];
            }
        }

        $meta = [
            'source' => 'project_color_plan',
            'palette_viewer_key' => $paletteViewerKey,
            'template_key' => $paletteViewerKey,
            'id' => (int)$plan['id'],
            'project_id' => (int)($plan['project_id'] ?? 0),
            'color_plan_id' => (int)$plan['id'],
            'hash' => null,
            'title' => $displayTitle,
            'nickname' => $plan['nickname'] ?? null,
            'display_title' => $displayTitle,
            'intro' => $intro,
            'notes' => $notes,
            'cta_label' => '',
            'playlist_url' => '',
            'photo_url' => $fullPhoto['rel_path'] ?? '',
            'photo_alt' => $fullPhoto['photo_title'] ?? null,
            'inset_photos' => $insets,
            'kicker_text' => $plan['area_name'] ?? '',
            'palette_type' => $plan['palette_type'] ?? null,
            'set_id' => null,
            'available_sets' => [],
            'scheme_title' => $schemeTitle,
            'area_name' => $plan['area_name'] ?? null,
            'revision_number' => isset($plan['revision_number']) ? (int)$plan['revision_number'] : 1,
            'issued_at' => $plan['issued_at'] ?? null,
        ];

        return (new Palette($meta, $swatches))->toArray();
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
    ): array {
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

    private function buildCanonicalSavedPalettePayload(
        PaletteViewer $viewer,
        array $palette,
        array $members,
        array $photos
    ): array {
        $fullPhoto = null;
        $insets = [];
        foreach ($photos as $photo) {
            $type = strtolower(trim($photo->photoType));
            $url = trim((string)($photo->relPath ?? ''));
            if ($url === '') {
                continue;
            }

            if ($fullPhoto === null && $type === 'full') {
                $fullPhoto = $photo;
                continue;
            }

            if ($type === 'before') {
                $insets[] = [
                    'url' => $url,
                    'alt_text' => $photo->altText,
                    'caption' => 'Before',
                ];
                continue;
            }

            if (in_array($type, ['zoom', 'inset'], true)) {
                $insets[] = [
                    'url' => $url,
                    'alt_text' => $photo->altText,
                    'caption' => $photo->caption,
                ];
            }
        }
        if ($fullPhoto === null) {
            foreach ($photos as $photo) {
                if (trim((string)($photo->relPath ?? '')) !== '') {
                    $fullPhoto = $photo;
                    break;
                }
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
            $viewer->title,
            $palette['display_title'] ?? null,
            $palette['nickname'] ?? null,
            'ColorFix Palette',
        ]);
        $format = strtolower(trim($viewer->format));
        $paletteViewerKey = $format === 'public' ? 'full_palette' : $format;

        $meta = [
            'source' => 'saved',
            'palette_viewer_id' => $viewer->paletteViewerId,
            'palette_viewer_key' => $paletteViewerKey,
            'template_key' => $viewer->templateKey ?? $paletteViewerKey,
            'format' => $format,
            'saved_palette_id' => $viewer->savedPaletteId,
            'id' => $palette['id'] ?? null,
            'hash' => $palette['palette_hash'] ?? '',
            'title' => $publicTitle,
            'nickname' => $palette['nickname'] ?? null,
            'display_title' => $publicTitle,
            'intro' => $viewer->intro ?? '',
            'notes' => $viewer->notes ?? ($palette['notes'] ?? ''),
            'cta_label' => $viewer->ctaLabel ?? '',
            'playlist_url' => '',
            'photo_url' => $fullPhoto?->relPath ?? '',
            'photo_alt' => $fullPhoto?->altText,
            'inset_photos' => $insets,
            'kicker_text' => $viewer->kickerText ?? '',
            'palette_type' => $palette['palette_type'] ?? null,
            'set_id' => null,
            'available_sets' => [],
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
