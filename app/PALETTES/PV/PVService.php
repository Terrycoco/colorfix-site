<?php
declare(strict_types=1);

namespace App\PALETTES\PV;

use App\PALETTES\Repos\PdoPVRepository;
use App\PALETTES\Repos\PdoSavedPaletteRepository;
use App\PROJECTS\Repos\PdoProjectPaletteRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class PVService
{
    private PdoPVRepository $repo;
    private PdoSavedPaletteRepository $savedPaletteRepo;
    private PdoProjectPaletteRepository $projectPaletteRepo;

    public function __construct(PDO $pdo)
    {
        $this->repo = new PdoPVRepository($pdo);
        $this->savedPaletteRepo = new PdoSavedPaletteRepository($pdo);
        $this->projectPaletteRepo = new PdoProjectPaletteRepository($pdo);
    }

    public function getPV(int $pvId): array
    {
        if ($pvId <= 0) {
            throw new InvalidArgumentException(
                'palette viewer id required'
            );
        }

        $record = $this->repo->findById($pvId);

        if (!$record) {
            throw new RuntimeException(
                "Palette Viewer {$pvId} not found"
            );
        }

        if (!(bool)($record['is_active'] ?? false)) {
            throw new RuntimeException(
                "Palette Viewer {$pvId} is inactive"
            );
        }

        $format = strtolower(
            trim((string)($record['format'] ?? 'public'))
        );

        $projectId = (int)($record['project_id'] ?? 0);

        if ($format === 'painter' && $projectId > 0) {
            return $this->getProjectPainterPV(
                $pvId,
                $projectId,
                $record
            );
        }

        $savedPaletteId = (int)($record['saved_palette_id'] ?? 0);

        if ($savedPaletteId <= 0) {
            throw new RuntimeException(
                "Palette Viewer {$pvId} has no Saved Palette"
            );
        }

        $savedPalette = $this->savedPaletteRepo->getFullPalette(
            $savedPaletteId
        );

        if ($savedPalette === null) {
            throw new RuntimeException(
                "Saved Palette {$savedPaletteId} not found"
            );
        }

        $palette = $savedPalette['palette'] ?? [];
        $members = $savedPalette['members'] ?? [];
        $photos = $record['photos'] ?? [];

        $projectPalette =
            $this->projectPaletteRepo
                ->findBySavedPaletteId(
                    $savedPaletteId
                );

        $areaLabel = trim(
            (string)(
                $projectPalette['area_label']
                ?? ''
            )
        );

        $fullPhoto = null;
        $insets = [];
        $photoLibraryIds = [];

        foreach ($photos as $photo) {
            $photoLibraryId = (int)($photo['photo_library_id'] ?? 0);

            if (
                $photoLibraryId > 0
                && !in_array($photoLibraryId, $photoLibraryIds, true)
            ) {
                $photoLibraryIds[] = $photoLibraryId;
            }

            $type = strtolower(
                trim((string)($photo['photo_type'] ?? ''))
            );

            $url = trim(
                (string)($photo['url'] ?? '')
            );

            if ($url === '') {
                continue;
            }

            if ($fullPhoto === null && $type === 'full') {
                $fullPhoto = $photo;
                continue;
            }

            if ($type === 'before') {
                $insets[] = [
                    'photo_library_id' => $photoLibraryId > 0
                        ? $photoLibraryId
                        : null,
                    'url' => $url,
                    'alt_text' => $photo['alt_text'] ?? null,
                    'caption' => 'Before',
                ];

                continue;
            }

            if (in_array($type, ['zoom', 'inset'], true)) {
                $insets[] = [
                    'photo_library_id' => $photoLibraryId > 0
                        ? $photoLibraryId
                        : null,
                    'url' => $url,
                    'alt_text' => $photo['alt_text'] ?? null,
                    'caption' => $photo['caption'] ?? null,
                ];
            }
        }

        if ($fullPhoto === null) {
            foreach ($photos as $photo) {
                if (trim((string)($photo['url'] ?? '')) !== '') {
                    $fullPhoto = $photo;
                    break;
                }
            }
        }

        $title = '';

        if (
            $format === 'painter'
            && $areaLabel !== ''
        ) {
            $title = $areaLabel;
        }

        if ($title === '') {
            $title = trim(
                (string)($record['viewer_title'] ?? '')
            );
        }

        if ($title === '') {
            $title = trim(
                (string)($palette['display_title'] ?? '')
            );
        }

        if ($title === '') {
            $title = trim(
                (string)($palette['nickname'] ?? '')
            );
        }

        if ($title === '') {
            $title = 'ColorFix Palette';
        }

        /*
         * Legacy PV format/template fields are preserved for now
         * so this move does not change rendering behavior.
         *
         * Experience ownership will be cleaned up separately;
         * REX is authoritative for experience.
         */
        $paletteViewerKey = $format === 'public'
            ? 'full_palette'
            : $format;

        $templateKey = trim(
            (string)($record['template_key'] ?? '')
        );

        if ($templateKey === '') {
            $templateKey = $paletteViewerKey;
        }

        $swatches = [];

        foreach ($members as $member) {
            $swatches[] = [
                'id' => isset($member['color_id'])
                    ? (int)$member['color_id']
                    : null,

                'name' => $member['color_name'] ?? null,
                'code' => $member['color_code'] ?? null,
                'brand' => $member['color_brand'] ?? null,
                'brand_name' => $member['color_brand_name'] ?? null,
                'hex6' => $member['color_hex6'] ?? null,
                'role' => $member['role'] ?? null,
                'sheen' => $member['sheen'] ?? null,
                'note' => $member['note'] ?? null,

                'int_only' => isset($member['color_int_only'])
                    ? (int)$member['color_int_only']
                    : 0,
            ];
        }

        $mainPhotoLibraryId = (int)($fullPhoto['photo_library_id'] ?? 0);

        $meta = [
            'source' => 'saved',
            'palette_viewer_id' => $pvId,
            'palette_viewer_key' => $paletteViewerKey,
            'template_key' => $templateKey,
            'format' => $format,

            'saved_palette_id' => $savedPaletteId,
            'id' => $savedPaletteId,
            'hash' => (string)($palette['palette_hash'] ?? ''),

            'title' => $title,
            'nickname' => $palette['nickname'] ?? null,
            'display_title' => $title,

            'intro' => $record['intro'] ?? '',

            'notes' =>
                $format === 'painter'
                    ? ''
                    : (
                        $record['viewer_notes']
                        ?? $palette['notes']
                        ?? ''
                    ),

            'area_label' =>
                $areaLabel !== ''
                    ? $areaLabel
                    : null,

            'project_id' =>
                isset($projectPalette['project_id'])
                    ? (int)$projectPalette['project_id']
                    : null,

            'cta_label' => $record['cta_label'] ?? '',
            'playlist_url' => '',

            'photo_library_id' => $mainPhotoLibraryId > 0
                ? $mainPhotoLibraryId
                : null,
            'photo_library_ids' => $photoLibraryIds,
            'photo_url' => $fullPhoto['url'] ?? '',
            'photo_alt' => $fullPhoto['alt_text'] ?? null,
            'inset_photos' => $insets,

            'kicker_text' => $record['kicker_text'] ?? '',
            'palette_type' => $palette['palette_type'] ?? null,

            'set_id' => null,
            'available_sets' => [],
        ];

        $pv = new PV(
            pvId: $pvId,
            savedPaletteId: $savedPaletteId,
            isActive: true,
            meta: $meta,
            swatches: $swatches
        );

        return $pv->toArray();
    }

    /**
     * Project-scoped Painter PV: one viewer, selected project palettes.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function getProjectPainterPV(
        int $pvId,
        int $projectId,
        array $record
    ): array {
        $selected = $this->repo->listPainterProjectPalettes($pvId);

        if ($selected === []) {
            throw new RuntimeException(
                "Painter Viewer {$pvId} has no selected project palettes"
            );
        }

        $project = $this->repo->findProjectSummary($projectId) ?? [];
        $savedPaletteIds = array_values(array_unique(array_map(
            static fn(array $row): int => (int)($row['saved_palette_id'] ?? 0),
            $selected
        )));

        $photoRows = $this->repo->loadProjectPlaylistPhotos(
            $projectId,
            $savedPaletteIds
        );

        $photosByPalette = [];
        foreach ($photoRows as $photo) {
            $savedPaletteId = (int)($photo['saved_palette_id'] ?? 0);
            $url = trim((string)($photo['url'] ?? ''));
            if ($savedPaletteId <= 0 || $url === '') {
                continue;
            }

            $photosByPalette[$savedPaletteId] ??= [];

            $photoLibraryId = (int)($photo['photo_library_id'] ?? 0);
            $key = $photoLibraryId > 0
                ? 'library:' . $photoLibraryId
                : 'url:' . $url;

            $seen = false;
            foreach ($photosByPalette[$savedPaletteId] as $existing) {
                $existingId = (int)($existing['photo_library_id'] ?? 0);
                $existingKey = $existingId > 0
                    ? 'library:' . $existingId
                    : 'url:' . trim((string)($existing['url'] ?? ''));
                if ($existingKey === $key) {
                    $seen = true;
                    break;
                }
            }

            if (!$seen) {
                $photosByPalette[$savedPaletteId][] = [
                    'photo_library_id' => $photoLibraryId > 0
                        ? $photoLibraryId
                        : null,
                    'url' => $url,
                    'alt_text' => $photo['alt_text'] ?? null,
                    'photo_type' => 'full',
                ];
            }
        }

        $plans = [];
        $firstPhoto = null;
        $hasUnfinalized = false;

        foreach ($selected as $index => $selection) {
            $savedPaletteId = (int)($selection['saved_palette_id'] ?? 0);
            if ($savedPaletteId <= 0) {
                continue;
            }

            $savedPalette = $this->savedPaletteRepo->getFullPalette(
                $savedPaletteId
            );

            if ($savedPalette === null) {
                continue;
            }

            $palette = $savedPalette['palette'] ?? [];
            $members = $savedPalette['members'] ?? [];
            $areaLabel = trim((string)($selection['area_label'] ?? ''));

            if (!(bool)($selection['is_final'] ?? false)) {
                $hasUnfinalized = true;
            }

            $swatches = [];
            foreach ($members as $member) {
                $swatches[] = [
                    'id' => isset($member['color_id'])
                        ? (int)$member['color_id']
                        : null,
                    'name' => $member['color_name'] ?? null,
                    'code' => $member['color_code'] ?? null,
                    'brand' => $member['color_brand'] ?? null,
                    'brand_name' => $member['color_brand_name'] ?? null,
                    'hex6' => $member['color_hex6'] ?? null,
                    'role' => $member['role'] ?? null,
                    'role_name' => $member['role'] ?? null,
                    'sheen' => $member['sheen'] ?? null,
                    'note' => $member['note'] ?? null,
                    'int_only' => isset($member['color_int_only'])
                        ? (int)$member['color_int_only']
                        : 0,
                ];
            }

            $planPhotos = $photosByPalette[$savedPaletteId] ?? [];
            if ($firstPhoto === null && $planPhotos !== []) {
                $firstPhoto = $planPhotos[0];
            }

            $title = $areaLabel;
            if ($title === '') {
                $title = trim((string)($palette['display_title'] ?? ''));
            }
            if ($title === '') {
                $title = trim((string)($palette['nickname'] ?? ''));
            }
            if ($title === '') {
                $title = 'Area ' . ($index + 1);
            }

            $plans[] = [
                'key' => 'project-palette-' . (int)($selection['project_palette_id'] ?? 0),
                'id' => (int)($selection['project_palette_id'] ?? 0),
                'project_palette_id' => (int)($selection['project_palette_id'] ?? 0),
                'saved_palette_id' => $savedPaletteId,
                'title' => $title,
                'area_name' => $title,
                'scheme_title' => trim((string)($palette['display_title'] ?? $palette['nickname'] ?? '')),
                'painter_note' => trim((string)($selection['note'] ?? '')),
                'is_final' => (int)($selection['is_final'] ?? 0),
                'palette_type' => $palette['palette_type'] ?? null,
                'swatches' => $swatches,
                'photos' => $planPhotos,
            ];
        }

        if ($plans === []) {
            throw new RuntimeException(
                "Painter Viewer {$pvId} has no usable selected palettes"
            );
        }

        $viewerTitle = trim((string)($record['viewer_title'] ?? ''));
        $projectName = trim((string)($project['project_name'] ?? ''));
        $title = $viewerTitle !== ''
            ? $viewerTitle
            : ($projectName !== '' ? $projectName : 'Painter Specifications');

        $meta = [
            'source' => 'painter_project',
            'palette_viewer_id' => $pvId,
            'palette_viewer_key' => 'painter',
            'template_key' => trim((string)($record['template_key'] ?? '')) ?: 'painter',
            'format' => 'painter',
            'saved_palette_id' => null,
            'project_id' => $projectId,
            'id' => $pvId,
            'hash' => 'pv-' . $pvId,
            'title' => $title,
            'display_title' => $title,
            'intro' => $record['intro'] ?? '',
            'notes' => $record['viewer_notes'] ?? '',
            'area_label' => count($plans) === 1
                ? ($plans[0]['title'] ?? null)
                : null,
            'cta_label' => $record['cta_label'] ?? '',
            'playlist_url' => '',
            'photo_library_id' => isset($firstPhoto['photo_library_id'])
                ? (int)$firstPhoto['photo_library_id']
                : null,
            'photo_url' => $firstPhoto['url'] ?? '',
            'photo_alt' => $firstPhoto['alt_text'] ?? null,
            'inset_photos' => [],
            'kicker_text' => $record['kicker_text'] ?? '',
            'palette_type' => count($plans) === 1
                ? ($plans[0]['palette_type'] ?? null)
                : null,
            'not_final_warning' => $hasUnfinalized
                ? 'One or more selected palettes are not marked final.'
                : '',
            'set_id' => null,
            'available_sets' => [],
        ];

        return [
            'meta' => $meta,
            'swatches' => [],
            'plans' => $plans,
            'painterView' => [
                'projectName' => $projectName,
                'areaLabel' => count($plans) === 1
                    ? ($plans[0]['title'] ?? '')
                    : '',
            ],
        ];
    }

    public function getLinkedPVs(int $playlistId): array
    {
        if ($playlistId <= 0) {
            throw new InvalidArgumentException(
                'playlist id required'
            );
        }

        $links = $this->repo->findLinkedByPlaylistId($playlistId);

        $linkedPVs = [];

        foreach ($links as $link) {
            $pvId = (int)$link['pv_id'];
            $pv = $this->getPV($pvId);

            $pv['rex_url'] = $link['rex_url'];
            $pv['rex_reservation_id'] = $link['rex_reservation_id'];
            $pv['rex_sort_order'] = $link['sort_order'];

            $linkedPVs[] = $pv;
        }

        return $linkedPVs;
    }

    public function getLinkedPubData(int $playlistId): array
    {
        if ($playlistId <= 0) {
            throw new InvalidArgumentException(
                'playlist id required'
            );
        }

        return $this->repo->findLinkedPubData($playlistId);
    }
}
