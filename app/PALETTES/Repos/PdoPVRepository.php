<?php
declare(strict_types=1);

namespace App\PALETTES\Repos;

use PDO;

final class PdoPVRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function findById(int $pvId): ?array
    {
        if ($pvId <= 0) {
            return null;
        }

        $base = $this->loadBase($pvId);

        if (!$base) {
            return null;
        }

        $base['photos'] = $this->loadPhotos($pvId);

        return $base;
    }

    /**
     * Return the active PUBLIC PV for one Saved Palette.
     *
     * Saved Palette visibility is owned by saved_palettes.is_public.
     * This method only answers the PV side of the relationship.
     */
    public function findActivePublicBySavedPaletteId(int $savedPaletteId): ?array
    {
        if ($savedPaletteId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT palette_viewer_id
               FROM palette_viewers
              WHERE saved_palette_id = :saved_palette_id
                AND is_active = 1
                AND LOWER(TRIM(COALESCE(format, ''))) = 'public'
              ORDER BY palette_viewer_id ASC
              LIMIT 1"
        );

        $stmt->execute([
            ':saved_palette_id' => $savedPaletteId,
        ]);

        $pvId = (int)($stmt->fetchColumn() ?: 0);

        return $pvId > 0
            ? $this->findById($pvId)
            : null;
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
                pv.is_active

             FROM palette_viewers pv

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
                pvp.photo_library_id,
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
                'photo_library_id' => isset($row['photo_library_id'])
                    ? (int)$row['photo_library_id']
                    : null,
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
     * LEGACY MIGRATION METHOD.
     * Remove after all callers use REX directly.
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
     * LEGACY MIGRATION METHOD.
     * Remove after PUB reads REX directly and hydrates PV data through PVService.
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

        $pvIds = array_values(array_unique($pvIds));
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

        $stmt->execute($photoParams);

        $photoIdsByPv = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $pvId = (int)$row['pv_id'];
            $photoLibraryId = (int)$row['photo_library_id'];

            if ($pvId <= 0 || $photoLibraryId <= 0) {
                continue;
            }

            $photoIdsByPv[$pvId] ??= [];

            if (!in_array($photoLibraryId, $photoIdsByPv[$pvId], true)) {
                $photoIdsByPv[$pvId][] = $photoLibraryId;
            }
        }

        $savedPaletteIds = array_values(array_unique($savedPaletteIds));
        $palettePlaceholders = [];
        $paletteParams = [];

        foreach ($savedPaletteIds as $index => $savedPaletteId) {
            $placeholder = ':saved_palette_' . $index;
            $palettePlaceholders[] = $placeholder;
            $paletteParams[$placeholder] = $savedPaletteId;
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

        $stmt->execute($paletteParams);

        $hex6sByPalette = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $savedPaletteId = (int)$row['saved_palette_id'];
            $hex6 = trim((string)($row['hex6'] ?? ''));

            if ($savedPaletteId <= 0 || $hex6 === '') {
                continue;
            }

            $hex6sByPalette[$savedPaletteId] ??= [];

            if (!in_array($hex6, $hex6sByPalette[$savedPaletteId], true)) {
                $hex6sByPalette[$savedPaletteId][] = $hex6;
            }
        }

        foreach ($resultByPv as $pvId => &$pv) {
            $savedPaletteId = $savedPaletteIdByPv[$pvId];
            $hex6s = $hex6sByPalette[$savedPaletteId] ?? [];

            foreach ($photoIdsByPv[$pvId] ?? [] as $photoLibraryId) {
                $pv['photo_palettes'][] = [
                    'photo_library_id' => $photoLibraryId,
                    'hex6s' => $hex6s,
                ];
            }
        }

        unset($pv);

        return array_values($resultByPv);
    }

    public function deletePhotosByPVId(int $pvId): int
    {
        if ($pvId <= 0) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM palette_viewer_photos
              WHERE palette_viewer_id = :pv_id'
        );

        $stmt->execute([
            ':pv_id' => $pvId,
        ]);

        return $stmt->rowCount();
    }

    public function deleteById(int $pvId): int
    {
        if ($pvId <= 0) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM palette_viewers
              WHERE palette_viewer_id = :pv_id'
        );

        $stmt->execute([
            ':pv_id' => $pvId,
        ]);

        return $stmt->rowCount();
    }


}
