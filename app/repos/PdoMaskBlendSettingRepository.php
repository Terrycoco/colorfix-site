<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

class PdoMaskBlendSettingRepository
{
    public function __construct(private PDO $pdo) {}

    private function baseSelect(): string
    {
        return "
            SELECT mbs.*,
                   COALESCE(NULLIF(mbs.color_hex, ''), c.hex6) AS color_hex,
                   c.hcl_l AS color_hcl_l,
                   c.lab_l AS color_lab_l,
                   c.hcl_h AS color_hcl_h,
                   c.hcl_c AS color_hcl_c,
                   COALESCE(c.hcl_l, c.lab_l, mbs.base_lightness) AS target_lightness_live,
                   c.hcl_h AS target_h_live,
                   c.hcl_c AS target_c_live
            FROM mask_blend_settings mbs
            LEFT JOIN colors c ON mbs.color_id = c.id
        ";
    }

    public function listForMask(int $photoId, string $maskRole): array
    {
        $sql = $this->baseSelect() . "
            WHERE mbs.photo_id = :photo_id
              AND mbs.mask_role = :mask_role
            ORDER BY COALESCE(c.hcl_l, c.lab_l, mbs.base_lightness) ASC, mbs.id ASC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':photo_id' => $photoId,
            ':mask_role' => $maskRole,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function insert(array $data): array
    {
        $sql = "
            INSERT INTO mask_blend_settings
                (photo_id, asset_id, mask_role, color_id, color_name, color_brand, color_code, color_hex,
                 base_lightness, blend_mode, blend_opacity,
                 shadow_l_offset, shadow_tint_hex, shadow_tint_opacity,
                 is_preset, approved, notes, created_at)
            VALUES
                (:photo_id, :asset_id, :mask_role, :color_id, :color_name, :color_brand, :color_code, :color_hex,
                 :base_lightness, :blend_mode, :blend_opacity,
                 :shadow_l_offset, :shadow_tint_hex, :shadow_tint_opacity,
                 :is_preset, :approved, :notes, NOW())
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':photo_id' => $data['photo_id'],
            ':asset_id' => $data['asset_id'],
            ':mask_role' => $data['mask_role'],
            ':color_id' => $data['color_id'],
            ':color_name' => $data['color_name'],
            ':color_brand' => $data['color_brand'],
            ':color_code' => $data['color_code'],
            ':color_hex' => $data['color_hex'],
            ':base_lightness' => $data['base_lightness'],
            ':blend_mode' => $data['blend_mode'],
            ':blend_opacity' => $data['blend_opacity'],
            ':shadow_l_offset' => $data['shadow_l_offset'],
            ':shadow_tint_hex' => $data['shadow_tint_hex'],
            ':shadow_tint_opacity' => $data['shadow_tint_opacity'],
            ':is_preset' => (int)($data['is_preset'] ?? 0),
            ':approved' => (int)($data['approved'] ?? 0),
            ':notes' => $data['notes'] ?? null,
        ]);

        $id = (int)$this->pdo->lastInsertId();
        return $this->findById($id);
    }

    public function update(int $id, array $fields): ?array
    {
        $allowed = [
            'color_id','color_name','color_brand','color_code','color_hex',
            'base_lightness',
            'blend_mode','blend_opacity',
            'shadow_l_offset','shadow_tint_hex','shadow_tint_opacity',
            'notes','approved','is_preset'
        ];
        $set = [];
        $params = [':id' => $id];
        foreach ($fields as $col => $val) {
            if (!in_array($col, $allowed, true)) continue;
            $key = ':' . $col;
            $set[] = "{$col} = {$key}";
            $params[$key] = $val;
        }
        if (!$set) return $this->findById($id);
        $sql = "
            UPDATE mask_blend_settings
               SET " . implode(',', $set) . ",
                   updated_at = NOW()
             WHERE id = :id
             LIMIT 1
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $this->findById($id);
    }

    public function delete(int $id, int $photoId, string $maskRole): void
    {
        $sql = "
            DELETE FROM mask_blend_settings
             WHERE id = :id AND photo_id = :photo_id AND mask_role = :mask_role
             LIMIT 1
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':photo_id' => $photoId,
            ':mask_role' => $maskRole,
        ]);
    }

    public function findClosest(int $photoId, string $maskRole, float $baseLightness, float $targetLightness): ?array
    {
        $sql = $this->baseSelect() . "
            WHERE mbs.photo_id = :photo_id
              AND mbs.mask_role = :mask_role
            ORDER BY
              (ABS(mbs.base_lightness - :base_l) + ABS(COALESCE(c.hcl_l, c.lab_l, mbs.base_lightness) - :target_l)) ASC,
              mbs.updated_at DESC
            LIMIT 1
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':photo_id' => $photoId,
            ':mask_role' => $maskRole,
            ':base_l' => $baseLightness,
            ':target_l' => $targetLightness,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $sql = $this->baseSelect() . " WHERE mbs.id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findForAssetMaskColor(string $assetId, string $maskRole, int $colorId): ?array
    {
        $sql = $this->baseSelect() . "
            WHERE mbs.asset_id = :asset_id
              AND mbs.mask_role = :mask_role
              AND mbs.color_id = :color_id
            ORDER BY mbs.updated_at DESC, mbs.id DESC
            LIMIT 1
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':asset_id' => $assetId,
            ':mask_role' => $maskRole,
            ':color_id' => $colorId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
