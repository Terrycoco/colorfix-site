<?php
declare(strict_types=1);

namespace App\PALETTES\Repos;

use PDO;


/**
 * Canonical repository for Saved Palettes.
 *
 * Owns ONLY:
 *   - saved_palettes
 *   - saved_palette_members
 *
 * Reads swatch_view only to enrich member/color data and support filtering.
 *
 * It intentionally does NOT know about:
 *   - PVs / palette_viewers
 *   - photos / photo_library
 *   - saved_palette_sets
 *   - saved_palette_set_photos
 *   - saved_palette_photos
 *   - saved_palette_viewer_content
 *   - saved_palette_views / analytics
 *   - playlists / playlist_instances
 *   - REX
 */
final class PdoSavedPaletteRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function createSavedPalette(array $data): int
    {
        $sql = "
            INSERT INTO saved_palettes
                (
                    palette_hash,
                    brand,
                    nickname,
                    display_title,
                    notes,
                    private_notes,
                    terry_fav,
                    kicker_id,
                    palette_type,
                    created_at
                )
            VALUES
                (
                    :palette_hash,
                    :brand,
                    :nickname,
                    :display_title,
                    :notes,
                    :private_notes,
                    :terry_fav,
                    :kicker_id,
                    :palette_type,
                    NOW()
                )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':palette_hash'  => $data['palette_hash'] ?? null,
            ':brand'         => $data['brand'] ?? null,
            ':nickname'      => $data['nickname'] ?? null,
            ':display_title' => $data['display_title'] ?? null,
            ':notes'         => $data['notes'] ?? null,
            ':private_notes' => $data['private_notes'] ?? null,
            ':terry_fav'     => isset($data['terry_fav'])
                ? (int)(bool)$data['terry_fav']
                : 0,
            ':kicker_id'     => isset($data['kicker_id']) && (int)$data['kicker_id'] > 0
                ? (int)$data['kicker_id']
                : null,
            ':palette_type'  => $data['palette_type'] ?? null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function updateSavedPalette(int $id, array $fields): void
    {
        if ($id <= 0 || $fields === []) {
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
        $params = [':id' => $id];

        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }

            if ($column === 'terry_fav') {
                $value = (int)(bool)$value;
            }

            if ($column === 'kicker_id') {
                $value = $value !== null && (int)$value > 0
                    ? (int)$value
                    : null;
            }

            $param = ':' . $column;
            $setParts[] = "{$column} = {$param}";
            $params[$param] = $value;
        }

        if ($setParts === []) {
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

    public function getSavedPaletteById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT *
               FROM saved_palettes
              WHERE id = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function getSavedPaletteByHash(string $hash): ?array
    {
        $hash = trim($hash);
        if ($hash === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT *
               FROM saved_palettes
              WHERE palette_hash = :hash
              LIMIT 1"
        );
        $stmt->execute([':hash' => $hash]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function getSavedPaletteByHashAndBrand(string $hash, string $brand): ?array
    {
        $hash = trim($hash);
        $brand = trim($brand);

        if ($hash === '' || $brand === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT *
               FROM saved_palettes
              WHERE palette_hash = :hash
                AND brand = :brand
              LIMIT 1"
        );
        $stmt->execute([
            ':hash' => $hash,
            ':brand' => $brand,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function paletteExists(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM saved_palettes WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        return (bool)$stmt->fetchColumn();
    }

    public function deleteSavedPalette(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM saved_palettes WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    public function getMembersForPalette(int $savedPaletteId): array
    {
        if ($savedPaletteId <= 0) {
            return [];
        }

        $sql = "
            SELECT
                m.id,
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
                c.cluster_id AS color_cluster_id,
                c.hue_cats   AS color_hue_cats,
                c.neutral_cats AS color_neutral_cats

            FROM saved_palette_members m

            LEFT JOIN swatch_view c
              ON c.id = m.color_id

            WHERE m.saved_palette_id = :id

            ORDER BY
                m.order_index ASC,
                m.id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $savedPaletteId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function addMember(
        int $savedPaletteId,
        int $colorId,
        int $orderIndex = 0,
        ?string $role = null,
        ?string $sheen = null,
        ?string $note = null
    ): int {
        $sql = "
            INSERT INTO saved_palette_members
                (
                    saved_palette_id,
                    color_id,
                    role_name,
                    sheen,
                    note,
                    order_index,
                    created_at
                )
            VALUES
                (
                    :saved_palette_id,
                    :color_id,
                    :role_name,
                    :sheen,
                    :note,
                    :order_index,
                    NOW()
                )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':saved_palette_id' => $savedPaletteId,
            ':color_id' => $colorId,
            ':role_name' => $this->nullableText($role),
            ':sheen' => $this->nullableText($sheen),
            ':note' => $this->nullableText($note),
            ':order_index' => $orderIndex,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Expected member shape:
     * [
     *   'color_id' => int,
     *   'role' => ?string,
     *   'sheen' => ?string,
     *   'note' => ?string,
     *   'order_index' => int,
     * ]
     */
    public function addMembers(int $savedPaletteId, array $members): void
    {
        if ($savedPaletteId <= 0 || $members === []) {
            return;
        }

        $sql = "
            INSERT INTO saved_palette_members
                (
                    saved_palette_id,
                    color_id,
                    role_name,
                    sheen,
                    note,
                    order_index,
                    created_at
                )
            VALUES
                (
                    :saved_palette_id,
                    :color_id,
                    :role_name,
                    :sheen,
                    :note,
                    :order_index,
                    NOW()
                )
        ";

        $stmt = $this->pdo->prepare($sql);

        foreach ($members as $member) {
            $colorId = (int)($member['color_id'] ?? 0);
            if ($colorId <= 0) {
                continue;
            }

            $stmt->execute([
                ':saved_palette_id' => $savedPaletteId,
                ':color_id' => $colorId,
                ':role_name' => $this->nullableText($member['role'] ?? null),
                ':sheen' => $this->nullableText($member['sheen'] ?? null),
                ':note' => $this->nullableText($member['note'] ?? null),
                ':order_index' => (int)($member['order_index'] ?? 0),
            ]);
        }
    }

    public function deleteMembersForPalette(int $savedPaletteId): void
    {
        if ($savedPaletteId <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM saved_palette_members WHERE saved_palette_id = :id'
        );
        $stmt->execute([':id' => $savedPaletteId]);
    }

public function replaceMembers(int $savedPaletteId, array $members): void
{
    if ($savedPaletteId <= 0) {
        return;
    }

    $this->deleteMembersForPalette($savedPaletteId);
    $this->addMembers($savedPaletteId, $members);
}

    /**
     * Canonical Saved Palette payload: palette + members only.
     * PVs/photos/REX are intentionally outside this repository.
     */
    public function getFullPalette(int $id): ?array
    {
        $palette = $this->getSavedPaletteById($id);
        if (!$palette) {
            return null;
        }

        return [
            'palette' => $palette,
            'members' => $this->getMembersForPalette($id),
        ];
    }

    public function setFavorite(int $id, bool $favorite): void
    {
        if ($id <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE saved_palettes
                SET terry_fav = :favorite,
                    updated_at = NOW()
              WHERE id = :id"
        );
        $stmt->execute([
            ':favorite' => (int)$favorite,
            ':id' => $id,
        ]);
    }

    /**
     * Supported filters:
     *   - brand
     *   - terry_fav
     *   - palette_type
     *   - color_family
     *   - q
     */
    public function listPalettes(
        array $filters = [],
        int $limit = 50,
        int $offset = 0
    ): array {
        $where = [];
        $params = [];

        if (!empty($filters['brand'])) {
            $where[] = 'p.brand = :brand';
            $params[':brand'] = (string)$filters['brand'];
        }

        if (array_key_exists('terry_fav', $filters)) {
            $where[] = 'p.terry_fav = :terry_fav';
            $params[':terry_fav'] = (int)(bool)$filters['terry_fav'];
        }

        if (!empty($filters['palette_type'])) {
            $where[] = 'p.palette_type = :palette_type';
            $params[':palette_type'] = (string)$filters['palette_type'];
        }

        if (!empty($filters['color_family'])) {
            $familyValue = strtolower(trim((string)$filters['color_family']));
            $familySingular = preg_replace('/s$/', '', $familyValue);

            $isNeutralFamily = in_array(
                $familySingular,
                ['white', 'black', 'gray', 'grey', 'greige', 'beige', 'brown'],
                true
            );

            $familyClause = $isNeutralFamily
                ? "family_color.neutral_cats LIKE :color_family_neutral"
                : "family_color.hue_cats LIKE :color_family_hue
                   AND NULLIF(TRIM(COALESCE(family_color.neutral_cats, '')), '') IS NULL";

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

            $familyLike = '%' . (string)$filters['color_family'] . '%';

            if ($isNeutralFamily) {
                $params[':color_family_neutral'] = $familyLike;
            } else {
                $params[':color_family_hue'] = $familyLike;
            }
        }

        if (!empty($filters['q'])) {
            $where[] = '(
                p.nickname LIKE :q_nickname
                OR p.display_title LIKE :q_display_title
                OR p.notes LIKE :q_notes
                OR p.private_notes LIKE :q_private_notes
                OR p.palette_type LIKE :q_type
            )';

            $like = '%' . (string)$filters['q'] . '%';
            $params[':q_nickname'] = $like;
            $params[':q_display_title'] = $like;
            $params[':q_notes'] = $like;
            $params[':q_private_notes'] = $like;
            $params[':q_type'] = $like;
        }

        $sql = "
            SELECT
                p.*,
                k.display_text AS kicker_text
              FROM saved_palettes p
         LEFT JOIN kickers k
                ON k.kicker_id = p.kicker_id
        ";

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY p.created_at DESC, p.id DESC';
        $sql .= ' LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getKickerText(int $kickerId): ?string
    {
        if ($kickerId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT display_text FROM kickers WHERE kicker_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $kickerId]);

        $value = $stmt->fetchColumn();
        return $value !== false ? (string)$value : null;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text === '' ? null : $text;
    }
}
