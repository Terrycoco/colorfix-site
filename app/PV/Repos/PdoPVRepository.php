<?php
declare(strict_types=1);

namespace App\PV\Repos;

use App\PV\PV;
use PDO;
use RuntimeException;

final class PdoPVRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function findById(int $pvId): ?PV
    {
        if ($pvId <= 0) {
            return null;
        }

        $base = $this->loadBase($pvId);
        if (!$base) {
            return null;
        }

        $savedPaletteId = (int)($base['saved_palette_id'] ?? 0);
        $paletteId = (int)($base['palette_id'] ?? 0);

        if ($savedPaletteId <= 0 || $paletteId <= 0) {
            throw new RuntimeException(
                "Saved Palette {$savedPaletteId} not found"
            );
        }

        $photos = $this->loadPhotos($pvId);
        $swatches = $this->loadSwatches($savedPaletteId);

        $fullPhoto = null;
        $insets = [];

        foreach ($photos as $photo) {
            $type = strtolower(trim((string)($photo['photo_type'] ?? '')));
            $url = trim((string)($photo['url'] ?? ''));

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

        // Preserve current canonical behavior:
        // if no FULL photo exists, use the first usable photo.
        if ($fullPhoto === null) {
            foreach ($photos as $photo) {
                if (trim((string)($photo['url'] ?? '')) !== '') {
                    $fullPhoto = $photo;
                    break;
                }
            }
        }

        $title = $this->firstNonEmpty([
            $base['viewer_title'] ?? null,
            $base['palette_display_title'] ?? null,
            $base['nickname'] ?? null,
            'ColorFix Palette',
        ]);

        $format = strtolower(trim((string)($base['format'] ?? 'public')));

        $paletteViewerKey = $format === 'public'
            ? 'full_palette'
            : $format;

        $templateKey = trim((string)($base['template_key'] ?? ''));
        if ($templateKey === '') {
            $templateKey = $paletteViewerKey;
        }

        $meta = [
            'source' => 'saved',
            'palette_viewer_id' => $pvId,
            'palette_viewer_key' => $paletteViewerKey,
            'template_key' => $templateKey,
            'format' => $format,
            'saved_palette_id' => $savedPaletteId,
            'id' => $paletteId,
            'hash' => (string)($base['palette_hash'] ?? ''),
            'title' => $title,
            'nickname' => $base['nickname'] ?? null,
            'display_title' => $title,
            'intro' => $base['intro'] ?? '',
            'notes' => $base['viewer_notes']
                ?? $base['palette_notes']
                ?? '',
            'cta_label' => $base['cta_label'] ?? '',
            'playlist_url' => '',
            'photo_url' => $fullPhoto['url'] ?? '',
            'photo_alt' => $fullPhoto['alt_text'] ?? null,
            'inset_photos' => $insets,
            'kicker_text' => $base['kicker_text'] ?? '',
            'palette_type' => $base['palette_type'] ?? null,
            'set_id' => null,
            'available_sets' => [],
        ];

        return new PV(
            pvId: $pvId,
            savedPaletteId: $savedPaletteId,
            isActive: (bool)($base['is_active'] ?? false),
            meta: $meta,
            swatches: $swatches
        );
    }

    private function loadBase(int $pvId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                pv.palette_viewer_id,
                pv.saved_palette_id,
                pv.format,
                NULLIF(TRIM(pv.template_key), '') AS template_key,
                NULLIF(TRIM(pv.kicker_text), '') AS kicker_text,
                NULLIF(TRIM(pv.title), '') AS viewer_title,
                NULLIF(TRIM(pv.intro), '') AS intro,
                NULLIF(TRIM(pv.notes), '') AS viewer_notes,
                NULLIF(TRIM(pv.cta_label), '') AS cta_label,
                pv.is_active,

                sp.id AS palette_id,
                sp.palette_hash,
                sp.nickname,
                sp.display_title AS palette_display_title,
                sp.notes AS palette_notes,
                sp.palette_type

             FROM palette_viewers pv

             LEFT JOIN saved_palettes sp
               ON sp.id = pv.saved_palette_id

             WHERE pv.palette_viewer_id = :pv_id
             LIMIT 1"
        );

        $stmt->execute([
            ':pv_id' => $pvId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    private function loadPhotos(int $pvId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                pvp.photo_type,
                pvp.caption,

                COALESCE(
                    NULLIF(pl.rel_path, ''),
                    NULLIF(pvp.rel_path, '')
                ) AS rel_path,

                COALESCE(
                    NULLIF(pvp.alt_text, ''),
                    NULLIF(pl.ai_alt_text, ''),
                    NULLIF(pl.alt_text, '')
                ) AS alt_text,

                COALESCE(
                    pl.updated_at,
                    pvp.updated_at
                ) AS resolved_updated_at

             FROM palette_viewer_photos pvp

             LEFT JOIN photo_library pl
               ON pl.photo_library_id = pvp.photo_library_id

             WHERE pvp.palette_viewer_id = :pv_id

             ORDER BY
                pvp.order_index ASC,
                pvp.palette_viewer_photo_id ASC"
        );

        $stmt->execute([
            ':pv_id' => $pvId,
        ]);

        $photos = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $path = trim((string)($row['rel_path'] ?? ''));

            $photos[] = [
                'photo_type' => strtolower(
                    trim((string)($row['photo_type'] ?? ''))
                ),
                'url' => $this->appendCacheBuster(
                    $path,
                    $row['resolved_updated_at'] ?? null
                ),
                'alt_text' => $this->nullableText(
                    $row['alt_text'] ?? null
                ),
                'caption' => $this->nullableText(
                    $row['caption'] ?? null
                ),
            ];
        }

        return $photos;
    }

    private function loadSwatches(int $savedPaletteId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                m.color_id,
                m.role_name AS role,

                c.name AS color_name,
                c.brand AS color_brand,
                c.brand_name AS color_brand_name,
                c.code AS color_code,
                c.hex6 AS color_hex6,
                c.int_only AS color_int_only

             FROM saved_palette_members m

             LEFT JOIN swatch_view c
               ON c.id = m.color_id

             WHERE m.saved_palette_id = :saved_palette_id

             ORDER BY
                m.order_index ASC,
                m.id ASC"
        );

        $stmt->execute([
            ':saved_palette_id' => $savedPaletteId,
        ]);

        $swatches = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $swatches[] = [
                'id' => isset($row['color_id'])
                    ? (int)$row['color_id']
                    : null,

                'name' => $row['color_name'] ?? null,
                'code' => $row['color_code'] ?? null,
                'brand' => $row['color_brand'] ?? null,
                'brand_name' => $row['color_brand_name'] ?? null,
                'hex6' => $row['color_hex6'] ?? null,
                'role' => $row['role'] ?? null,

                'int_only' => isset($row['color_int_only'])
                    ? (int)$row['color_int_only']
                    : 0,
            ];
        }

        return $swatches;
    }

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

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function appendCacheBuster(
        string $url,
        mixed $updatedAt
    ): string {
        $url = trim($url);
        $updatedAt = trim((string)($updatedAt ?? ''));

        if (
            $url === ''
            || $updatedAt === ''
            || str_contains($url, '?v=')
            || str_contains($url, '&v=')
        ) {
            return $url;
        }

        $stamp = strtotime($updatedAt);

        if ($stamp === false || $stamp <= 0) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'v=' . $stamp;
    }


