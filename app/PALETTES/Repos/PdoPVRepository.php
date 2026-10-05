<?php
declare(strict_types=1);

namespace App\PALETTES\Repos;

use PDO;
use App\PROJECTS\Repos\PdoProjectPhotoRepository;

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

        $base['photos'] = (new PdoProjectPhotoRepository($this->pdo))->forViewer($pvId)
            ?? $this->loadPhotos($pvId);

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
                pv.project_id,
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


    /**
     * Create one first-class PV.
     */
    public function create(
        int $savedPaletteId,
        string $format,
        string $title,
        ?string $kickerText = null,
        ?string $intro = null
    ): int {
        $this->assertUniqueTitleExperience(0, $savedPaletteId, $format, $title);
        $stmt = $this->pdo->prepare(
            "INSERT INTO palette_viewers
                (
                    saved_palette_id,
                    format,
                    template_key,
                    kicker_text,
                    title,
                    intro,
                    notes,
                    cta_label,
                    is_active,
                    created_at,
                    updated_at
                )
             VALUES
                (
                    :saved_palette_id,
                    :format,
                    NULL,
                    :kicker_text,
                    :title,
                    :intro,
                    NULL,
                    NULL,
                    1,
                    NOW(),
                    NOW()
                )"
        );

        $stmt->execute([
            ':saved_palette_id' => $savedPaletteId,
            ':format' => strtolower(trim($format)),
            ':kicker_text' => $this->nullableText($kickerText),
            ':title' => $this->nullableText($title),
            ':intro' => $this->nullableText($intro),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Grid/list rows for one Project. Normal PVs are found through the
     * Project's saved palettes; Painter PVs are found directly by project_id.
     *
     * @param int[] $savedPaletteIds
     * @return array<int,array<string,mixed>>
     */
    public function listGridRowsForProject(
        int $projectId,
        array $savedPaletteIds
    ): array {
        if ($projectId <= 0) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn(mixed $value): int => (int)$value, $savedPaletteIds),
            static fn(int $value): bool => $value > 0
        )));

        $params = [':project_id' => $projectId];
        $normalClause = '0 = 1';

        if ($ids !== []) {
            $placeholders = [];
            foreach ($ids as $index => $id) {
                $key = ':palette_' . $index;
                $placeholders[] = $key;
                $params[$key] = $id;
            }
            $normalClause = 'pv.saved_palette_id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql =
            "SELECT
                pv.palette_viewer_id,
                pv.saved_palette_id,
                pv.project_id,
                pv.format,
                pv.kicker_text,
                pv.title,
                pv.intro,
                pv.is_active,

                CASE
                    WHEN LOWER(TRIM(COALESCE(pv.format, ''))) = 'painter'
                    THEN CONCAT(
                        (SELECT COUNT(*)
                           FROM palette_viewer_project_palettes pvpp
                          WHERE pvpp.palette_viewer_id = pv.palette_viewer_id),
                        ' areas'
                    )
                    ELSE COALESCE(
                        NULLIF(TRIM(sp.nickname), ''),
                        CONCAT('Palette #', sp.id)
                    )
                END AS palette_name,

                (
                    SELECT COUNT(*)
                      FROM palette_viewer_photos pvp
                     WHERE pvp.palette_viewer_id = pv.palette_viewer_id
                ) AS photo_count

             FROM palette_viewers pv

             LEFT JOIN saved_palettes sp
               ON sp.id = pv.saved_palette_id

             WHERE pv.is_active = 1
               AND (
                    (LOWER(TRIM(COALESCE(pv.format, ''))) = 'painter'
                     AND pv.project_id = :project_id)
                    OR
                    (LOWER(TRIM(COALESCE(pv.format, ''))) <> 'painter'
                     AND {$normalClause})
               )

             ORDER BY
                COALESCE(NULLIF(TRIM(pv.title), ''), '') ASC,
                pv.format ASC,
                pv.palette_viewer_id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * One grid/list row by PV id.
     *
     * @return array<string,mixed>|null
     */
    public function findGridRowById(
        int $pvId
    ): ?array {
        if ($pvId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                pv.palette_viewer_id,
                pv.saved_palette_id,
                pv.project_id,
                pv.format,
                pv.kicker_text,
                pv.title,
                pv.intro,
                pv.is_active,

                CASE
                    WHEN LOWER(TRIM(COALESCE(pv.format, ''))) = 'painter'
                    THEN CONCAT(
                        (SELECT COUNT(*)
                           FROM palette_viewer_project_palettes pvpp
                          WHERE pvpp.palette_viewer_id = pv.palette_viewer_id),
                        ' areas'
                    )
                    ELSE COALESCE(
                        NULLIF(TRIM(sp.nickname), ''),
                        CONCAT('Palette #', sp.id)
                    )
                END AS palette_name,

                (
                    SELECT COUNT(*)
                      FROM palette_viewer_photos pvp
                     WHERE pvp.palette_viewer_id =
                           pv.palette_viewer_id
                ) AS photo_count

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

    /** @param int[] $projectPaletteIds */
    public function createPainter(
        int $projectId,
        array $projectPaletteIds,
        string $title
    ): int {
        $this->assertUniqueTitleExperience($projectId, 0, 'painter', $title);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO palette_viewers
                    (saved_palette_id, project_id, format, template_key, title, is_active, created_at, updated_at)
                 VALUES
                    (NULL, :project_id, 'painter', 'painter', :title, 1, NOW(), NOW())"
            );
            $stmt->execute([
                ':project_id' => $projectId,
                ':title' => $this->nullableText($title),
            ]);

            $pvId = (int)$this->pdo->lastInsertId();

            $linkStmt = $this->pdo->prepare(
                "INSERT INTO palette_viewer_project_palettes
                    (palette_viewer_id, project_palette_id, order_index, created_at)
                 VALUES
                    (:pv_id, :project_palette_id, :order_index, NOW())"
            );

            foreach (array_values($projectPaletteIds) as $orderIndex => $projectPaletteId) {
                $linkStmt->execute([
                    ':pv_id' => $pvId,
                    ':project_palette_id' => (int)$projectPaletteId,
                    ':order_index' => $orderIndex,
                ]);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $pvId;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<int,array<string,mixed>> */
    /**
     * Dedicated admin document query for a project-scoped Painter PV.
     *
     * @return array<string,mixed>|null
     */
    public function findPainterAdminDocument(int $pvId): ?array
    {
        if ($pvId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                pv.palette_viewer_id,
                pv.project_id,
                pv.format,
                pv.template_key,
                pv.kicker_text,
                pv.title,
                pv.intro,
                pv.notes,
                pv.cta_label,
                pv.is_active,
                p.project_name

             FROM palette_viewers pv

             LEFT JOIN projects p
               ON p.id = pv.project_id

             WHERE pv.palette_viewer_id = :pv_id
               AND LOWER(TRIM(COALESCE(pv.format, ''))) = 'painter'

             LIMIT 1"
        );

        $stmt->execute([
            ':pv_id' => $pvId,
        ]);

        $viewer = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($viewer === false) {
            return null;
        }

        $viewer['palette_viewer_id'] = (int)$viewer['palette_viewer_id'];
        $viewer['project_id'] = isset($viewer['project_id'])
            ? (int)$viewer['project_id']
            : null;
        $viewer['saved_palette_id'] = null;
        $viewer['is_active'] = (int)($viewer['is_active'] ?? 0);

        $rex = null;

        $rexStmt = $this->pdo->prepare(
            "SELECT id, token
               FROM rex_reservations
              WHERE resolver_key = 'viewer'
                AND resource_type = 'palette_viewer'
                AND resource_id = :pv_id
                AND status = 'active'
                AND revoked_at IS NULL
              ORDER BY id ASC
              LIMIT 1"
        );
        $rexStmt->execute([':pv_id' => $pvId]);
        $rexRow = $rexStmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($rexRow)) {
            $token = trim((string)($rexRow['token'] ?? ''));
            if ($token !== '') {
                $rex = [
                    'reservation_id' => (int)($rexRow['id'] ?? 0),
                    'id' => (int)($rexRow['id'] ?? 0),
                    'token' => $token,
                    'public_url' => '/t/' . $token,
                ];
            }
        }

        $projectPalettes = $this->listPainterProjectPalettes($pvId);
        foreach ($projectPalettes as &$projectPalette) {
            $projectPalette['photos'] = (new PdoProjectPhotoRepository($this->pdo))->forPalette(
                (int)$viewer['project_id'], (int)$projectPalette['saved_palette_id'], true
            );
        }
        unset($projectPalette);

        return [
            'viewer' => $viewer,
            'project_palettes' => $projectPalettes,
            'rex' => $rex,
        ];
    }

    /**
     * Update the Painter document header and selected project palettes.
     *
     * @param int[] $projectPaletteIds
     */
    public function savePainterAdminDocument(
        int $pvId,
        int $projectId,
        array $projectPaletteIds,
        ?string $kickerText,
        string $title,
        ?string $intro,
        ?string $notes,
        bool $isActive,
        ?string $ctaLabel = null
    ): void {
        $this->assertUniqueTitleExperience($projectId, 0, 'painter', $title, $pvId);
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $projectPaletteIds),
            static fn(int $id): bool => $id > 0
        )));

        if ($pvId <= 0 || $projectId <= 0 || $ids === []) {
            throw new \InvalidArgumentException(
                'Painter viewer, project, and at least one project palette are required.'
            );
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $check = $this->pdo->prepare(
            "SELECT project_palette_id
               FROM project_palettes
              WHERE project_id = ?
                AND project_palette_id IN ({$placeholders})"
        );
        $check->execute([$projectId, ...$ids]);
        $allowed = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN) ?: []);
        sort($allowed);
        $expected = $ids;
        sort($expected);

        if ($allowed !== $expected) {
            throw new \InvalidArgumentException(
                'One or more selected palettes do not belong to this project.'
            );
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare(
                "UPDATE palette_viewers
                    SET saved_palette_id = NULL,
                        project_id = :project_id,
                        format = 'painter',
                        kicker_text = :kicker_text,
                        title = :title,
                        intro = :intro,
                        notes = :notes,
                        cta_label = :cta_label,
                        is_active = :is_active
                  WHERE palette_viewer_id = :pv_id"
            );
            $stmt->execute([
                ':project_id' => $projectId,
                ':kicker_text' => $kickerText,
                ':title' => $title,
                ':intro' => $intro,
                ':notes' => $notes,
                ':cta_label' => $ctaLabel,
                ':is_active' => $isActive ? 1 : 0,
                ':pv_id' => $pvId,
            ]);

            $delete = $this->pdo->prepare(
                "DELETE FROM palette_viewer_project_palettes
                  WHERE palette_viewer_id = :pv_id"
            );
            $delete->execute([':pv_id' => $pvId]);

            $insert = $this->pdo->prepare(
                "INSERT INTO palette_viewer_project_palettes
                    (palette_viewer_id, project_palette_id, order_index)
                 VALUES
                    (:pv_id, :project_palette_id, :order_index)"
            );

            foreach ($ids as $index => $projectPaletteId) {
                $insert->execute([
                    ':pv_id' => $pvId,
                    ':project_palette_id' => $projectPaletteId,
                    ':order_index' => $index,
                ]);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function listPainterProjectPalettes(int $pvId): array
    {
        if ($pvId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                pp.project_palette_id,
                pp.order_index AS viewer_order_index,
                pp.project_id,
                pp.saved_palette_id,
                pp.area_label,
                pp.note,
                pp.is_final,
                pp.order_index AS project_order_index
             FROM palette_viewers pv
             INNER JOIN project_palettes pp ON pp.project_id = pv.project_id
             WHERE pv.palette_viewer_id = :pv_id AND pp.is_final = 1
             ORDER BY pp.order_index ASC, pp.project_palette_id ASC"
        );
        $stmt->execute([':pv_id' => $pvId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findProjectSummary(int $projectId): ?array
    {
        if ($projectId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, project_name, playlist_id
               FROM projects
              WHERE id = :project_id
              LIMIT 1"
        );
        $stmt->execute([':project_id' => $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** @param int[] $savedPaletteIds */
    public function loadProjectPlaylistPhotos(
        int $projectId,
        array $savedPaletteIds
    ): array {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $savedPaletteIds),
            static fn(int $id): bool => $id > 0
        )));

        if ($projectId <= 0 || $ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [':project_id' => $projectId];
        foreach ($ids as $index => $id) {
            $key = ':palette_' . $index;
            $placeholders[] = $key;
            $params[$key] = $id;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                pi.saved_palette_id,
                pi.photo_library_id,
                pi.order_index,
                COALESCE(NULLIF(pl.rel_path, ''), NULLIF(pi.image_url, '')) AS rel_path,
                COALESCE(NULLIF(pl.ai_alt_text, ''), NULLIF(pl.alt_text, ''), NULLIF(pi.title, '')) AS alt_text,
                pl.updated_at AS resolved_updated_at
             FROM playlist_items pi
             INNER JOIN playlists p
               ON p.playlist_id = pi.playlist_id
             LEFT JOIN photo_library pl
               ON pl.photo_library_id = pi.photo_library_id
             WHERE p.project_id = :project_id
               AND pi.saved_palette_id IN (" . implode(', ', $placeholders) . ")
               AND (pi.photo_library_id IS NOT NULL OR NULLIF(TRIM(pi.image_url), '') IS NOT NULL)
               AND LOWER(COALESCE(pi.analyzer_role, 'ignore')) <> 'before'
             ORDER BY pi.saved_palette_id ASC, pi.order_index ASC, pi.playlist_item_id ASC"
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $row['url'] = $this->appendCacheBuster(
                trim((string)($row['rel_path'] ?? '')),
                $row['resolved_updated_at'] ?? null
            );
            $rows[] = $row;
        }
        return $rows;
    }

    public function assertUniqueTitleExperience(int $projectId, int $savedPaletteId, string $format, string $title, int $excludeId = 0): void
    {
        $projectIds = $projectId > 0 ? [$projectId] : [];
        if (!$projectIds && $savedPaletteId > 0) {
            $stmt = $this->pdo->prepare('SELECT DISTINCT project_id FROM project_palettes WHERE saved_palette_id = ?');
            $stmt->execute([$savedPaletteId]);
            $projectIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $scope = 'pv.saved_palette_id = ?';
        $scopeParams = [$savedPaletteId];
        if ($projectIds) {
            $marks = implode(',', array_fill(0, count($projectIds), '?'));
            $scope .= " OR pv.project_id IN ({$marks}) OR pv.saved_palette_id IN (SELECT saved_palette_id FROM project_palettes WHERE project_id IN ({$marks}))";
            $scopeParams = [...$scopeParams, ...$projectIds, ...$projectIds];
        }
        $stmt = $this->pdo->prepare("SELECT 1 FROM palette_viewers pv WHERE LOWER(TRIM(pv.title)) = ? AND LOWER(TRIM(pv.format)) = ? AND pv.palette_viewer_id <> ? AND ({$scope}) LIMIT 1");
        $stmt->execute([strtolower(trim($title)), strtolower(trim($format)), $excludeId, ...$scopeParams]);
        if ($stmt->fetchColumn()) {
            throw new \InvalidArgumentException('A PV with this title and experience already exists in this project. Change the title or experience.');
        }
    }

    public function updateHeader(int $pvId, array $fields): void
    {
        $stmt = $this->pdo->prepare('UPDATE palette_viewers SET template_key = ?, kicker_text = ?, intro = ?, notes = ?, cta_label = ?, is_active = ? WHERE palette_viewer_id = ?');
        $stmt->execute([
            $this->nullableText($fields['template_key'] ?? null),
            $this->nullableText($fields['kicker_text'] ?? null),
            $this->nullableText($fields['intro'] ?? null),
            $this->nullableText($fields['notes'] ?? null),
            $this->nullableText($fields['cta_label'] ?? null),
            (int)(bool)($fields['is_active'] ?? true), $pvId,
        ]);
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
