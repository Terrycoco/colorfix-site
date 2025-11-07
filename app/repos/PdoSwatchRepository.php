<?php
declare(strict_types=1);

namespace App\repos;

use App\entities\Swatch;
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

    // --- keep your fuzzy method unchanged ---
    public function fuzzySearchByNameCode(string $term, int $limit = 2000): array
    {
        $like = '%' . $term . '%';
        $limit = max(1, (int)$limit);
        $limitPlusOne = $limit + 1;

        $sql = "
            SELECT s.*
            FROM swatch_view s
            WHERE
                 s.name COLLATE utf8_general_ci LIKE :like1
              OR s.code COLLATE utf8_general_ci LIKE :like2
            ORDER BY s.name, s.brand_name ASC
            LIMIT {$limitPlusOne}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['like1' => $like, 'like2' => $like]);

        $rows    = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $tooMany = count($rows) > $limit;
        $results = array_slice($rows, 0, $limit);

        return ['results' => $results, 'too_many' => $tooMany];
    }
}
