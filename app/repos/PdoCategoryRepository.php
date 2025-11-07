<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;
use App\Lib\Logger;

final class PdoCategoryRepository
{
    public function __construct(private PDO $pdo) {}

    public function fetchCategoryDefinitions(): array
    {
        $sql = "SELECT id, name, type, hue_min, hue_max,
                       chroma_min, chroma_max,
                       light_min, light_max,
                       active, calc_only, sort_order
                  FROM category_definitions
                 WHERE active = 1
              ORDER BY type, sort_order, name";
        $stmt = $this->pdo->query($sql);
        $defs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byType = [];
        foreach ($defs as $d) {
            $byType[$d['type']][] = $d;
        }
        return $byType;
    }

    public function fetchColorBatch(int $offset, int $limit): array
    {
        $sql = "SELECT id, hcl_h, hcl_c, hcl_l
                  FROM colors
              ORDER BY id
                 LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countColors(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM colors")->fetchColumn();
    }

 public function replaceColorCategories(int $colorId, array $categoryIds): void
{
    // Savepoint-aware transaction guard
    $useSavepoint = $this->pdo->inTransaction();
    $spName = null;
    if ($useSavepoint) {
        $spName = 'sp_' . bin2hex(random_bytes(4));
        $this->pdo->exec("SAVEPOINT {$spName}");
    } else {
        $this->pdo->beginTransaction();
    }

    try {
        $del = $this->pdo->prepare("DELETE FROM color_category WHERE color_id = :cid");
        $del->execute([':cid' => $colorId]);

        if (!empty($categoryIds)) {
            $ins = $this->pdo->prepare(
                "INSERT INTO color_category (color_id, category_id) VALUES (:cid, :cat)"
            );
            foreach ($categoryIds as $catId) {
                $ins->execute([':cid' => $colorId, ':cat' => (int)$catId]);
            }
        }

        if ($useSavepoint) {
            $this->pdo->exec("RELEASE SAVEPOINT {$spName}");
        } else {
            $this->pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($useSavepoint) {
            $this->pdo->exec("ROLLBACK TO SAVEPOINT {$spName}");
            $this->pdo->exec("RELEASE SAVEPOINT {$spName}");
        } else {
            $this->pdo->rollBack();
        }
        throw $e;
    }
}


    public function updateColorCachedCats(
        int $colorId,
        string $hueCsv,
        int $hueOrder,
        string $neutralCsv,
        ?int $lightCatId,
        ?int $chromaCatId
    ): void {
        $sql = "UPDATE colors
                   SET hue_cats = :hue,
                       hue_cat_order = :ord,
                       neutral_cats = :neu,
                       light_cat_id = :light_id,
                       chroma_cat_id = :chroma_id
                 WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':hue'       => $hueCsv,
            ':ord'       => $hueOrder,
            ':neu'       => $neutralCsv,
            ':light_id'  => $lightCatId,
            ':chroma_id' => $chromaCatId,
            ':id'        => $colorId,
        ]);
    }

    public function refreshClusterAggregates(): void
    {
        $sql = "
        UPDATE clusters c
        JOIN (
            SELECT cl.id AS cluster_id,

                   /* HUE: from colors, ordered by hue_cat_order */
                   COALESCE(NULLIF(GROUP_CONCAT(
                       DISTINCT col.hue_cats
                       ORDER BY col.hue_cat_order
                       SEPARATOR ','),''),'') AS hue_cats_all,

                   /* NEUTRAL: from colors */
                   COALESCE(NULLIF(GROUP_CONCAT(
                       DISTINCT col.neutral_cats
                       SEPARATOR ','),''),'') AS neutral_cats_all,

                   /* LIGHTNESS: via color_category → category_definitions (visible only) */
                   COALESCE(NULLIF(GROUP_CONCAT(
                       DISTINCT ld.name
                       SEPARATOR ','),''),'') AS lightness_cats_all,

                   /* CHROMA: via color_category → category_definitions (visible only) */
                   COALESCE(NULLIF(GROUP_CONCAT(
                       DISTINCT cd.name
                       SEPARATOR ','),''),'') AS chroma_cats_all

              FROM clusters cl
              LEFT JOIN colors col ON col.cluster_id = cl.id

              /* LIGHTNESS joins */
              LEFT JOIN color_category ccL ON ccL.color_id = col.id
              LEFT JOIN category_definitions ld
                     ON ld.id = ccL.category_id
                    AND ld.type = 'lightness'
                    AND ld.active = 1
                    AND ld.calc_only = 0

              /* CHROMA joins */
              LEFT JOIN color_category ccC ON ccC.color_id = col.id
              LEFT JOIN category_definitions cd
                     ON cd.id = ccC.category_id
                    AND cd.type = 'chroma'
                    AND cd.active = 1
                    AND cd.calc_only = 0

             GROUP BY cl.id
        ) agg ON agg.cluster_id = c.id
           SET c.hue_cats       = agg.hue_cats_all,
               c.neutral_cats   = agg.neutral_cats_all,
               c.lightness_cats = agg.lightness_cats_all,
               c.chroma_cats    = agg.chroma_cats_all";
        $this->pdo->exec($sql);
    }

