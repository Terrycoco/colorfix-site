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

    /**
     * Create a new saved palette row.
     *
     * Expected keys in $data:
     *  - palette_hash (string, optional but recommended)
     *  - brand        (string, e.g. 'de', 'behr')
     *  - nickname     (string|null)
     *  - notes        (string|null)
     *  - terry_fav    (bool|int|null)
     *  - sent_to_email(string|null)
     *  - sent_at      (string|null, 'Y-m-d H:i:s')
     */
    public function createSavedPalette(array $data): int
    {
        $sql = "
            INSERT INTO saved_palettes
                (palette_hash, brand, client_id, nickname, notes, terry_fav, sent_to_email, sent_at, created_at)
            VALUES
                (:palette_hash, :brand, :client_id, :nickname, :notes, :terry_fav, :sent_to_email, :sent_at, NOW())
        ";

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            ':palette_hash'  => $data['palette_hash'] ?? null,
            ':brand'         => $data['brand'],
            ':client_id'     => $data['client_id'] ?? null,
            ':nickname'      => $data['nickname'] ?? null,
            ':notes'         => $data['notes'] ?? null,
            ':terry_fav'     => isset($data['terry_fav']) ? (int) (bool) $data['terry_fav'] : 0,
            ':sent_to_email' => $data['sent_to_email'] ?? null,
            ':sent_at'       => $data['sent_at'] ?? null,
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
            'client_id',
            'nickname',
            'notes',
            'terry_fav',
            'sent_to_email',
            'sent_at',
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

    public function getFullPaletteByHash(string $hash): ?array
    {
        $palette = $this->getSavedPaletteByHash($hash);
        if ($palette === null) {
            return null;
        }

        $members = $this->getMembersForPalette((int)$palette['id']);

        return [
            'palette' => $palette,
            'members' => $members,
        ];
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
     * Add a single member row.
     *
     * $orderIndex is used to preserve ordering in the palette.
     */
    public function addMember(int $savedPaletteId, int $colorId, int $orderIndex = 0): int
    {
        $sql = "
            INSERT INTO saved_palette_members
                (saved_palette_id, color_id, order_index, created_at)
            VALUES
                (:saved_palette_id, :color_id, :order_index, NOW())
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':saved_palette_id' => $savedPaletteId,
            ':color_id'         => $colorId,
            ':order_index'      => $orderIndex,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Bulk insert members for a palette.
     *
     * $members is an array of ['color_id' => int, 'order_index' => int].
     */
    public function addMembers(int $savedPaletteId, array $members): void
    {
        if (empty($members)) {
            return;
        }

        $sql = "
            INSERT INTO saved_palette_members
                (saved_palette_id, color_id, order_index, created_at)
            VALUES
                (:saved_palette_id, :color_id, :order_index, NOW())
        ";

        $stmt = $this->pdo->prepare($sql);

        foreach ($members as $m) {
            if (!isset($m['color_id'])) {
                continue;
            }

            $stmt->execute([
                ':saved_palette_id' => $savedPaletteId,
                ':color_id'         => (int) $m['color_id'],
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
                   m.order_index,
                   c.name       AS color_name,
                   c.brand      AS color_brand,
                   c.code       AS color_code,
                   c.hex6       AS color_hex6,
                   c.hcl_h      AS color_hcl_h,
                   c.hcl_c      AS color_hcl_c,
                   c.hcl_l      AS color_hcl_l,
                   c.chip_num   AS color_chip_num,
                   c.cluster_id AS color_cluster_id
              FROM saved_palette_members m
         LEFT JOIN colors c
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
    public function getFullPalette(int $id): ?array
    {
        $palette = $this->getSavedPaletteById($id);
        if (!$palette) {
            return null;
        }

        $members = $this->getMembersForPalette($id);

        return [
            'palette' => $palette,
            'members' => $members,
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

        if (!empty($filters['q'])) {
            $where[] = '('
                . 'p.nickname LIKE :q_nickname '
                . 'OR p.notes LIKE :q_notes '
                . 'OR p.sent_to_email LIKE :q_email '
                . 'OR c.name LIKE :q_client_name '
                . 'OR c.email LIKE :q_client_email'
                . ')';
            $likeValue = '%' . $filters['q'] . '%';
            $params[':q_nickname']    = $likeValue;
            $params[':q_notes']       = $likeValue;
            $params[':q_email']       = $likeValue;
            $params[':q_client_name'] = $likeValue;
            $params[':q_client_email']= $likeValue;
        }

        $sql = "SELECT p.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone
                FROM saved_palettes p
                LEFT JOIN clients c ON c.id = p.client_id";

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
     * Find all saved palettes sent to a given email.
     */
    public function findPalettesByEmail(string $email): array
    {
        $sql = "
            SELECT *
              FROM saved_palettes
             WHERE sent_to_email = :email
          ORDER BY created_at DESC, id DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email' => $email]);

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
}
