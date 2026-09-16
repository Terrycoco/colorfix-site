<?php
declare(strict_types=1);

namespace App\PALETTES\PV;

use App\PALETTES\Repos\PdoPVRepository;
use App\PALETTES\Repos\PdoSavedPaletteRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class PVService
{
    private PdoPVRepository $repo;
    private PdoSavedPaletteRepository $savedPaletteRepo;

    public function __construct(PDO $pdo)
    {
        $this->repo = new PdoPVRepository($pdo);
        $this->savedPaletteRepo = new PdoSavedPaletteRepository($pdo);
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

    $fullPhoto = null;
    $insets = [];

    foreach ($photos as $photo) {
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
                'url' => $url,
                'alt_text' => $photo['alt_text'] ?? null,
                'caption' => 'Before',
            ];

            continue;
        }

        if (in_array($type, ['zoom', 'inset'], true)) {
            $insets[] = [
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

    $title = trim(
        (string)($record['viewer_title'] ?? '')
    );

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
    $format = strtolower(
        trim((string)($record['format'] ?? 'public'))
    );

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
            $record['viewer_notes']
            ?? $palette['notes']
            ?? '',

        'cta_label' => $record['cta_label'] ?? '',
        'playlist_url' => '',

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

        // Important: one canonical definition of a complete PV.
        $pv = $this->getPV($pvId);

        // Relationship data belongs to getLinkedPVs(), not getPV().
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