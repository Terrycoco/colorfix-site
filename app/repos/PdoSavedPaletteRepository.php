<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;
use PDOException;

class PdoSavedPaletteRepository
{
    private PDO $pdo;
    private ?bool $hasSetTables = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create a new saved palette row.
     *
     * Expected keys in $data:
     *  - palette_hash (string, optional but recommended)
     *  - brand        (string, e.g. 'de', 'behr')
     *  - nickname     (string|null)
     *  - display_title (string|null)
     *  - notes         (string|null)
     *  - private_notes (string|null)
     *  - terry_fav    (bool|int|null)
     *  - kicker_id    (int|null)
     *  - palette_type (string|null)
     */
    public function createSavedPalette(array $data): int
    {
        $sql = "
            INSERT INTO saved_palettes
                (palette_hash, brand, nickname, display_title, notes, private_notes, terry_fav, kicker_id, palette_type, created_at)
            VALUES
                (:palette_hash, :brand, :nickname, :display_title, :notes, :private_notes, :terry_fav, :kicker_id, :palette_type, NOW())
        ";

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            ':palette_hash'  => $data['palette_hash'] ?? null,
            ':brand'         => $data['brand'],
            ':nickname'      => $data['nickname'] ?? null,
            ':display_title' => $data['display_title'] ?? null,
            ':notes'         => $data['notes'] ?? null,
            ':private_notes' => $data['private_notes'] ?? null,
            ':terry_fav'     => isset($data['terry_fav']) ? (int) (bool) $data['terry_fav'] : 0,
            ':kicker_id'     => isset($data['kicker_id']) ? (int) $data['kicker_id'] : null,
            ':palette_type'  => $data['palette_type'] ?? 'exterior',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update an existing saved palette.
     *
     * $fields is an associative array of column => value.
     * Only the provided keys will be updated.
     */
    public function updateSavedPalette(int $id, array $fields): void
    {
        if (empty($fields)) {
            return;
        }

        $allowed = [
            'palette_hash',
            'brand',
            'nickname',
            'display_title',
            'notes',
            'private_notes',
            'terry_fav',
            'kicker_id',
            'palette_type',
        ];

        $setParts = [];
        $params   = [':id' => $id];

        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }

            $paramKey = ':' . $column;

            if ($column === 'terry_fav') {
                $value = (int) (bool) $value;
            }

            $setParts[]         = "{$column} = {$paramKey}";
            $params[$paramKey]  = $value;
        }

        if (empty($setParts)) {
            return;
        }