/**
 * Find active PVs linked through REX to a Playlist.
 *
 * @return array<int, array{
 *     pv_id:int,
 *     rex_reservation_id:int,
 *     rex_url:string,
 *     sort_order:int
 * }>
 */
public function findLinkedByPlaylistId(int $playlistId): array
{
    if ($playlistId <= 0) {
        return [];
    }

    $stmt = $this->pdo->prepare(
        "SELECT
            child.id AS rex_reservation_id,
            child.resource_id AS palette_viewer_id,
            child.token AS viewer_token,
            l.sort_order

         FROM rex_reservations parent

         INNER JOIN rex_reservation_links l
           ON l.parent_reservation_id = parent.id
          AND l.relationship_key = 'viewer'

         INNER JOIN rex_reservations child
           ON child.id = l.child_reservation_id

         INNER JOIN palette_viewers pv
           ON pv.palette_viewer_id = child.resource_id
          AND pv.is_active = 1

         WHERE parent.resolver_key = 'playlist_experience'
           AND parent.resource_id = :playlist_id
           AND parent.status = 'active'

           AND child.resolver_key = 'viewer'
           AND child.resource_type = 'palette_viewer'
           AND child.status = 'active'

         ORDER BY
            l.sort_order ASC,
            l.id ASC,
            child.id ASC"
    );

    $stmt->execute([
        ':playlist_id' => $playlistId,
    ]);

    $linked = [];
    $seen = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pvId = (int)($row['palette_viewer_id'] ?? 0);

        if ($pvId <= 0 || isset($seen[$pvId])) {
            continue;
        }

        $token = trim((string)($row['viewer_token'] ?? ''));
        if ($token === '') {
            continue;
        }

        $seen[$pvId] = true;

        $linked[] = [
            'pv_id' => $pvId,
            'rex_reservation_id' => (int)$row['rex_reservation_id'],
            'rex_url' => '/t/' . $token,
            'sort_order' => (int)$row['sort_order'],
        ];
    }

    return $linked;
}

