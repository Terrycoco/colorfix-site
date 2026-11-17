<?php
declare(strict_types=1);

namespace App\Services;

use App\Entities\Palette;
use App\Entities\PaletteViewer;
use App\Repos\PdoSavedPaletteRepository;
use App\Repos\PdoPaletteViewerRepository;
use App\Repos\PdoPaletteViewerPhotoRepository;
use App\Repos\PdoPlaylistInstanceRepository;
use App\PROJECTS\Repos\PdoProjectColorPlanRepository;
use RuntimeException;
use InvalidArgumentException;

class PaletteViewerService
{
    private PdoSavedPaletteRepository $savedRepo;
    private ?PdoPlaylistInstanceRepository $playlistInstanceRepo;
    private ?PdoProjectColorPlanRepository $projectColorPlanRepo;
    private ?PdoPaletteViewerRepository $paletteViewerRepo;
    private ?PdoPaletteViewerPhotoRepository $paletteViewerPhotoRepo;

    public function __construct(
        PdoSavedPaletteRepository $savedRepo,
        ?PdoPlaylistInstanceRepository $playlistInstanceRepo = null,
        ?PdoProjectColorPlanRepository $projectColorPlanRepo = null,
        ?PdoPaletteViewerRepository $paletteViewerRepo = null,
        ?PdoPaletteViewerPhotoRepository $paletteViewerPhotoRepo = null
    ) {
        $this->savedRepo = $savedRepo;
        $this->playlistInstanceRepo = $playlistInstanceRepo;
        $this->projectColorPlanRepo = $projectColorPlanRepo;
        $this->paletteViewerRepo = $paletteViewerRepo;
        $this->paletteViewerPhotoRepo = $paletteViewerPhotoRepo;
    }

    public function getSaved(string $hash, ?int $setId = null, string $paletteViewerKey = 'full_palette'): array
    {
        $hash = trim($hash);
        if ($hash === '') {
            throw new InvalidArgumentException('hash required');
        }

        $full = $this->savedRepo->getFullPaletteByHash($hash);
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
                    'photo_library_id' => $photo['photo_library_id'] ?? null,
                    'display_image_url' => \App\PHOTOS\Services\PhotoDeliveryService::url((int)($photo['photo_library_id'] ?? 0), $url, 800),
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
            'photo_library_id' => $fullPhoto['photo_library_id'] ?? null,
            'display_image_url' => \App\PHOTOS\Services\PhotoDeliveryService::url((int)($fullPhoto['photo_library_id'] ?? 0), (string)($fullPhoto['rel_path'] ?? '')),
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
        if ($full && $this->paletteViewerRepo) {
            $format = $paletteViewerKey === 'full_palette' ? 'public' : $paletteViewerKey;
            $inactiveViewerId = null;
            foreach ($this->paletteViewerRepo->findBySavedPaletteId((int)$full['palette']['id']) as $viewer) {
                if ($viewer->format !== $format) {
                    continue;
                }
                if ($viewer->isActive) {
                    return $this->getCanonicalById($viewer->paletteViewerId);
                }
                $inactiveViewerId = $viewer->paletteViewerId;
            }
            // A fallback must not bypass an explicitly disabled current viewer.
            if ($inactiveViewerId !== null) {
                throw new RuntimeException("Palette Viewer {$inactiveViewerId} is inactive");
            }
        }
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
        $viewerContent = null;
        $insets = [];
        foreach ($photos as $photo) {
            if (($photo['photo_type'] ?? '') === 'before' && !empty($photo['rel_path'])) {
                $insets[] = [
                    'url' => $photo['rel_path'],
                    'photo_library_id' => $photo['photo_library_id'] ?? null,
                    'display_image_url' => \App\PHOTOS\Services\PhotoDeliveryService::url((int)($photo['photo_library_id'] ?? 0), $photo['rel_path'], 800),
                    'alt_text' => $photo['alt_text'] ?? null,
                    'caption' => 'Before',
                ];
            }
        }
        foreach ($photos as $photo) {
            if (($photo['photo_type'] ?? '') === 'zoom' && !empty($photo['rel_path'])) {
                $insets[] = [
                    'url' => $photo['rel_path'],
                    'photo_library_id' => $photo['photo_library_id'] ?? null,
                    'display_image_url' => \App\PHOTOS\Services\PhotoDeliveryService::url((int)($photo['photo_library_id'] ?? 0), $photo['rel_path'], 800),
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
            'photo_library_id' => $fullPhoto['photo_library_id'] ?? null,
            'display_image_url' => \App\PHOTOS\Services\PhotoDeliveryService::url((int)($fullPhoto['photo_library_id'] ?? 0), (string)($fullPhoto['rel_path'] ?? '')),
            'photo_alt' => $fullPhoto['alt_text'] ?? null,
            'inset_photos' => $insets,
            'kicker_text' => $kickerText,
            'palette_type' => $palette['palette_type'] ?? null,
            'set_id' => null,
            'available_sets' => [],
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
                    'photo_library_id' => $photo->photoLibraryId,
                    'display_image_url' => \App\PHOTOS\Services\PhotoDeliveryService::url((int)$photo->photoLibraryId, $url, 800),
                    'alt_text' => $photo->altText,
                    'caption' => 'Before',
                ];
                continue;
            }

            if (in_array($type, ['zoom', 'inset'], true)) {
                $insets[] = [
                    'url' => $url,
                    'photo_library_id' => $photo->photoLibraryId,
                    'display_image_url' => \App\PHOTOS\Services\PhotoDeliveryService::url((int)$photo->photoLibraryId, $url, 800),
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
            'photo_library_id' => $fullPhoto?->photoLibraryId,
            'display_image_url' => \App\PHOTOS\Services\PhotoDeliveryService::url((int)$fullPhoto?->photoLibraryId, (string)$fullPhoto?->relPath),
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
