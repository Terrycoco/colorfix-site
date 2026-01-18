<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

class PdoPhotoRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getPhotoByAssetId(string $assetId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM photos
            WHERE asset_id = :asset_id
            LIMIT 1
        ");
        $stmt->execute(['asset_id' => $assetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getPhotoById(int $photoId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM photos
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $photoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createPhotoShell(
        string $assetId,
        ?string $stylePrimary,
        ?string $verdict,
        ?string $status,
        ?string $lighting,
        ?string $rightsStatus,
        ?string $categoryPath
    ): array {
        $stmt = $this->pdo->prepare("
            INSERT INTO photos (
                asset_id,
                style_primary,
                verdict,
                status,
                lighting,
                category_path,
                rights_status
            ) VALUES (
                :asset_id,
                :style_primary,
                :verdict,
                :status,
                :lighting,
                :category_path,
                :rights_status
            )
        ");
        $stmt->execute([
            'asset_id' => $assetId,
            'style_primary' => $stylePrimary,
            'verdict' => $verdict,
            'status' => $status,
            'lighting' => $lighting,
            'category_path' => $categoryPath,
            'rights_status' => $rightsStatus,
        ]);

        return ['id' => (int)$this->pdo->lastInsertId()];
    }

    public function updatePhotoCategoryPath(int $photoId, string $categoryPath): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE photos
            SET category_path = :category_path
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $photoId,
            'category_path' => $categoryPath,
        ]);
    }

    public function updatePhotoSize(int $photoId, int $width, int $height): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE photos
            SET width = :width, height = :height
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $photoId,
            'width' => $width,
            'height' => $height,
        ]);
    }

    public function listVariants(int $photoId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM photos_variants
            WHERE photo_id = :photo_id
            ORDER BY id ASC
        ");
        $stmt->execute(['photo_id' => $photoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRoleStats(int $photoId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM photos_mask_stats
            WHERE photo_id = :photo_id
        ");
        $stmt->execute(['photo_id' => $photoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsertMaskStats(
        int $photoId,
        string $role,
        string $preparedPath,
        string $maskPath,
        int $preparedBytes,
        int $maskBytes,
        float $lAvg01,
        ?float $lP10,
        ?float $lP90,
        int $pxCovered
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO photos_mask_stats (
                photo_id,
                role,
                prepared_path,
                mask_path,
                prepared_bytes,
                mask_bytes,
                l_avg01,
                l_p10,
                l_p90,
                px_covered
            ) VALUES (
                :photo_id,
                :role,
                :prepared_path,
                :mask_path,
                :prepared_bytes,
                :mask_bytes,
                :l_avg01,
                :l_p10,
                :l_p90,
                :px_covered
            )
            ON DUPLICATE KEY UPDATE
                prepared_path = VALUES(prepared_path),
                mask_path = VALUES(mask_path),
                prepared_bytes = VALUES(prepared_bytes),
                mask_bytes = VALUES(mask_bytes),
                l_avg01 = VALUES(l_avg01),
                l_p10 = VALUES(l_p10),
                l_p90 = VALUES(l_p90),
                px_covered = VALUES(px_covered),
                computed_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'photo_id' => $photoId,
            'role' => $role,
            'prepared_path' => $preparedPath,
            'mask_path' => $maskPath,
            'prepared_bytes' => $preparedBytes,
            'mask_bytes' => $maskBytes,
            'l_avg01' => $lAvg01,
            'l_p10' => $lP10,
            'l_p90' => $lP90,
            'px_covered' => $pxCovered,
        ]);
    }

    public function upsertVariant(
        int $photoId,
        string $kind,
        string $role,
        string $path,
        string $mime,
        int $bytes,
        int $width,
        int $height,
        array $overlaySettings = [],
        ?string $originalTexture = null
    ): void {
        $existingId = $this->findVariantId($photoId, $kind, $role);

        $overlay = $this->normalizeOverlay($overlaySettings);
        $payload = [
            'photo_id' => $photoId,
            'kind' => $kind,
            'role' => $role,
            'original_texture' => $originalTexture,
            'path' => $path,
            'mime' => $mime,
            'bytes' => $bytes,
            'width' => $width,
            'height' => $height,
            'overlay_mode_dark' => $overlay['overlay_mode_dark'],
            'overlay_opacity_dark' => $overlay['overlay_opacity_dark'],
            'overlay_mode_medium' => $overlay['overlay_mode_medium'],
            'overlay_opacity_medium' => $overlay['overlay_opacity_medium'],
            'overlay_mode_light' => $overlay['overlay_mode_light'],
            'overlay_opacity_light' => $overlay['overlay_opacity_light'],
            'overlay_mode' => $overlay['overlay_mode'],
            'overlay_opacity' => $overlay['overlay_opacity'],
            'overlay_shadow_l_offset' => $overlay['overlay_shadow_l_offset'],
            'overlay_shadow_tint' => $overlay['overlay_shadow_tint'],
            'overlay_shadow_tint_opacity' => $overlay['overlay_shadow_tint_opacity'],
        ];

        if ($existingId) {
            $stmt = $this->pdo->prepare("
                UPDATE photos_variants
                SET
                    original_texture = :original_texture,
                    path = :path,
                    mime = :mime,
                    bytes = :bytes,
                    width = :width,
                    height = :height,
                    overlay_mode_dark = :overlay_mode_dark,
                    overlay_opacity_dark = :overlay_opacity_dark,
                    overlay_mode_medium = :overlay_mode_medium,
                    overlay_opacity_medium = :overlay_opacity_medium,
                    overlay_mode_light = :overlay_mode_light,
                    overlay_opacity_light = :overlay_opacity_light,
                    overlay_mode = :overlay_mode,
                    overlay_opacity = :overlay_opacity,
                    overlay_shadow_l_offset = :overlay_shadow_l_offset,
                    overlay_shadow_tint = :overlay_shadow_tint,
                    overlay_shadow_tint_opacity = :overlay_shadow_tint_opacity
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $existingId,
                'original_texture' => $payload['original_texture'] ?? null,
                'path' => $payload['path'] ?? null,
                'mime' => $payload['mime'] ?? null,
                'bytes' => $payload['bytes'] ?? null,
                'width' => $payload['width'] ?? null,
                'height' => $payload['height'] ?? null,
                'overlay_mode_dark' => $payload['overlay_mode_dark'] ?? null,
                'overlay_opacity_dark' => $payload['overlay_opacity_dark'] ?? null,
                'overlay_mode_medium' => $payload['overlay_mode_medium'] ?? null,
                'overlay_opacity_medium' => $payload['overlay_opacity_medium'] ?? null,
                'overlay_mode_light' => $payload['overlay_mode_light'] ?? null,
                'overlay_opacity_light' => $payload['overlay_opacity_light'] ?? null,
                'overlay_mode' => $payload['overlay_mode'] ?? null,
                'overlay_opacity' => $payload['overlay_opacity'] ?? null,
                'overlay_shadow_l_offset' => $payload['overlay_shadow_l_offset'] ?? null,
                'overlay_shadow_tint' => $payload['overlay_shadow_tint'] ?? null,
                'overlay_shadow_tint_opacity' => $payload['overlay_shadow_tint_opacity'] ?? null,
            ]);
            return;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO photos_variants (
                photo_id,
                kind,
                role,
                original_texture,
                path,
                mime,
                bytes,
                width,
                height,
                overlay_mode_dark,
                overlay_opacity_dark,
                overlay_mode_medium,
                overlay_opacity_medium,
                overlay_mode_light,
                overlay_opacity_light,
                overlay_mode,
                overlay_opacity,
                overlay_shadow_l_offset,
                overlay_shadow_tint,
                overlay_shadow_tint_opacity
            ) VALUES (
                :photo_id,
                :kind,
                :role,
                :original_texture,
                :path,
                :mime,
                :bytes,
                :width,
                :height,
                :overlay_mode_dark,
                :overlay_opacity_dark,
                :overlay_mode_medium,
                :overlay_opacity_medium,
                :overlay_mode_light,
                :overlay_opacity_light,
                :overlay_mode,
                :overlay_opacity,
                :overlay_shadow_l_offset,
                :overlay_shadow_tint,
                :overlay_shadow_tint_opacity
            )
        ");
        $stmt->execute($payload);
    }

    public function updateMaskOverlay(int $photoId, string $role, array $overlay, ?string $originalTexture = null): bool
    {
        $overlay = $this->normalizeOverlay($overlay);
        $variantId = $this->findMaskVariantId($photoId, $role);
        if (!$variantId) return false;

        $stmt = $this->pdo->prepare("
            UPDATE photos_variants
            SET
                overlay_mode_dark = :overlay_mode_dark,
                overlay_opacity_dark = :overlay_opacity_dark,
                overlay_mode_medium = :overlay_mode_medium,
                overlay_opacity_medium = :overlay_opacity_medium,
                overlay_mode_light = :overlay_mode_light,
                overlay_opacity_light = :overlay_opacity_light,
                overlay_mode = :overlay_mode,
                overlay_opacity = :overlay_opacity,
                overlay_shadow_l_offset = :overlay_shadow_l_offset,
                overlay_shadow_tint = :overlay_shadow_tint,
                overlay_shadow_tint_opacity = :overlay_shadow_tint_opacity,
                original_texture = :original_texture
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $variantId,
            'original_texture' => $originalTexture,
            'overlay_mode_dark' => $overlay['overlay_mode_dark'],
            'overlay_opacity_dark' => $overlay['overlay_opacity_dark'],
            'overlay_mode_medium' => $overlay['overlay_mode_medium'],
            'overlay_opacity_medium' => $overlay['overlay_opacity_medium'],
            'overlay_mode_light' => $overlay['overlay_mode_light'],
            'overlay_opacity_light' => $overlay['overlay_opacity_light'],
            'overlay_mode' => $overlay['overlay_mode'],
            'overlay_opacity' => $overlay['overlay_opacity'],
            'overlay_shadow_l_offset' => $overlay['overlay_shadow_l_offset'],
            'overlay_shadow_tint' => $overlay['overlay_shadow_tint'],
            'overlay_shadow_tint_opacity' => $overlay['overlay_shadow_tint_opacity'],
        ]);

        return true;
    }

    public function getTagsForPhotoIds(array $photoIds): array
    {
        $photoIds = array_values(array_filter(array_map('intval', $photoIds)));
        if (!$photoIds) return [];

        $placeholders = implode(',', array_fill(0, count($photoIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT photo_id, tag
            FROM photos_tags
            WHERE photo_id IN ({$placeholders})
        ");
        $stmt->execute($photoIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $pid = (int)$row['photo_id'];
            $tag = (string)$row['tag'];
            if (!isset($map[$pid])) $map[$pid] = [];
            $map[$pid][] = $tag;
        }
        return $map;
    }

    public function addTag(int $photoId, string $tag): void
    {
        $tag = trim($tag);
        if ($tag === '') return;
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO photos_tags (photo_id, tag)
            VALUES (:photo_id, :tag)
        ");
        $stmt->execute([
            'photo_id' => $photoId,
            'tag' => $tag,
        ]);
    }

    public function searchAssets(array $tags, string $qtext, int $limit, int $offset): array
    {
        $tags = array_values(array_filter(array_map('trim', $tags)));
        $params = [];
        $where = [];
        $joins = [];
        $having = '';

        if ($qtext !== '') {
            $where[] = "(p.asset_id LIKE :qtext OR p.style_primary LIKE :qtext OR p.category_path LIKE :qtext)";
            $params['qtext'] = '%' . $qtext . '%';
        }

        if ($tags) {
            $joins[] = "JOIN photos_tags pt ON pt.photo_id = p.id";
            $placeholders = [];
            foreach ($tags as $idx => $tag) {
                $key = "tag{$idx}";
                $placeholders[] = ':' . $key;
                $params[$key] = $tag;
            }
            $where[] = "pt.tag IN (" . implode(',', $placeholders) . ")";
            $having = "HAVING COUNT(DISTINCT pt.tag) = " . count($tags);
        }

        $sqlBase = "
            FROM photos p
            " . implode("\n", $joins) . "
            " . ($where ? "WHERE " . implode(" AND ", $where) : "") . "
            GROUP BY p.id
            {$having}
        ";

        $countSql = "SELECT COUNT(*) AS total FROM (SELECT p.id {$sqlBase}) t";
        $stmt = $this->pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $itemsSql = "
            SELECT
                p.id,
                p.asset_id,
                p.style_primary AS title,
                (
                    SELECT v.path
                    FROM photos_variants v
                    WHERE v.photo_id = p.id
                      AND (
                        v.kind = 'thumb'
                        OR (v.kind = 'prepared' AND v.role = '')
                        OR v.kind = 'prepared_base'
                        OR (v.kind = 'repaired' AND v.role = '')
                      )
                    ORDER BY
                        CASE
                            WHEN v.kind = 'thumb' THEN 0
                            WHEN v.kind = 'prepared' AND v.role = '' THEN 1
                            WHEN v.kind = 'prepared_base' THEN 2
                            ELSE 3
                        END
                    LIMIT 1
                ) AS thumb_rel_path
            {$sqlBase}
            ORDER BY p.id DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->pdo->prepare($itemsSql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $photoIds = array_map(fn($row) => (int)$row['id'], $rows);
        $tagsMap = $this->getTagsForPhotoIds($photoIds);

        $items = [];
        foreach ($rows as $row) {
            $pid = (int)$row['id'];
            $items[] = [
                'asset_id' => (string)$row['asset_id'],
                'title' => (string)($row['title'] ?? ''),
                'thumb_rel_path' => (string)($row['thumb_rel_path'] ?? ''),
                'tags' => $tagsMap[$pid] ?? [],
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    public function listAllAssetIds(?int $limit = null, ?int $offset = null): array
    {
        $sql = "SELECT asset_id FROM photos WHERE asset_id IS NOT NULL AND asset_id <> '' ORDER BY id ASC";
        if ($limit !== null) {
            $sql .= " LIMIT :limit";
            if ($offset !== null) {
                $sql .= " OFFSET :offset";
            }
        }
        $stmt = $this->pdo->prepare($sql);
        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }
        if ($offset !== null) {
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_values(array_filter(array_map(fn($row) => (string)($row['asset_id'] ?? ''), $rows)));
    }

    private function findVariantId(int $photoId, string $kind, string $role): ?int
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM photos_variants
            WHERE photo_id = :photo_id
              AND kind = :kind
              AND role = :role
            LIMIT 1
        ");
        $stmt->execute([
            'photo_id' => $photoId,
            'kind' => $kind,
            'role' => $role,
        ]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    private function findMaskVariantId(int $photoId, string $role): ?int
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM photos_variants
            WHERE photo_id = :photo_id
              AND role = :role
              AND kind IN ('masks', 'mask')
            LIMIT 1
        ");
        $stmt->execute([
            'photo_id' => $photoId,
            'role' => $role,
        ]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    private function normalizeOverlay(array $overlay): array
    {
        $dark = $overlay['dark'] ?? [];
        $medium = $overlay['medium'] ?? [];
        $light = $overlay['light'] ?? [];
        $shadow = $overlay['_shadow'] ?? [];

        return [
            'overlay_mode_dark' => $dark['mode'] ?? null,
            'overlay_opacity_dark' => $dark['opacity'] ?? null,
            'overlay_mode_medium' => $medium['mode'] ?? null,
            'overlay_opacity_medium' => $medium['opacity'] ?? null,
            'overlay_mode_light' => $light['mode'] ?? null,
            'overlay_opacity_light' => $light['opacity'] ?? null,
            'overlay_mode' => $overlay['mode'] ?? null,
            'overlay_opacity' => $overlay['opacity'] ?? null,
            'overlay_shadow_l_offset' => $shadow['l_offset'] ?? null,
            'overlay_shadow_tint' => $shadow['tint_hex'] ?? null,
            'overlay_shadow_tint_opacity' => $shadow['tint_opacity'] ?? null,
        ];
    }
}
