<?php
declare(strict_types=1);

namespace App\PALETTES\Repos;

use PDO;

/** Read-only, batch lookups for the Admin saved-palette inventory. */
final class PdoAdminPaletteInventoryRepository
{
    public function __construct(private PDO $pdo) {}

    /** @param int[] $paletteIds @return array<int,list<array<string,mixed>>> */
    public function membersByPalette(array $paletteIds): array
    {
        [$in, $params] = $this->placeholders($paletteIds);
        if ($in === '') return [];
        // Keep the exact columns and ordering returned by getMembersForPalette().
        $sql = "SELECT m.id, m.saved_palette_id, m.color_id,
                       m.role_name AS role, m.sheen, m.note, m.order_index,
                       c.name AS color_name, c.brand AS color_brand,
                       c.brand_name AS color_brand_name, c.code AS color_code,
                       c.hex6 AS color_hex6, c.hcl_h AS color_hcl_h,
                       c.hcl_c AS color_hcl_c, c.hcl_l AS color_hcl_l,
                       c.int_only AS color_int_only, c.chip_num AS color_chip_num,
                       c.cluster_id AS color_cluster_id,
                       c.hue_cats AS color_hue_cats,
                       c.neutral_cats AS color_neutral_cats
                  FROM saved_palette_members m
             LEFT JOIN swatch_view c ON c.id = m.color_id
                 WHERE m.saved_palette_id IN ($in)
              ORDER BY m.saved_palette_id ASC, m.order_index ASC, m.id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[(int)$row['saved_palette_id']][] = $row;
        }
        return $result;
    }

    /** @param int[] $paletteIds @return array<int,int> saved_palette_id => first active public PV id */
    public function publicPvIdsByPalette(array $paletteIds): array
    {
        [$in, $params] = $this->placeholders($paletteIds);
        if ($in === '') return [];
        $stmt = $this->pdo->prepare(
            "SELECT saved_palette_id, MIN(palette_viewer_id) AS palette_viewer_id
               FROM palette_viewers
              WHERE saved_palette_id IN ($in)
                AND is_active = 1
                AND LOWER(TRIM(COALESCE(format, ''))) = 'public'
           GROUP BY saved_palette_id"
        );
        $stmt->execute($params);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[(int)$row['saved_palette_id']] = (int)$row['palette_viewer_id'];
        }
        return $result;
    }

    /**
     * Thumbnail lookup uses the canonical photo_library.palette_id association,
     * independently of whether a public PV exists.
     * @param int[] $paletteIds
     * @return array<int,string>
     */
    public function thumbnailsByPalette(array $paletteIds): array
    {
        [$in, $params] = $this->placeholders($paletteIds);
        if ($in === '') return [];

        $stmt = $this->pdo->prepare(
            "SELECT palette_id, photo_library_id, rel_path, tags, updated_at
               FROM photo_library
              WHERE palette_id IN ($in)
                AND COALESCE(is_inactive, 0) = 0
                AND rel_path IS NOT NULL AND TRIM(rel_path) <> ''
              ORDER BY palette_id ASC, photo_library_id ASC"
        );
        $stmt->execute($params);
        $chosen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int)$row['palette_id'];
            $tags = strtolower((string)($row['tags'] ?? ''));
            // Never put a Before photograph on the saved-palette thumbnail.
            if (preg_match('/(^|[,;\s])before([,;\s]|$)/', $tags)) continue;
            $priority = preg_match('/(^|[,;\s])full([,;\s]|$)/', $tags) ? 0
                : (preg_match('/(^|[,;\s])after([,;\s]|$)/', $tags) ? 1 : 2);
            if (isset($chosen[$id]) && $chosen[$id]['priority'] <= $priority) continue;
            $url = trim((string)$row['rel_path']);
            $updatedAt = trim((string)($row['updated_at'] ?? ''));
            if ($updatedAt !== '' && !preg_match('/[?&]v=/', $url)) {
                $stamp = strtotime($updatedAt);
                if ($stamp !== false && $stamp > 0) {
                    $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . $stamp;
                }
            }
            $chosen[$id] = ['priority' => $priority, 'url' => $url];
        }
        return array_map(static fn(array $row): string => $row['url'], $chosen);
    }

    /** @param int[] $ids @return array{0:string,1:list<int>} */
    private function placeholders(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        return [$ids ? implode(',', array_fill(0, count($ids), '?')) : '', $ids];
    }
}
