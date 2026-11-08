<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;
use PDOException;

class PdoSavedPaletteRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function connection(): PDO { return $this->pdo; }

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

    public function getViewerContentForPalette(int $savedPaletteId): array
    {
        return array_map(static fn($viewer): array => [
            'palette_viewer_id'=>$viewer->paletteViewerId, 'saved_palette_id'=>$viewer->savedPaletteId,
            'template_key'=>$viewer->templateKey, 'format'=>$viewer->format, 'is_active'=>(int)$viewer->isActive,
            'kicker_text'=>$viewer->kickerText,'title'=>$viewer->title,'intro'=>$viewer->intro,
            'notes'=>$viewer->notes,'cta_label'=>$viewer->ctaLabel,
        ], (new \App\Repos\PdoPaletteViewerRepository($this->pdo))->findBySavedPaletteId($savedPaletteId));
    }

    public function getFullPaletteByHash(string $hash): ?array
    {
        $palette=$this->getSavedPaletteByHash($hash);
        return $palette ? $this->getFullPalette((int)$palette['id']) : null;
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
        $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT pi.playlist_item_id)
            FROM playlist_items pi JOIN saved_palettes sp ON sp.id = ?
            LEFT JOIN photo_library pl ON pl.photo_library_id=pi.photo_library_id
            WHERE pi.palette_hash=sp.palette_hash OR pl.palette_id=sp.id");
        $stmt->execute([$savedPaletteId]);
        return (int)$stmt->fetchColumn();
    }

    public function getPlaylistUsesForPaletteIds(array $savedPaletteIds): array
    {
        $savedPaletteIds = array_values(array_unique(array_filter(array_map('intval', $savedPaletteIds), static fn(int $id): bool => $id > 0)));
        if ($savedPaletteIds === [] || !$this->tableExists('playlist_items') || !$this->tableExists('playlist_instances')) {
            return [];
        }

        $playlistItemHasIsActive = $this->columnExists('playlist_items', 'is_active');
        $instanceHasIsActive = $this->columnExists('playlist_instances', 'is_active');
        $playlistItemHasAnalyzerRole = $this->columnExists('playlist_items', 'analyzer_role');
        $playlistItemHasFinderStart = $this->columnExists('playlist_items', 'finder_start');
        $playlistItemHasItemType = $this->columnExists('playlist_items', 'item_type');
        $matches = ["(sp.palette_hash IS NOT NULL AND sp.palette_hash <> '' AND pi.palette_hash=sp.palette_hash)",
            "(linked_photo.photo_library_id IS NOT NULL AND pi.photo_library_id=linked_photo.photo_library_id)"];
        $params = [];
        $placeholders = [];
        foreach ($savedPaletteIds as $index => $id) {
            $key = ':id' . $index;
            $placeholders[] = $key;
            $params[$key] = $id;
        }

        $joins = ['LEFT JOIN photo_library linked_photo ON linked_photo.palette_id=sp.id'];
        $itemActiveClause = $playlistItemHasIsActive ? 'AND pi.is_active = 1' : '';
        $instanceActiveClause = $instanceHasIsActive ? 'AND inst.is_active = 1' : '';
        $analyzerRoleSelect = $playlistItemHasAnalyzerRole ? 'pi.analyzer_role' : 'NULL AS analyzer_role';
        $finderStartSelect = $playlistItemHasFinderStart ? 'pi.finder_start' : "'auto' AS finder_start";
        $itemTypeSelect = $playlistItemHasItemType ? 'pi.item_type' : 'NULL AS item_type';
        $photoTypeSelect = "NULL AS photo_type";

        $sql = "
            SELECT
                sp.id AS saved_palette_id,
                pi.playlist_item_id,
                pi.playlist_id,
                pi.order_index,
                pi.title AS slide_title,
                {$analyzerRoleSelect},
                {$finderStartSelect},
                {$itemTypeSelect},
                {$photoTypeSelect},
                inst.playlist_instance_id,
                inst.instance_name,
                inst.display_title AS instance_display_title,
                p.title AS playlist_title
              FROM saved_palettes sp
              " . implode("\n              ", $joins) . "
              JOIN playlist_items pi
                ON (" . implode(' OR ', $matches) . ")
               {$itemActiveClause}
              JOIN playlist_instances inst
                ON inst.playlist_id = pi.playlist_id
               {$instanceActiveClause}
              LEFT JOIN playlists p
                ON p.playlist_id = pi.playlist_id
             WHERE sp.id IN (" . implode(',', $placeholders) . ")
             ORDER BY sp.id, inst.playlist_instance_id, pi.order_index, pi.playlist_item_id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $grouped = array_fill_keys($savedPaletteIds, []);
        $bestByInstance = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $paletteId = (int)($row['saved_palette_id'] ?? 0);
            $instanceId = (int)($row['playlist_instance_id'] ?? 0);
            $itemId = (int)($row['playlist_item_id'] ?? 0);
            if ($paletteId <= 0 || $instanceId <= 0 || $itemId <= 0) {
                continue;
            }

            $roleText = strtolower(trim(implode(' ', array_filter([
                (string)($row['analyzer_role'] ?? ''),
                (string)($row['photo_type'] ?? ''),
                (string)($row['item_type'] ?? ''),
                (string)($row['slide_title'] ?? ''),
            ]))));
            $isAfter = preg_match('/\bafter\b/', $roleText) === 1;
            $finderStart = strtolower(trim((string)($row['finder_start'] ?? 'auto')));
            if (!in_array($finderStart, ['auto', 'this', 'previous'], true)) {
                $finderStart = 'auto';
            }
            $offset = match ($finderStart) {
                'this' => 0,
                'previous' => -1,
                default => $isAfter ? -1 : 0,
            };
            $orderIndex = (int)($row['order_index'] ?? 0);
            $label = trim((string)($row['instance_display_title'] ?? ''))
                ?: trim((string)($row['instance_name'] ?? ''))
                ?: trim((string)($row['playlist_title'] ?? ''))
                ?: ('Playlist #' . $instanceId);

            $entry = [
                'playlist_instance_id' => $instanceId,
                'playlist_id' => (int)($row['playlist_id'] ?? 0),
                'playlist_item_id' => $itemId,
                'slide_title' => (string)($row['slide_title'] ?? ''),
                'label' => $label,
                'offset' => $offset,
                'finder_start' => $finderStart,
                'is_after' => $isAfter,
                'order_index' => $orderIndex,
                'player_url' => '/playlist/' . rawurlencode((string)$instanceId) . '?slide_id=' . rawurlencode((string)$itemId) . '&offset=' . rawurlencode((string)$offset),
            ];

            $key = $paletteId . ':' . $instanceId;
            $existing = $bestByInstance[$key] ?? null;
            if (
                $existing === null
                || ($entry['is_after'] && !$existing['is_after'])
                || ($entry['is_after'] === $existing['is_after'] && $entry['order_index'] < $existing['order_index'])
            ) {
                $bestByInstance[$key] = $entry;
            }
        }

        foreach ($bestByInstance as $key => $entry) {
            [$paletteId] = explode(':', $key, 2);
            $grouped[(int)$paletteId][] = $entry;
        }

        foreach ($grouped as &$entries) {
            usort($entries, static fn(array $a, array $b): int => strcasecmp((string)$a['label'], (string)$b['label']));
        }
        unset($entries);

        return $grouped;
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
     * Detach a palette's MAIN associations through PHOTOS; retain Photo Library assets.
     */
    public function deletePhotosForPalette(int $savedPaletteId): void
    {
        (new \App\PHOTOS\Repos\PdoPhotoLibraryRepository($this->pdo))->detachPalettePhotos($savedPaletteId);
    }

    /**
     * Fetch photos for a palette.
     */
    public function getPhotosForPalette(int $savedPaletteId, ?int $setId = null): array
    {
        return (new \App\PHOTOS\Repos\PdoPhotoLibraryRepository($this->pdo))->listForPalette($savedPaletteId);
    }

    /**
     * Describe the current direct association for a Photo Library asset.
     */
    public function listLinksByPhotoLibraryId(int $photoLibraryId): array
    {
        return (new \App\PHOTOS\Repos\PdoPhotoLibraryRepository($this->pdo))->listPaletteLinks($photoLibraryId);
    }

    /**
     * Update photo metadata, scoped to a palette.
     */
    public function updatePhoto(int $photoId, int $savedPaletteId, array $fields): void
    {
        $repo = new \App\PHOTOS\Repos\PdoPhotoLibraryRepository($this->pdo);
        $photo = $repo->findById($photoId);
        if (!$photo || (int)($photo['palette_id'] ?? 0) !== $savedPaletteId) {
            throw new \InvalidArgumentException('Photo does not belong to this palette');
        }
        $metadata = [];
        if (array_key_exists('alt_text', $fields)) { $metadata['alt_text'] = $fields['alt_text']; }
        if (array_key_exists('caption', $fields) && $fields['caption'] !== null) { $metadata['title'] = $fields['caption']; }
        $repo->update($photoId, $metadata);
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
     * $members is an array of ['color_id' => int, 'order_index' => int, 'role' => ?string, 'sheen' => ?string, 'note' => ?string].
     */
    public function addMembers(int $savedPaletteId, array $members): void
    {
        if (empty($members)) {
            return;
        }

        $sql = "
            INSERT INTO saved_palette_members
                (saved_palette_id, color_id, role_name, sheen, note, order_index, created_at)
            VALUES
                (:saved_palette_id, :color_id, :role_name, :sheen, :note, :order_index, NOW())
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
                ':sheen'            => isset($m['sheen']) && $m['sheen'] !== '' ? (string) $m['sheen'] : null,
                ':note'             => isset($m['note']) && $m['note'] !== '' ? (string) $m['note'] : null,
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
    public function hasMembersForPalette(int $savedPaletteId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM saved_palette_members WHERE saved_palette_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $savedPaletteId]);

        return $stmt->fetchColumn() !== false;
    }

    public function getMembersForPalette(int $savedPaletteId): array
    {
        $sql = "
            SELECT m.id,
                   m.saved_palette_id,
                   m.color_id,
                   m.role_name AS role,
                   m.sheen,
                   m.note,
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
            'sets'    => [],
            'viewer_content' => $this->getViewerContentForPalette($id),
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
     *   - color_family (string)
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

        if (!empty($filters['color_family'])) {
            $familyValue = strtolower(trim((string)$filters['color_family']));
            $familySingular = preg_replace('/s$/', '', $familyValue);
            $isNeutralFamily = in_array($familySingular, ['white', 'black', 'gray', 'grey', 'greige', 'beige', 'brown'], true);
            $familyClause = $isNeutralFamily
                ? "family_color.neutral_cats LIKE :color_family_neutral"
                : "family_color.hue_cats LIKE :color_family_hue AND NULLIF(TRIM(COALESCE(family_color.neutral_cats, '')), '') IS NULL";
            $where[] = "
                EXISTS (
                    SELECT 1
                      FROM saved_palette_members family_members
                      JOIN swatch_view family_color
                        ON family_color.id = family_members.color_id
                     WHERE family_members.saved_palette_id = p.id
                       AND {$familyClause}
                )
            ";
            $familyLike = '%' . $filters['color_family'] . '%';
            if ($isNeutralFamily) {
                $params[':color_family_neutral'] = $familyLike;
            } else {
                $params[':color_family_hue'] = $familyLike;
            }
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

}