/**
 * Return exactly the linked-PV data declared by PUBContract.
 *
 * @return array<int, array{
 *     pv_id:int,
 *     kicker:string,
 *     intro:string,
 *     photo_palettes:array<int, array{
 *         photo_library_id:int,
 *         hex6s:array<int,string>
 *     }>
 * }>
 */

public function findLinkedPubData(int $playlistId): array
{
    if ($playlistId <= 0) {
        return [];
    }

    /*
     * 1. Find the active PVs linked to this playlist.
     *
     * saved_palette_id is used internally to obtain the colors.
     * It is NOT returned to PUB.
     *
     * kicker_text is the reusable publishing/search context.
     * The PV title remains the identity of the individual palette
     * and is intentionally NOT returned in the PUB grocery run.
     */
    $stmt = $this->pdo->prepare(
        "SELECT
            child.resource_id AS pv_id,
            pv.saved_palette_id,

            COALESCE(
                NULLIF(TRIM(pv.kicker_text), ''),
                ''
            ) AS kicker,

            COALESCE(pv.intro, '') AS intro,

            MIN(l.sort_order) AS link_sort_order,
            MIN(l.id) AS link_id

         FROM rex_reservations parent

         INNER JOIN rex_reservation_links l
           ON l.parent_reservation_id = parent.id
          AND l.relationship_key = 'viewer'

         INNER JOIN rex_reservations child
           ON child.id = l.child_reservation_id

         INNER JOIN palette_viewers pv
           ON pv.palette_viewer_id = child.resource_id
          AND pv.is_active = 1

         WHERE parent.resolver_key = 'playlist_experience'
           AND parent.resource_id = :playlist_id
           AND parent.status = 'active'

           AND child.resolver_key = 'viewer'
           AND child.resource_type = 'palette_viewer'
           AND child.status = 'active'

         GROUP BY
            child.resource_id,
            pv.saved_palette_id,
            pv.kicker_text,
            pv.intro

         ORDER BY
            link_sort_order ASC,
            link_id ASC,
            pv_id ASC"
    );

    $stmt->execute([
        ':playlist_id' => $playlistId,
    ]);

    $pvRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($pvRows === []) {
        return [];
    }

    $pvIds = [];
    $savedPaletteIds = [];
    $savedPaletteIdByPv = [];
    $resultByPv = [];

    foreach ($pvRows as $row) {
        $pvId = (int)$row['pv_id'];
        $savedPaletteId = (int)$row['saved_palette_id'];

        if ($pvId <= 0 || $savedPaletteId <= 0) {
            continue;
        }

        $pvIds[] = $pvId;
        $savedPaletteIds[] = $savedPaletteId;
        $savedPaletteIdByPv[$pvId] = $savedPaletteId;

        $resultByPv[$pvId] = [
            'pv_id' => $pvId,
            'kicker' => trim((string)$row['kicker']),
            'intro' => trim((string)$row['intro']),
            'photo_palettes' => [],
        ];
    }

    if ($resultByPv === []) {
        return [];
    }

    /*
     * 2. Get only the PhotoEntity join key for each PV photo.
     */
    $pvIds = array_values(
        array_unique(
            $pvIds
        )
    );

    $photoPlaceholders = [];
    $photoParams = [];

    foreach ($pvIds as $index => $pvId) {
        $placeholder = ':pv_' . $index;

        $photoPlaceholders[] = $placeholder;
        $photoParams[$placeholder] = $pvId;
    }

    $stmt = $this->pdo->prepare(
        "SELECT
            pvp.palette_viewer_id AS pv_id,
            pvp.photo_library_id

         FROM palette_viewer_photos pvp

         WHERE pvp.palette_viewer_id IN (" .
            implode(', ', $photoPlaceholders) .
         ")
           AND pvp.photo_library_id IS NOT NULL
           AND pvp.photo_library_id > 0

         ORDER BY
            pvp.palette_viewer_id ASC,
            pvp.order_index ASC,
            pvp.palette_viewer_photo_id ASC"
    );

    $stmt->execute(
        $photoParams
    );

    $photoIdsByPv = [];

    foreach (
        $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        as $row
    ) {
        $pvId =
            (int)$row['pv_id'];

        $photoLibraryId =
            (int)$row['photo_library_id'];

        if (
            $pvId <= 0
            || $photoLibraryId <= 0
        ) {
            continue;
        }

        $photoIdsByPv[$pvId] ??= [];

        if (
            !in_array(
                $photoLibraryId,
                $photoIdsByPv[$pvId],
                true
            )
        ) {
            $photoIdsByPv[$pvId][] =
                $photoLibraryId;
        }
    }

    /*
     * 3. Get only the hex6 values for each palette.
     */
    $savedPaletteIds =
        array_values(
            array_unique(
                $savedPaletteIds
            )
        );

    $palettePlaceholders = [];
    $paletteParams = [];

    foreach (
        $savedPaletteIds
        as $index => $savedPaletteId
    ) {
        $placeholder =
            ':saved_palette_' . $index;

        $palettePlaceholders[] =
            $placeholder;

        $paletteParams[$placeholder] =
            $savedPaletteId;
    }

    $stmt = $this->pdo->prepare(
        "SELECT
            m.saved_palette_id,
            c.hex6

         FROM saved_palette_members m

         LEFT JOIN swatch_view c
           ON c.id = m.color_id

         WHERE m.saved_palette_id IN (" .
            implode(', ', $palettePlaceholders) .
         ")

         ORDER BY
            m.saved_palette_id ASC,
            m.order_index ASC,
            m.id ASC"
    );

    $stmt->execute(
        $paletteParams
    );

    $hex6sByPalette = [];

    foreach (
        $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        as $row
    ) {
        $savedPaletteId =
            (int)$row['saved_palette_id'];

        $hex6 =
            trim(
                (string)(
                    $row['hex6']
                    ?? ''
                )
            );

        if (
            $savedPaletteId <= 0
            || $hex6 === ''
        ) {
            continue;
        }

        $hex6sByPalette[$savedPaletteId] ??= [];

        if (
            !in_array(
                $hex6,
                $hex6sByPalette[$savedPaletteId],
                true
            )
        ) {
            $hex6sByPalette[$savedPaletteId][] =
                $hex6;
        }
    }

    /*
     * 4. Assemble exactly the PUBContract projection.
     */
    foreach (
        $resultByPv
        as $pvId => &$pv
    ) {
        $savedPaletteId =
            $savedPaletteIdByPv[$pvId];

        $hex6s =
            $hex6sByPalette[
                $savedPaletteId
            ]
            ?? [];

        foreach (
            $photoIdsByPv[$pvId]
            ?? []
            as $photoLibraryId
        ) {
            $pv['photo_palettes'][] = [
                'photo_library_id' =>
                    $photoLibraryId,

                'hex6s' =>
                    $hex6s,
            ];
        }
    }

    unset($pv);

    return array_values(
        $resultByPv
    );
}


}