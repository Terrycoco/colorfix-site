<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

class PdoHoaSchemeRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /* =========================================================
     * SCHEMES
     * ======================================================= */

    public function getSchemesByHoaId(int $hoaId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM hoa_schemes
            WHERE hoa_id = :hoa_id
            ORDER BY scheme_code ASC
        ");
        $stmt->execute(['hoa_id' => $hoaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSchemeById(int $schemeId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM hoa_schemes
            WHERE id = :id
        ");
        $stmt->execute(['id' => $schemeId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function insertScheme(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO hoa_schemes (
                hoa_id,
                scheme_code,
                source_brand,
                notes
            ) VALUES (
                :hoa_id,
                :scheme_code,
                :source_brand,
                :notes
            )
        ");

        $stmt->execute([
            'hoa_id' => $data['hoa_id'],
            'scheme_code' => $data['scheme_code'],
            'source_brand' => $data['source_brand'],
            'notes' => $data['notes'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateScheme(int $schemeId, array $data): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE hoa_schemes
            SET
                scheme_code = :scheme_code,
                source_brand = :source_brand,
                notes = :notes
            WHERE id = :id
        ");

        $stmt->execute([
            'id' => $schemeId,
            'scheme_code' => $data['scheme_code'],
            'source_brand' => $data['source_brand'],
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /* =========================================================
     * SCHEME COLORS
     * ======================================================= */

    public function getColorsBySchemeId(int $schemeId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                sc.id AS scheme_color_id,
                sc.scheme_id,
                sc.color_id,
                sc.allowed_roles,
                sc.notes,
                c.id AS color_id,
                c.name,
                c.brand,
                c.hex6
            FROM hoa_scheme_colors sc
            JOIN colors c ON c.id = sc.color_id
            WHERE sc.scheme_id = :scheme_id
            ORDER BY c.name ASC
        ");
        $stmt->execute(['scheme_id' => $schemeId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllowedColorsByRole(int $schemeId, string $role): array
    {
        $stmt = $this->pdo->prepare("
            SELECT c.*, sc.allowed_roles
            FROM hoa_scheme_colors sc
            JOIN colors c ON c.id = sc.color_id
            WHERE sc.scheme_id = :scheme_id
              AND (
                    sc.allowed_roles = 'any'
                    OR FIND_IN_SET(:role, sc.allowed_roles)
                  )
            ORDER BY c.name ASC
        ");
        $stmt->execute([
            'scheme_id' => $schemeId,
            'role' => $role,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertSchemeColor(
        int $schemeId,
        int $colorId,
        string $allowedRoles,
        ?string $notes = null
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO hoa_scheme_colors (
                scheme_id,
                color_id,
                allowed_roles,
                notes
            ) VALUES (
                :scheme_id,
                :color_id,
                :allowed_roles,
                :notes
            )
        ");

        $stmt->execute([
            'scheme_id' => $schemeId,
            'color_id' => $colorId,
            'allowed_roles' => $allowedRoles,
            'notes' => $notes,
        ]);
    }

    public function deleteSchemeColor(int $id): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM hoa_scheme_colors
            WHERE id = :id
        ");
        $stmt->execute(['id' => $id]);
    }

    public function updateSchemeColor(int $id, string $allowedRoles, ?string $notes = null): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE hoa_scheme_colors
            SET
                allowed_roles = :allowed_roles,
                notes = :notes
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $id,
            'allowed_roles' => $allowedRoles,
            'notes' => $notes,
        ]);
    }

    /* =========================================================
     * SCHEME MASK MAPS
     * ======================================================= */

    public function getMaskMapBySchemeAsset(int $schemeId, string $assetId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT mask_role, scheme_role
            FROM hoa_scheme_mask_maps
            WHERE scheme_id = :scheme_id
              AND asset_id = :asset_id
            ORDER BY mask_role ASC
        ");
        $stmt->execute([
            'scheme_id' => $schemeId,
            'asset_id' => $assetId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function replaceMaskMap(int $hoaId, int $schemeId, string $assetId, array $items): void
    {
        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare("
                DELETE FROM hoa_scheme_mask_maps
                WHERE scheme_id = :scheme_id
                  AND asset_id = :asset_id
            ");
            $del->execute([
                'scheme_id' => $schemeId,
                'asset_id' => $assetId,
            ]);

            if ($items) {
                $ins = $this->pdo->prepare("
                    INSERT INTO hoa_scheme_mask_maps (
                        hoa_id,
                        scheme_id,
                        asset_id,
                        mask_role,
                        scheme_role
                    ) VALUES (
                        :hoa_id,
                        :scheme_id,
                        :asset_id,
                        :mask_role,
                        :scheme_role
                    )
                ");
                foreach ($items as $row) {
                    $maskRole = trim((string)($row['mask_role'] ?? ''));
                    $schemeRole = trim((string)($row['scheme_role'] ?? ''));
                    if ($maskRole === '' || $schemeRole === '') continue;
                    $ins->execute([
                        'hoa_id' => $hoaId,
                        'scheme_id' => $schemeId,
                        'asset_id' => $assetId,
                        'mask_role' => $maskRole,
                        'scheme_role' => $schemeRole,
                    ]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
