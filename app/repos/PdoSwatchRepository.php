<?php
declare(strict_types=1);

namespace App\Repos;

use App\Entities\Swatch;
use PDO;

final class PdoSwatchRepository implements SwatchRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Return plain arrays from swatch_view; ALWAYS includes hex6.
     * (This matches your tests + FE expectation and avoids the object/array mismatch.)
     */
    public function getByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($x) => (int)$x, $ids),
            static fn($n) => $n > 0
        )));
        if (!$ids) return [];

        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $sql = "
            SELECT
              id, name, brand, code, chip_num,
              hex6,
              hcl_l, hcl_c, hcl_h,
              brand_name, hue_cats, hue_cat_order, neutral_cats,
              is_stain, cluster_id
            FROM swatch_view
            WHERE id IN ($ph)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($ids);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // enforce hex6 presence as a 6-char string
            $row['hex6'] = (string)$row['hex6'];
            $out[(int)$row['id']] = $row;
        }
        return $out;
    }

    /**
     * OPTIONAL: If any legacy code still needs Swatch objects, use this one.
     * Callers must opt-in (don’t change getByIds()).
     */
    public function getByIdsAsEntities(array $ids): array
    {
        $rows = $this->getByIds($ids);
        $out  = [];
        foreach ($rows as $id => $row) {
            $out[$id] = new Swatch($row);
        }
        return $out;
    }

    public function fuzzySearchByNameCode(string $term, int $limit = 2000): array
    {
        $term = trim($term);
        $limit = max(1, (int)$limit);
        $limitPlusOne = $limit + 1;
        $prefix = $term . '%';
        $hexPrefix = ltrim($term, '#') . '%';

        $sql = "
            SELECT
              c.id, c.name, c.brand, c.code, c.chip_num,
              c.r, c.g, c.b,
              c.hex6, CONCAT('#', c.hex6) AS hex,
              c.hcl_l, c.hcl_c, c.hcl_h,
              co.name AS brand_name,
              c.hue_cats, c.hue_cat_order, c.neutral_cats,
              c.is_stain, c.cluster_id,
              c.light_cat_id,
              cd.name AS light_cat_name,
              cd.sort_order AS light_cat_order,
              IF((c.exterior = 0 OR c.exterior IS NULL), 1, 0) AS int_only
            FROM colors c
            LEFT JOIN company co ON co.code = c.brand
            LEFT JOIN category_definitions cd ON cd.id = c.light_cat_id AND cd.type = 'Lightness'
            WHERE c.id IN (
              SELECT id FROM (
                SELECT id FROM (
                  SELECT id
                  FROM colors FORCE INDEX (idx_name)
                  WHERE is_inactive = 0 AND name LIKE :prefix_name
                  ORDER BY name
                  LIMIT {$limitPlusOne}
                ) name_matches

                UNION

                SELECT id FROM (
                  SELECT id
                  FROM colors FORCE INDEX (idx_colors_hex6)
                  WHERE is_inactive = 0 AND hex6 LIKE :prefix_hex
                  ORDER BY hex6
                  LIMIT {$limitPlusOne}
                ) hex_matches

                UNION

                SELECT id FROM (
                  SELECT id
                  FROM colors
                  WHERE is_inactive = 0 AND code = :exact_code_filter
                  LIMIT {$limitPlusOne}
                ) exact_code_matches
              ) candidates
            )
            ORDER BY
              CASE
                WHEN c.code = :exact_code THEN 0
                WHEN c.name = :exact_name THEN 1
                WHEN c.name LIKE :prefix_name_order THEN 2
                WHEN c.hex6 LIKE :prefix_hex_order THEN 3
                WHEN c.code LIKE :prefix_code_order THEN 4
                ELSE 5
              END,
              c.name,
              co.name ASC
            LIMIT {$limitPlusOne}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'prefix_name' => $prefix,
            'prefix_hex' => $hexPrefix,
            'exact_code_filter' => $term,
            'exact_code' => $term,
            'exact_name' => $term,
            'prefix_name_order' => $prefix,
            'prefix_hex_order' => $hexPrefix,
            'prefix_code_order' => $prefix,
        ]);

        $rows    = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $tooMany = count($rows) > $limit;
        $results = array_slice($rows, 0, $limit);

        return ['results' => $results, 'too_many' => $tooMany];
    }
}