        $sql = "
            UPDATE saved_palettes
               SET " . implode(', ', $setParts) . ",
                   updated_at = NOW()
             WHERE id = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Fetch a saved palette row by id (without members).
     */
    public function getSavedPaletteById(int $id): ?array
    {
        $sql = "SELECT * FROM saved_palettes WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Fetch a saved palette by palette_hash and brand (if you want to dedupe).
     */
    public function getSavedPaletteByHashAndBrand(string $hash, string $brand): ?array
    {
        $sql = "
            SELECT *
              FROM saved_palettes
             WHERE palette_hash = :hash
               AND brand = :brand
             LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':hash'  => $hash,
            ':brand' => $brand,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function getSavedPaletteByHash(string $hash): ?array
    {
        $sql = "SELECT * FROM saved_palettes WHERE palette_hash = :hash LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':hash' => $hash,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function getSavedPaletteByPrivateNotes(string $privateNotes): ?array
    {
        $sql = "SELECT * FROM saved_palettes WHERE private_notes = :private_notes LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':private_notes' => $privateNotes,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function getKickerText(int $kickerId): ?string
    {
        if ($kickerId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT display_text FROM kickers WHERE kicker_id = :id");
        $stmt->execute([':id' => $kickerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !isset($row['display_text'])) {
            return null;
        }
        return (string)$row['display_text'];
    }

    public function getFullPaletteByHash(string $hash): ?array
    {
        return $this->getFullPaletteByHashAndSet($hash, null);
    }

    /**
     * Delete a saved palette and cascade members + views.
     */
    public function deleteSavedPalette(int $id): void
    {
        $sql = "DELETE FROM saved_palettes WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
    }

    public function countPlaylistUsageForPalette(int $savedPaletteId): int
    {
        if ($savedPaletteId <= 0 || !$this->tableExists('playlist_items')) {
            return 0;
        }

        $queries = [];
        $params = [];

        if (
            $this->usesPaletteSets()
            && $this->columnExists('playlist_items', 'saved_palette_set_id')
        ) {
            $queries[] = "
                SELECT pi.playlist_item_id
                  FROM saved_palette_sets s
                  JOIN playlist_items pi
                    ON pi.saved_palette_set_id = s.id
                 WHERE s.saved_palette_id = :id_sets
            ";
            $params[':id_sets'] = $savedPaletteId;
        }

        if (
            $this->usesPaletteSets()
            && $this->columnExists('playlist_items', 'photo_library_id')
            && $this->columnExists('saved_palette_set_photos', 'photo_library_id')
        ) {
            $queries[] = "
                SELECT pi.playlist_item_id
                  FROM saved_palette_sets s
                  JOIN saved_palette_set_photos sp
                    ON sp.saved_palette_set_id = s.id
                  JOIN playlist_items pi
                    ON pi.photo_library_id = sp.photo_library_id
                 WHERE s.saved_palette_id = :id_photos
            ";
            $params[':id_photos'] = $savedPaletteId;
        }

        if (
            !$this->usesPaletteSets()
            && $this->tableExists('saved_palette_photos')
            && $this->columnExists('playlist_items', 'photo_library_id')
            && $this->columnExists('saved_palette_photos', 'photo_library_id')
        ) {
            $queries[] = "
                SELECT pi.playlist_item_id
                  FROM saved_palette_photos sp
                  JOIN playlist_items pi
                    ON pi.photo_library_id = sp.photo_library_id
                 WHERE sp.saved_palette_id = :id_legacy_photos
            ";
            $params[':id_legacy_photos'] = $savedPaletteId;
        }

        if ($queries === []) {
            return 0;
        }

        $sql = 'SELECT COUNT(*) FROM (' . implode(' UNION ', $queries) . ') used_palette_items';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Remove all views for a palette.
     */
    public function deleteViewsForPalette(int $savedPaletteId): void
    {
        $sql = "DELETE FROM saved_palette_views WHERE saved_palette_id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $savedPaletteId]);
    }

    /**
     * Remove all photos for a palette.
     */
    public function deletePhotosForPalette(int $savedPaletteId): void
    {
        if ($this->usesPaletteSets()) {
            $sql = "
                DELETE sp
                  FROM saved_palette_set_photos sp
                  JOIN saved_palette_sets s
                    ON s.id = sp.saved_palette_set_id
                 WHERE s.saved_palette_id = :id
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id' => $savedPaletteId]);
            return;
        }

        $sql = "DELETE FROM saved_palette_photos WHERE saved_palette_id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $savedPaletteId]);
    }

    /**
     * Fetch photos for a palette.
     */
    public function getPhotosForPalette(int $savedPaletteId, ?int $setId = null): array
    {
        if ($this->usesPaletteSets()) {
            $resolvedSetId = $this->resolveSetIdForPalette($savedPaletteId, $setId);
            if ($resolvedSetId <= 0) {
                return [];
            }

            $sql = "
                SELECT sp.id,
                       s.saved_palette_id,
                       sp.saved_palette_set_id,
                       s.slug AS set_slug,
                       s.title AS set_title,
                       s.is_default AS set_is_default,
                       sp.photo_library_id,
                       CASE
                           WHEN sp.photo_library_id IS NOT NULL THEN COALESCE(pl.rel_path, '')
                           ELSE ''
                       END AS rel_path,
                       sp.photo_type,
                       sp.trigger_mode,
                       sp.trigger_color_id,
                       CASE
                           WHEN sp.photo_library_id IS NOT NULL THEN COALESCE(pl.show_in_gallery, 0)
                           ELSE sp.show_in_gallery
                       END AS show_in_gallery,
                       sp.use_palette_default_roles,
                       sp.caption,
                       sp.alt_text,
                       sp.order_index,
                       sp.created_at
                  FROM saved_palette_set_photos sp
                  JOIN saved_palette_sets s
                    ON s.id = sp.saved_palette_set_id
             LEFT JOIN photo_library pl
                    ON pl.photo_library_id = sp.photo_library_id
                 WHERE sp.saved_palette_set_id = :set_id
              ORDER BY sp.order_index ASC, sp.id ASC
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':set_id' => $resolvedSetId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $sql = "
            SELECT id,
                   saved_palette_id,
                   rel_path,
                   photo_type,
                   trigger_mode,
                   trigger_color_id,
                   caption,
                   alt_text,
                   order_index,
                   created_at
              FROM saved_palette_photos
             WHERE saved_palette_id = :id
          ORDER BY order_index ASC, id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $savedPaletteId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listPhotosNeedingPathNormalization(): array
    {
        if ($this->usesPaletteSets()) {
            $stmt = $this->pdo->query(
                "SELECT sp.id,
                        s.saved_palette_id,
                        sp.saved_palette_set_id,
                        sp.photo_library_id,
                        sp.rel_path,
                        sp.photo_type
                   FROM saved_palette_set_photos sp
                   JOIN saved_palette_sets s
                     ON s.id = sp.saved_palette_set_id
                  WHERE sp.rel_path LIKE '%?%'
               ORDER BY sp.id ASC"
            );
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        }

        $stmt = $this->pdo->query(
            "SELECT id,
                    saved_palette_id,
                    NULL AS saved_palette_set_id,
                    NULL AS photo_library_id,
                    rel_path,
                    photo_type
               FROM saved_palette_photos
              WHERE rel_path LIKE '%?%'
           ORDER BY id ASC"
        );
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * Fetch a single photo row.
     */
    public function getPhotoById(int $photoId): ?array
    {
        if ($this->usesPaletteSets()) {
            $sql = "
                SELECT sp.id,
                       sp.saved_palette_set_id,
                       sp.photo_library_id,
                       CASE
                           WHEN sp.photo_library_id IS NOT NULL THEN COALESCE(pl.rel_path, '')
                           ELSE ''
                       END AS rel_path,
                       sp.photo_type,
                       sp.trigger_mode,
                       sp.trigger_color_id,
                       CASE
                           WHEN sp.photo_library_id IS NOT NULL THEN COALESCE(pl.show_in_gallery, 0)
                           ELSE sp.show_in_gallery
                       END AS show_in_gallery,
                       sp.use_palette_default_roles,
                       sp.caption,
                       sp.alt_text,
                       sp.order_index,
                       sp.created_at,
                       sp.updated_at,
                       s.saved_palette_id,
                       s.slug AS set_slug,
                       s.title AS set_title,
                       s.is_default AS set_is_default
                  FROM saved_palette_set_photos sp
                  JOIN saved_palette_sets s
                    ON s.id = sp.saved_palette_set_id
             LEFT JOIN photo_library pl
                    ON pl.photo_library_id = sp.photo_library_id
                 WHERE sp.id = :id
                 LIMIT 1
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id' => $photoId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                return $row;
            }
        }

        $sql = "SELECT * FROM saved_palette_photos WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $photoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function listLinksByPhotoLibraryId(int $photoLibraryId): array
    {
        if (!$this->usesPaletteSets() || $photoLibraryId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT sp.id,
                    sp.photo_library_id,
                    CASE
                        WHEN sp.photo_library_id IS NOT NULL THEN COALESCE(pl.rel_path, '')
                        ELSE ''
                    END AS rel_path,
                    sp.photo_type,
                    sp.trigger_mode,
                    sp.trigger_color_id,
                    CASE
                        WHEN sp.photo_library_id IS NOT NULL THEN COALESCE(pl.show_in_gallery, 0)
                        ELSE sp.show_in_gallery
                    END AS show_in_gallery,
                    sp.caption,
                    sp.alt_text,
                    s.id AS saved_palette_set_id,
                    CONCAT(
                        COALESCE(NULLIF(s.title, ''), s.slug, 'Viewer'),
                        ' #',
                        s.id
                    ) AS set_label,
                    s.saved_palette_id,
                    COALESCE(NULLIF(p.nickname, ''), p.palette_hash, CONCAT('Saved #', p.id)) AS palette_label
               FROM saved_palette_set_photos sp
               JOIN saved_palette_sets s
                 ON s.id = sp.saved_palette_set_id
               JOIN saved_palettes p
                 ON p.id = s.saved_palette_id
          LEFT JOIN photo_library pl
                 ON pl.photo_library_id = sp.photo_library_id
              WHERE sp.photo_library_id = :photo_library_id
           ORDER BY p.nickname ASC, s.is_default DESC, s.order_index ASC, sp.id ASC"
        );
        $stmt->execute([':photo_library_id' => $photoLibraryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function hasFullPhotoInPaletteSet(int $savedPaletteId, ?int $setId = null, ?int $excludePhotoId = null): bool
    {
        if ($this->usesPaletteSets()) {
            $resolvedSetId = $this->resolveSetIdForPalette($savedPaletteId, $setId, true);
            if ($resolvedSetId <= 0) {
                return false;
            }

            $sql = "
                SELECT 1
                  FROM saved_palette_set_photos
                 WHERE saved_palette_set_id = :set_id
                   AND photo_type = 'full'
            ";
            $params = [':set_id' => $resolvedSetId];
            if ($excludePhotoId !== null && $excludePhotoId > 0) {
                $sql .= " AND id <> :exclude_id";
                $params[':exclude_id'] = $excludePhotoId;
            }
            $sql .= " LIMIT 1";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (bool)$stmt->fetchColumn();
        }

        $sql = "
            SELECT 1
              FROM saved_palette_photos
             WHERE saved_palette_id = :palette_id
               AND photo_type = 'full'
        ";
        $params = [':palette_id' => $savedPaletteId];
        if ($excludePhotoId !== null && $excludePhotoId > 0) {
            $sql .= " AND id <> :exclude_id";
            $params[':exclude_id'] = $excludePhotoId;
        }
        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Get the highest order_index for a palette's photos.
     */
    public function getMaxPhotoOrder(int $savedPaletteId, ?int $setId = null): int
    {
        if ($this->usesPaletteSets()) {
            $resolvedSetId = $this->resolveSetIdForPalette($savedPaletteId, $setId);
            if ($resolvedSetId <= 0) {
                return 0;
            }

            $sql = "SELECT MAX(order_index) AS max_order FROM saved_palette_set_photos WHERE saved_palette_set_id = :set_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':set_id' => $resolvedSetId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['max_order'] ?? 0);
        }

        $sql = "SELECT MAX(order_index) AS max_order FROM saved_palette_photos WHERE saved_palette_id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $savedPaletteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['max_order'] ?? 0);
    }

    /**
     * Add a photo row for a palette.
     */
    public function addPhoto(int $savedPaletteId, string $relPath, ?string $caption, ?string $altText, int $orderIndex, ?int $setId = null, ?int $photoLibraryId = null): int
    {
        if ($this->usesPaletteSets()) {
            $resolvedSetId = $this->resolveSetIdForPalette($savedPaletteId, $setId, true);
            $sql = "
                INSERT INTO saved_palette_set_photos
                    (saved_palette_set_id, photo_library_id, rel_path, photo_type, trigger_mode, trigger_color_id, show_in_gallery, use_palette_default_roles, caption, alt_text, order_index, created_at, updated_at)
                VALUES
                    (:saved_palette_set_id, :photo_library_id, :rel_path, :photo_type, :trigger_mode, :trigger_color_id, :show_in_gallery, :use_palette_default_roles, :caption, :alt_text, :order_index, NOW(), NOW())
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':saved_palette_set_id' => $resolvedSetId,
                ':photo_library_id' => $photoLibraryId,
                ':rel_path' => $relPath,
                ':photo_type' => 'full',
                ':trigger_mode' => 'any',
                ':trigger_color_id' => null,
                ':show_in_gallery' => 0,
                ':use_palette_default_roles' => 1,
                ':caption' => $caption,
                ':alt_text' => $altText,
                ':order_index' => $orderIndex,
            ]);

            return (int)$this->pdo->lastInsertId();
        }

        $sql = "
            INSERT INTO saved_palette_photos
                (saved_palette_id, rel_path, photo_type, trigger_mode, trigger_color_id, caption, alt_text, order_index, created_at)
            VALUES
                (:saved_palette_id, :rel_path, :photo_type, :trigger_mode, :trigger_color_id, :caption, :alt_text, :order_index, NOW())
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':saved_palette_id' => $savedPaletteId,
            ':rel_path'         => $relPath,
            ':photo_type'       => 'full',
            ':trigger_mode'     => 'any',
            ':trigger_color_id' => null,
            ':caption'          => $caption,
            ':alt_text'         => $altText,
            ':order_index'      => $orderIndex,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Delete a single photo row.
     */
    public function deletePhoto(int $photoId): void
    {
        if ($this->usesPaletteSets()) {
            $sql = "DELETE FROM saved_palette_set_photos WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id' => $photoId]);
            return;
        }

        $sql = "DELETE FROM saved_palette_photos WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $photoId]);
    }

    /**
     * Update photo metadata, scoped to a palette.
     */
    public function updatePhoto(int $photoId, int $savedPaletteId, array $fields): void
    {
        if (empty($fields)) {
            return;
        }

        if ($this->usesPaletteSets()) {
            $allowed = [
                'photo_library_id',
                'rel_path',
                'photo_type',
                'trigger_mode',
                'trigger_color_id',
                'show_in_gallery',
                'use_palette_default_roles',
                'caption',
                'alt_text',
                'order_index',
            ];

            $setParts = [];
            $params = [
                ':id' => $photoId,
                ':saved_palette_id' => $savedPaletteId,
            ];

            foreach ($fields as $column => $value) {
                if (!in_array($column, $allowed, true)) {
                    continue;
                }

                $paramKey = ':' . $column;
                $setParts[] = "sp.{$column} = {$paramKey}";
                $params[$paramKey] = $value;
            }

            if (!$setParts) {
                return;
            }

            $sql = "
                UPDATE saved_palette_set_photos sp
                JOIN saved_palette_sets s
                  ON s.id = sp.saved_palette_set_id
                   SET " . implode(', ', $setParts) . ",
                       sp.updated_at = NOW()
                 WHERE sp.id = :id
                   AND s.saved_palette_id = :saved_palette_id
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return;
        }

        $allowed = [
            'rel_path',
            'photo_type',
            'trigger_mode',
            'trigger_color_id',
            'caption',
            'alt_text',
            'order_index',
        ];

        $setParts = [];
        $params = [
            ':id' => $photoId,
            ':saved_palette_id' => $savedPaletteId,
        ];

        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }

            $paramKey = ':' . $column;
            $setParts[] = "{$column} = {$paramKey}";
            $params[$paramKey] = $value;
        }

        if (!$setParts) {
            return;
        }

        $sql = "
            UPDATE saved_palette_photos
               SET " . implode(', ', $setParts) . "
             WHERE id = :id
               AND saved_palette_id = :saved_palette_id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Add a single member row.
     *
     * $orderIndex is used to preserve ordering in the palette.
     */
    public function addMember(int $savedPaletteId, int $colorId, int $orderIndex = 0): int
    {
        $sql = "
            INSERT INTO saved_palette_members
                (saved_palette_id, color_id, role_name, order_index, created_at)
            VALUES
                (:saved_palette_id, :color_id, :role_name, :order_index, NOW())
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':saved_palette_id' => $savedPaletteId,
            ':color_id'         => $colorId,
            ':role_name'        => null,
            ':order_index'      => $orderIndex,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Bulk insert members for a palette.
     *
     * $members is an array of ['color_id' => int, 'order_index' => int, 'role' => ?string].
     */
    public function addMembers(int $savedPaletteId, array $members): void
    {
        if (empty($members)) {
            return;
        }

        $sql = "
            INSERT INTO saved_palette_members
                (saved_palette_id, color_id, role_name, order_index, created_at)
            VALUES
                (:saved_palette_id, :color_id, :role_name, :order_index, NOW())
        ";

        $stmt = $this->pdo->prepare($sql);

        foreach ($members as $m) {
            if (!isset($m['color_id'])) {
                continue;
            }

            $stmt->execute([
                ':saved_palette_id' => $savedPaletteId,
                ':color_id'         => (int) $m['color_id'],
                ':role_name'        => isset($m['role']) && $m['role'] !== '' ? (string) $m['role'] : null,
                ':order_index'      => isset($m['order_index']) ? (int) $m['order_index'] : 0,
            ]);
        }
    }

    /**
     * Remove all members for a palette.
     */
    public function deleteMembersForPalette(int $savedPaletteId): void
    {
        $sql = "DELETE FROM saved_palette_members WHERE saved_palette_id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $savedPaletteId]);
    }

    /**
     * Replace all members for a palette with the given list (in a transaction).
     */
    public function replaceMembers(int $savedPaletteId, array $members): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->deleteMembersForPalette($savedPaletteId);
            $this->addMembers($savedPaletteId, $members);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Get all members for a palette, joined to colors.
     *
     * Returns an array ordered by order_index ascending.
     */
    public function getMembersForPalette(int $savedPaletteId): array
    {
        $sql = "
            SELECT m.id,
                   m.saved_palette_id,
                   m.color_id,
                   m.role_name AS role,
                   m.order_index,
                   c.name       AS color_name,
                   c.brand      AS color_brand,
                   c.brand_name AS color_brand_name,
                   c.code       AS color_code,
                   c.hex6       AS color_hex6,
                   c.hcl_h      AS color_hcl_h,
                   c.hcl_c      AS color_hcl_c,
                   c.hcl_l      AS color_hcl_l,
                   c.int_only   AS color_int_only,
                   c.chip_num   AS color_chip_num,
                   c.cluster_id AS color_cluster_id
              FROM saved_palette_members m
         LEFT JOIN swatch_view c
                ON c.id = m.color_id
             WHERE m.saved_palette_id = :id
          ORDER BY m.order_index ASC, m.id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $savedPaletteId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Convenience method: fetch palette + members together.
     */
    public function getFullPalette(int $id, ?int $setId = null): ?array
    {
        $palette = $this->getSavedPaletteById($id);
        if (!$palette) {
            return null;
        }

        $members = $this->getMembersForPalette($id);
        $photos = $this->getPhotosForPalette($id, $setId);

        return [
            'palette' => $palette,
            'members' => $members,
            'photos'  => $photos,
            'sets'    => $this->getSetsForPalette($id),
        ];
    }

    /**
     * Mark or unmark a palette as Terry's favorite.
     */
    public function setFavorite(int $id, bool $fav): void
    {
        $sql = "
            UPDATE saved_palettes
               SET terry_fav = :fav,
                   updated_at = NOW()
             WHERE id = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':fav' => (int) $fav,
            ':id'  => $id,
        ]);
    }

    /**
     * List saved palettes, optionally filtered by brand or favorite.
     *
     * $filters:
     *   - brand (string)
     *   - terry_fav (bool|int)
     *   - palette_type (string)
     */
    public function listPalettes(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['brand'])) {
            $where[] = 'p.brand = :brand';
            $params[':brand'] = $filters['brand'];
        }

        if (array_key_exists('terry_fav', $filters)) {
            $where[] = 'p.terry_fav = :terry_fav';
            $params[':terry_fav'] = (int) (bool) $filters['terry_fav'];
        }

        if (!empty($filters['palette_type'])) {
            $where[] = 'p.palette_type = :palette_type';
            $params[':palette_type'] = $filters['palette_type'];
        }

        if (!empty($filters['q'])) {
            $where[] = '('
                . 'p.nickname LIKE :q_nickname '
                . 'OR p.display_title LIKE :q_display_title '
                . 'OR p.notes LIKE :q_notes '
                . 'OR p.private_notes LIKE :q_private_notes '
                . 'OR p.palette_type LIKE :q_type'
                . ')';
            $likeValue = '%' . $filters['q'] . '%';
            $params[':q_nickname']    = $likeValue;
            $params[':q_display_title'] = $likeValue;
            $params[':q_notes']       = $likeValue;
            $params[':q_private_notes'] = $likeValue;
            $params[':q_type']        = $likeValue;
        }

        $sql = "SELECT p.*, k.display_text AS kicker_text
                FROM saved_palettes p
                LEFT JOIN kickers k ON k.kicker_id = p.kicker_id";

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY p.created_at DESC, p.id DESC';
        $sql .= ' LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Record a view of a saved palette.
     *
     * $viewerEmail may be null (anonymous).
     * $isOwner should be true when Terry (admin) is viewing,
     * so you can filter those out in stats.
     */
    public function recordView(
        int $savedPaletteId,
        ?string $viewerEmail,
        bool $isOwner,
        ?string $ipAddress,
        ?string $userAgent
    ): void {
        $sql = "
            INSERT INTO saved_palette_views
                (saved_palette_id, viewer_email, is_owner, ip_address, user_agent, created_at)
            VALUES
                (:saved_palette_id, :viewer_email, :is_owner, :ip_address, :user_agent, NOW())
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':saved_palette_id' => $savedPaletteId,
            ':viewer_email'     => $viewerEmail,
            ':is_owner'         => (int) $isOwner,
            ':ip_address'       => $ipAddress,
            ':user_agent'       => $userAgent,
        ]);
    }

    /**
     * Get simple aggregate view stats for a palette.
     *
     * Returns:
     *  [
     *      'total_views'      => int,
     *      'total_client_views' => int, // is_owner = 0
     *      'first_view'       => 'Y-m-d H:i:s' | null,
     *      'last_view'        => 'Y-m-d H:i:s' | null,
     *  ]
     */
    public function getViewStats(int $savedPaletteId): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total_views,
                SUM(CASE WHEN is_owner = 0 THEN 1 ELSE 0 END) AS total_client_views,
                MIN(created_at) AS first_view,
                MAX(created_at) AS last_view
            FROM saved_palette_views
            WHERE saved_palette_id = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $savedPaletteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return [
                'total_views'        => 0,
                'total_client_views' => 0,
                'first_view'         => null,
                'last_view'          => null,
            ];
        }

        return [
            'total_views'        => (int) ($row['total_views'] ?? 0),
            'total_client_views' => (int) ($row['total_client_views'] ?? 0),
            'first_view'         => $row['first_view'] ?? null,
            'last_view'          => $row['last_view'] ?? null,
        ];
    }

    public function paletteExists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM saved_palettes WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return (bool)$stmt->fetchColumn();
    }

    public function getSetsForPalette(int $savedPaletteId): array
    {
        if (!$this->usesPaletteSets()) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, saved_palette_id, slug, title, is_default, order_index, created_at, updated_at
               FROM saved_palette_sets
              WHERE saved_palette_id = :id
           ORDER BY is_default DESC, order_index ASC, id ASC"
        );
        $stmt->execute([':id' => $savedPaletteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getSetById(int $setId): ?array
    {
        if (!$this->usesPaletteSets() || $setId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, saved_palette_id, slug, title, is_default, order_index, created_at, updated_at
               FROM saved_palette_sets
              WHERE id = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $setId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function ensureSetForPalette(int $savedPaletteId, ?string $title = null, ?string $slug = null): int
    {
        if (!$this->usesPaletteSets() || $savedPaletteId <= 0) {
            return 0;
        }

        $normalizedTitle = trim((string)($title ?? ''));
        $normalizedSlug = trim((string)($slug ?? ''));
        if ($normalizedSlug === '' && $normalizedTitle !== '') {
            $normalizedSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $normalizedTitle), '-'));
        }
        if ($normalizedSlug === '') {
            return $this->resolveSetIdForPalette($savedPaletteId, null, true);
        }

        $stmt = $this->pdo->prepare(
            "SELECT id
               FROM saved_palette_sets
              WHERE saved_palette_id = :palette_id
                AND slug = :slug
              LIMIT 1"
        );
        $stmt->execute([
            ':palette_id' => $savedPaletteId,
            ':slug' => $normalizedSlug,
        ]);
        $existingId = $stmt->fetchColumn();
        if ($existingId) {
            return (int)$existingId;
        }

        $titleValue = $normalizedTitle !== '' ? $normalizedTitle : ucwords(str_replace('-', ' ', $normalizedSlug));
        $orderStmt = $this->pdo->prepare(
            "SELECT COALESCE(MAX(order_index), 0) + 1
               FROM saved_palette_sets
              WHERE saved_palette_id = :palette_id"
        );
        $orderStmt->execute([':palette_id' => $savedPaletteId]);
        $nextOrder = (int)$orderStmt->fetchColumn();
        $stmt = $this->pdo->prepare(
            "INSERT INTO saved_palette_sets
                (saved_palette_id, slug, title, is_default, order_index, created_at, updated_at)
             VALUES
                (:palette_id, :slug, :title, 0, :order_index, NOW(), NOW())"
        );
        $stmt->execute([
            ':palette_id' => $savedPaletteId,
            ':slug' => $normalizedSlug,
            ':title' => $titleValue,
            ':order_index' => $nextOrder,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function createAutoSetForPalette(int $savedPaletteId): int
    {
        if (!$this->usesPaletteSets() || $savedPaletteId <= 0) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) + 1
               FROM saved_palette_sets
              WHERE saved_palette_id = :palette_id"
        );
        $stmt->execute([':palette_id' => $savedPaletteId]);
        $nextNumber = max(2, (int)$stmt->fetchColumn());

        return $this->ensureSetForPalette(
            $savedPaletteId,
            sprintf('Group %d', $nextNumber),
            sprintf('group-%d', $nextNumber)
        );
    }

    public function syncRelPathFromPhotoLibrary(int $photoLibraryId, string $relPath): void
    {
        if (!$this->usesPaletteSets() || $photoLibraryId <= 0 || trim($relPath) === '') {
            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE saved_palette_set_photos
                SET rel_path = :rel_path,
                    updated_at = NOW()
              WHERE photo_library_id = :photo_library_id"
        );
        $stmt->execute([
            ':rel_path' => $relPath,
            ':photo_library_id' => $photoLibraryId,
        ]);
    }

    public function getFullPaletteByHashAndSet(string $hash, ?int $setId = null): ?array
    {
        $palette = $this->getSavedPaletteByHash($hash);
        if ($palette === null) {
            return null;
        }

        return $this->getFullPalette((int)$palette['id'], $setId);
    }

    private function usesPaletteSets(): bool
    {
        if ($this->hasSetTables !== null) {
            return $this->hasSetTables;
        }

        try {
            $hasSets = (bool)$this->pdo->query("SHOW TABLES LIKE 'saved_palette_sets'")?->fetchColumn();
            $hasSetPhotos = (bool)$this->pdo->query("SHOW TABLES LIKE 'saved_palette_set_photos'")?->fetchColumn();
            $this->hasSetTables = $hasSets && $hasSetPhotos;
        } catch (\Throwable) {
            $this->hasSetTables = false;
        }

        return $this->hasSetTables;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name'
        );
        $stmt->execute([':table_name' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name
                AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([':table_name' => $table, ':column_name' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function resolveSetIdForPalette(int $savedPaletteId, ?int $setId = null, bool $createIfMissing = false): int
    {
        if (!$this->usesPaletteSets() || $savedPaletteId <= 0) {
            return 0;
        }

        if ($setId !== null && $setId > 0) {
            $stmt = $this->pdo->prepare(
                "SELECT id
                   FROM saved_palette_sets
                  WHERE id = :set_id
                    AND saved_palette_id = :palette_id
                  LIMIT 1"
            );
            $stmt->execute([
                ':set_id' => $setId,
                ':palette_id' => $savedPaletteId,
            ]);
            $found = $stmt->fetchColumn();
            if ($found) {
                return (int)$found;
            }
        }

        $stmt = $this->pdo->prepare(
            "SELECT id
               FROM saved_palette_sets
              WHERE saved_palette_id = :palette_id
           ORDER BY is_default DESC, order_index ASC, id ASC
              LIMIT 1"
        );
        $stmt->execute([':palette_id' => $savedPaletteId]);
        $found = $stmt->fetchColumn();
        if ($found) {
            return (int)$found;
        }

        if (!$createIfMissing) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO saved_palette_sets
                (saved_palette_id, slug, title, is_default, order_index, created_at, updated_at)
             VALUES
                (:palette_id, 'primary', 'Primary Set', 1, 0, NOW(), NOW())"
        );
        $stmt->execute([':palette_id' => $savedPaletteId]);
        return (int)$this->pdo->lastInsertId();
    }
}
