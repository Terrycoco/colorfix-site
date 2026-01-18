<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

class HoaSchemeColorsService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    private function normalizeRoleKey(string $role): string
    {
        $key = strtolower(trim($role));
        if (strlen($key) > 3 && str_ends_with($key, 's') && !str_ends_with($key, 'ss')) {
            return substr($key, 0, -1);
        }
        return $key;
    }

    public function getColorsByHoaId(int $hoaId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                s.id AS scheme_id,
                s.scheme_code,
                sc.allowed_roles,
                sc.notes,
                sv.id AS color_id,
                sv.name,
                sv.brand,
                sv.code,
                sv.hex6,
                sv.hcl_h,
                sv.hcl_c,
                sv.hcl_l
            FROM hoa_schemes s
            JOIN hoa_scheme_colors sc ON sc.scheme_id = s.id
            JOIN swatch_view sv ON sv.id = sc.color_id
            WHERE s.hoa_id = :hoa_id
            ORDER BY s.scheme_code ASC, sv.name ASC
        ");
        $stmt->execute(['hoa_id' => $hoaId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $colorsByRole = [];
        $roleSeen = [];
        $anyColors = [];
        $anySeen = [];

        foreach ($rows as $row) {
            $colorId = (int)($row['color_id'] ?? 0);
            if ($colorId <= 0) continue;
            $color = [
                'color_id' => $colorId,
                'name' => $row['name'] ?? '',
                'brand' => $row['brand'] ?? '',
                'code' => $row['code'] ?? '',
                'hex6' => strtoupper((string)($row['hex6'] ?? '')),
                'hcl_h' => isset($row['hcl_h']) ? (float)$row['hcl_h'] : null,
                'hcl_c' => isset($row['hcl_c']) ? (float)$row['hcl_c'] : null,
                'hcl_l' => isset($row['hcl_l']) ? (float)$row['hcl_l'] : null,
                'note' => $row['notes'] ?? null,
                'scheme_code' => $row['scheme_code'] ?? null,
            ];
            $allowedRaw = strtolower(trim((string)($row['allowed_roles'] ?? '')));
            if ($allowedRaw === '' || $allowedRaw === 'any') {
                if (!isset($anySeen[$colorId])) {
                    $anyColors[] = $color;
                    $anySeen[$colorId] = true;
                }
                continue;
            }

            $roles = array_values(array_filter(array_map('trim', explode(',', $allowedRaw))));
            if (!$roles) {
                if (!isset($anySeen[$colorId])) {
                    $anyColors[] = $color;
                    $anySeen[$colorId] = true;
                }
                continue;
            }

            foreach ($roles as $role) {
                $key = $this->normalizeRoleKey($role);
                if ($key === '') continue;
                if (!isset($colorsByRole[$key])) {
                    $colorsByRole[$key] = [];
                    $roleSeen[$key] = [];
                }
                if (isset($roleSeen[$key][$colorId])) continue;
                $colorsByRole[$key][] = $color;
                $roleSeen[$key][$colorId] = true;
            }
        }

        return [
            'colors_by_role' => $colorsByRole,
            'any_colors' => $anyColors,
        ];
    }
}