    public function applyHueDisplayCanonicalization(): void
    {
        Logger::info('Applying hue_display canonicalization...');
        $sql1 = "
        UPDATE colors c
        JOIN hue_display hd ON hd.combo_key = c.hue_cats
           SET c.hue_cats = hd.display_name,
               c.hue_cat_order = hd.sort_order";
        $this->pdo->exec($sql1);

        $sql2 = "
        UPDATE clusters cl
        JOIN hue_display hd ON hd.combo_key = cl.hue_cats
           SET cl.hue_cats = hd.display_name";
        $this->pdo->exec($sql2);
    }

    /**
     * Return map of single-hue name → sort_order from hue_display (no commas).
     * Used to deterministically order hue combos at assignment time.
     *
     * @return array<string,int>
     */
    public function getHueSinglesOrderMap(): array
    {
        $sql = "SELECT combo_key, sort_order
                  FROM hue_display
                 WHERE combo_key NOT LIKE '%,%'";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) {
            $k = trim((string)$r['combo_key']);
            if ($k !== '') $map[$k] = (int)$r['sort_order'];
        }
        return $map;
    }

    public function fetchByTypes(array $types): array
    {
        if (empty($types)) return [];

        $ph = implode(',', array_fill(0, count($types), '?'));
        $sql = "
          SELECT
            id,
            name,
            type,
            description,
            hue_min,   hue_max,
            chroma_min, chroma_max,
            light_min,  light_max,
            wheel_text_color,
            sort_order
          FROM category_definitions
          WHERE active = 1
            AND calc_only = 0
            AND type IN ($ph)
          ORDER BY
            (type = 'hue') DESC,         -- hue first
            (hue_min IS NULL) ASC,
            hue_min ASC,
            sort_order ASC,
            name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($types);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // app/repos/PdoCategoryRepository.php
    public function fetchByType(string $type): array
    {
        $rows = $this->fetchByTypes([$type]);
        return array_values(array_filter($rows, static fn($r) => ($r['type'] ?? '') === $type));
    }

    public function applyHueDisplayForColor(int $colorId): void
    {
        $sql1 = "
            UPDATE colors c
            LEFT JOIN hue_display hd ON hd.combo_key = c.hue_cats
               SET c.hue_cats     = COALESCE(hd.display_name, c.hue_cats),
                   c.hue_cat_order = COALESCE(hd.sort_order, c.hue_cat_order)
             WHERE c.id = :id";
        $stmt1 = $this->pdo->prepare($sql1);
        $stmt1->execute([':id' => $colorId]);

        $sql2 = "
            UPDATE clusters cl
            JOIN colors c ON c.cluster_id = cl.id AND c.id = :id
            LEFT JOIN hue_display hd ON hd.combo_key = cl.hue_cats
               SET cl.hue_cats = COALESCE(hd.display_name, cl.hue_cats)";
        $stmt2 = $this->pdo->prepare($sql2);
        $stmt2->execute([':id' => $colorId]);
    }


/**
     * Map cluster_id => #RRGGBB rep hex (leading #, 7 chars).
     */
    public function getRepHexForClusterIds(array $clusterIds): array
    {
        $ids = array_values(array_unique(array_map('intval',
            array_filter($clusterIds, fn($v)=>$v>0))));
        if (!$ids) return [];

        // :c1,:c2,...
        $params = [];
        foreach ($ids as $i => $id) { $params[":c".($i+1)] = $id; }
        $in = implode(',', array_keys($params));

        // adjust table/columns if different in your schema
        $sql = "SELECT id AS cluster_id, rep_hex FROM clusters WHERE id IN ($in)";
        $st  = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_INT);
        $st->execute();

        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $cid = (int)$row['cluster_id'];
            $hex = strtoupper(trim((string)($row['rep_hex'] ?? '')));
            if ($hex !== '') {
                if ($hex[0] !== '#') $hex = '#'.$hex;
                if (strlen($hex) === 7) $out[$cid] = $hex;
            }
        }
        return $out;
    }

}
