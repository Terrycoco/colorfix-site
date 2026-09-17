<?php
declare(strict_types=1);

namespace App\PALETTES\Repos;

use PDO;

final class PdoPaletteEditorRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function nicknameInUse(string $nickname, ?int $excludePaletteId = null): bool
    {
        $nickname = trim($nickname);

        if ($nickname === '') {
            return false;
        }

        $sql = "
            SELECT 1
              FROM saved_palettes
             WHERE LOWER(TRIM(nickname)) = LOWER(TRIM(:nickname))
        ";

        $params = [
            ':nickname' => $nickname,
        ];

        if ($excludePaletteId !== null && $excludePaletteId > 0) {
            $sql .= " AND id <> :exclude_id";
            $params[':exclude_id'] = $excludePaletteId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (bool)$stmt->fetchColumn();
    }

    public function paletteExists(int $paletteId): bool
    {
        if ($paletteId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            "SELECT 1
               FROM saved_palettes
              WHERE id = :id
              LIMIT 1"
        );

        $stmt->execute([
            ':id' => $paletteId,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    public function createPalette(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO saved_palettes
                (
                    palette_hash,
                    brand,
                    palette_type,
                    nickname,
                    display_title,
                    notes,
                    private_notes,
                    terry_fav,
                    is_public,
                    created_at
                )
             VALUES
                (
                    :palette_hash,
                    :brand,
                    :palette_type,
                    :nickname,
                    NULL,
                    NULL,
                    :private_notes,
                    0,
                    :is_public,
                    NOW()
                )"
        );

        $stmt->execute([
            ':palette_hash' => $this->newStorageHash(),
            ':brand' => null,
            ':palette_type' => $this->nullableText($data['palette_type'] ?? null),
            ':nickname' => trim((string)($data['nickname'] ?? '')),
            ':private_notes' => $this->nullableText($data['private_notes'] ?? null),
            ':is_public' => (int)(bool)($data['is_public'] ?? true),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function updatePalette(int $paletteId, array $data): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE saved_palettes
                SET nickname = :nickname,
                    palette_type = :palette_type,
                    private_notes = :private_notes,
                    is_public = :is_public,
                    updated_at = NOW()
              WHERE id = :id"
        );

        $stmt->execute([
            ':nickname' => trim((string)($data['nickname'] ?? '')),
            ':palette_type' => $this->nullableText($data['palette_type'] ?? null),
            ':private_notes' => $this->nullableText($data['private_notes'] ?? null),
            ':is_public' => (int)(bool)($data['is_public'] ?? true),
            ':id' => $paletteId,
        ]);
    }

    public function replaceMembers(int $paletteId, array $members): void
    {
        $delete = $this->pdo->prepare(
            "DELETE FROM saved_palette_members
              WHERE saved_palette_id = :palette_id"
        );

        $delete->execute([
            ':palette_id' => $paletteId,
        ]);

        if ($members === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO saved_palette_members
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
                )"
        );

        foreach ($members as $index => $member) {
            $colorId = (int)($member['color_id'] ?? 0);

            if ($colorId <= 0) {
                continue;
            }

            $insert->execute([
                ':saved_palette_id' => $paletteId,
                ':color_id' => $colorId,
                ':role_name' => $this->nullableText($member['role'] ?? null),
                ':sheen' => $this->nullableText($member['sheen'] ?? null),
                ':note' => $this->nullableText($member['note'] ?? null),
                ':order_index' => (int)($member['order_index'] ?? $index),
            ]);
        }
    }

    public function findPublicPVByPaletteId(int $paletteId): ?array
    {
        if ($paletteId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                palette_viewer_id,
                saved_palette_id,
                format,
                template_key,
                kicker_text,
                title,
                intro,
                notes,
                cta_label,
                is_active
               FROM palette_viewers
              WHERE saved_palette_id = :palette_id
                AND format = 'public'
           ORDER BY is_active DESC, palette_viewer_id ASC
              LIMIT 1"
        );

        $stmt->execute([
            ':palette_id' => $paletteId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function createPublicPV(
        int $paletteId,
        ?string $title,
        ?string $description
    ): int {
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
                    'public',
                    'full_palette',
                    NULL,
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
            ':saved_palette_id' => $paletteId,
            ':title' => $this->nullableText($title),
            ':intro' => $this->nullableText($description),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function updatePublicPV(
        int $pvId,
        ?string $title,
        ?string $description
    ): void {
        if ($pvId <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE palette_viewers
                SET title = :title,
                    intro = :intro,
                    updated_at = NOW()
              WHERE palette_viewer_id = :pv_id"
        );

        $stmt->execute([
            ':title' => $this->nullableText($title),
            ':intro' => $this->nullableText($description),
            ':pv_id' => $pvId,
        ]);
    }

    public function getEditorItem(int $paletteId): ?array
    {
        if ($paletteId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                sp.id,
                sp.palette_hash,
                sp.brand,
                sp.palette_type,
                sp.nickname,
                sp.display_title,
                sp.notes,
                sp.private_notes,
                sp.terry_fav,
                sp.is_public,
                sp.created_at,
                sp.updated_at
               FROM saved_palettes sp
              WHERE sp.id = :id
              LIMIT 1"
        );

        $stmt->execute([
            ':id' => $paletteId,
        ]);

        $palette = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($palette === false) {
            return null;
        }

        $pv = $this->findPublicPVByPaletteId($paletteId);

        $palette['palette_viewer_id'] = $pv
            ? (int)$pv['palette_viewer_id']
            : null;

        $palette['pv_title'] = $pv['title'] ?? null;
        $palette['pv_description'] = $pv['intro'] ?? null;
        $palette['pv_kicker_text'] = $pv['kicker_text'] ?? null;
        $palette['members'] = $this->getMembers($paletteId);

        return $palette;
    }

    public function getMembers(int $paletteId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                m.id,
                m.saved_palette_id,
                m.color_id,
                m.role_name AS role,
                m.sheen,
                m.note,
                m.order_index,

                c.name AS color_name,
                c.brand AS color_brand,
                c.brand_name AS color_brand_name,
                c.code AS color_code,
                c.hex6 AS color_hex6,
                c.hcl_h AS color_hcl_h,
                c.hcl_c AS color_hcl_c,
                c.hcl_l AS color_hcl_l,
                c.int_only AS color_int_only,
                c.chip_num AS color_chip_num,
                c.cluster_id AS color_cluster_id,
                c.hue_cats AS color_hue_cats,
                c.neutral_cats AS color_neutral_cats

               FROM saved_palette_members m

          LEFT JOIN swatch_view c
                 ON c.id = m.color_id

              WHERE m.saved_palette_id = :palette_id

           ORDER BY
                m.order_index ASC,
                m.id ASC"
        );

        $stmt->execute([
            ':palette_id' => $paletteId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function newStorageHash(): string
    {
        return hash(
            'sha256',
            'saved-palette:' . bin2hex(random_bytes(32))
        );
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));

        return $text === '' ? null : $text;
    }
}
