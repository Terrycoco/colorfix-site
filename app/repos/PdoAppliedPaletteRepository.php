<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;
use App\Entities\AppliedPalette;

class PdoAppliedPaletteRepository
{
    public function __construct(private PDO $pdo) {}

    public function findById(int $id): ?AppliedPalette
    {
        $stmt = $this->pdo->prepare("SELECT * FROM applied_palettes WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $entries = $this->fetchEntries($id);
        return new AppliedPalette(
            (int)$row['id'],
            $row['title'] ?? null,
            $row['display_title'] ?? null,
            $row['notes'] ?? null,
            $row['tags'] ?? null,
            (int)$row['photo_id'],
            (string)$row['asset_id'],
            $entries
        );
    }

    public function listPalettes(array $filters, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['asset_id'])) {
            $where[] = "ap.asset_id = :asset_id";
            $params[':asset_id'] = $filters['asset_id'];
        }
        if (!empty($filters['q'])) {
            $pattern = '%' . $filters['q'] . '%';
            $where[] = "(ap.title LIKE :q_title OR ap.display_title LIKE :q_display OR ap.asset_id LIKE :q_asset)";
            $params[':q_title'] = $pattern;
            $params[':q_display'] = $pattern;
            $params[':q_asset'] = $pattern;
            if (ctype_digit($filters['q'])) {
                $where[] = "ap.id = :q_id";
                $params[':q_id'] = (int)$filters['q'];
            }
        }
        $cond = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $sql = "SELECT ap.*,
                       COUNT(sh.id) AS share_count,
                       SUM(
                           CASE WHEN ape.mask_setting_id IS NOT NULL AND mbs.updated_at IS NOT NULL
                                     AND (ap.updated_at IS NULL OR mbs.updated_at > ap.updated_at)
                                THEN 1 ELSE 0 END
                       ) AS stale_entries,
                       COUNT(ape.id) AS total_entries
                FROM applied_palettes ap
                LEFT JOIN applied_palette_shares sh ON sh.applied_palette_id = ap.id
                LEFT JOIN applied_palette_entries ape ON ape.applied_palette_id = ap.id
                LEFT JOIN mask_blend_settings mbs ON mbs.id = ape.mask_setting_id
                {$cond}
                GROUP BY ap.id
                ORDER BY ap.created_at DESC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function recordShare(int $paletteId, int $clientId, array $data): int
    {
        $sql = "INSERT INTO applied_palette_shares
                (applied_palette_id, client_id, channel, target_phone, target_email, note, share_url, created_at)
                VALUES (:palette_id, :client_id, :channel, :phone, :email, :note, :url, NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':palette_id' => $paletteId,
            ':client_id' => $clientId,
            ':channel' => $data['channel'] ?? 'sms',
            ':phone' => $data['target_phone'] ?? null,
            ':email' => $data['target_email'] ?? null,
            ':note' => $data['note'] ?? null,
            ':url' => $data['share_url'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function fetchEntries(int $paletteId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT e.mask_role, e.color_id,
                   e.mask_setting_id, e.mask_setting_revision,
                   -- legacy fields kept for compatibility but prefer mask settings
                   e.blend_mode, e.blend_opacity,
                   e.lightness_offset, e.tint_hex, e.tint_opacity,
                   c.name AS color_name, c.code AS color_code, c.brand AS color_brand, c.hex6 AS color_hex6,
                   c.hcl_l AS color_hcl_l, c.lab_l AS color_lab_l,
                   mbs.blend_mode AS setting_blend_mode,
                   mbs.blend_opacity AS setting_blend_opacity,
                   mbs.shadow_l_offset AS setting_shadow_l_offset,
                   mbs.shadow_tint_hex AS setting_shadow_tint_hex,
                   mbs.shadow_tint_opacity AS setting_shadow_tint_opacity,
                   mbs.target_lightness AS setting_target_lightness,
                   mbs.target_h AS setting_target_h,
                   mbs.target_c AS setting_target_c,
                   mbs.is_preset AS setting_is_preset,
                   mbs.updated_at AS setting_updated_at
            FROM applied_palette_entries e
            LEFT JOIN colors c ON c.id = e.color_id
            LEFT JOIN mask_blend_settings mbs ON mbs.id = e.mask_setting_id
            WHERE e.applied_palette_id = :pid
            ORDER BY e.id ASC
        ");
        $stmt->execute([':pid' => $paletteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Inserts a palette row and returns the primary key.
     *
     * @param array{
     *   photo_id:int,
     *   asset_id:string,
     *   title?:string|null,
     *   display_title?:string|null,
     *   notes?:string|null
     * } $data
     * @return array{id:int}
     */
    public function insertPalette(array $data): array
    {
        $sql = "INSERT INTO applied_palettes
                (photo_id, asset_id, title, display_title, notes, tags, created_at, updated_at)
                VALUES (:photo_id, :asset_id, :title, :display_title, :notes, :tags, NOW(), NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':photo_id' => $data['photo_id'],
            ':asset_id' => $data['asset_id'],
            ':title' => $data['title'] ?? null,
            ':display_title' => $data['display_title'] ?? null,
            ':notes' => $data['notes'] ?? null,
            ':tags' => $data['tags'] ?? null,
        ]);
        return [
            'id' => (int)$this->pdo->lastInsertId(),
        ];
    }

    public function insertPaletteEntry(int $paletteId, array $data): int
    {
        $sql = "INSERT INTO applied_palette_entries
                (applied_palette_id, mask_role, color_id, mask_setting_id, mask_setting_revision,
                 blend_mode, blend_opacity,
                 lightness_offset, tint_hex, tint_opacity, created_at)
                VALUES
                (:applied_palette_id, :mask_role, :color_id, :mask_setting_id, :mask_setting_revision,
                 :blend_mode, :blend_opacity,
                 :lightness_offset, :tint_hex, :tint_opacity, NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':applied_palette_id' => $paletteId,
            ':mask_role' => $data['mask_role'],
            ':color_id' => $data['color_id'],
            ':mask_setting_id' => $data['mask_setting_id'] ?? null,
            ':mask_setting_revision' => $data['mask_setting_revision'] ?? null,
            // legacy fields kept nullable to avoid duplication; prefer mask_setting_id linkage
            ':blend_mode' => $data['blend_mode'] ?? null,
            ':blend_opacity' => $data['blend_opacity'] ?? null,
            ':lightness_offset' => $data['shadow_l_offset'] ?? null,
            ':tint_hex' => $data['shadow_tint_hex'] ?? null,
            ':tint_opacity' => $data['shadow_tint_opacity'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function linkPaletteToClient(int $clientId, int $paletteId, string $relation): void
    {
        $sql = "INSERT INTO client_applied_palettes
                (client_id, applied_palette_id, relation_type, created_at)
                VALUES (:client_id, :applied_palette_id, :relation_type, NOW())
                ON DUPLICATE KEY UPDATE relation_type = VALUES(relation_type)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':client_id' => $clientId,
            ':applied_palette_id' => $paletteId,
            ':relation_type' => $relation,
        ]);
    }

    public function deleteById(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM applied_palettes WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    public function updatePalette(int $id, array $fields): void
    {
        $cols = [];
        $params = [':id' => $id];
        foreach (['title', 'display_title', 'notes', 'tags'] as $key) {
            if (array_key_exists($key, $fields)) {
                $cols[] = "{$key} = :{$key}";
                $params[":{$key}"] = $fields[$key];
            }
        }
        if (!$cols) return;
        $sql = "UPDATE applied_palettes SET " . implode(', ', $cols) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}
