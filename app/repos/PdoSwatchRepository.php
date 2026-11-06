<?php
declare(strict_types=1);
namespace App\Repos;

use App\Entities\Swatch;
use PDO;

final class PdoSwatchRepository implements SwatchRepository
{
    public function __construct(private PDO $pdo) {}

    public function getByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($x) => (int)$x, $ids),
            static fn($n) => $n > 0
        )));
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT id, name, brand, code, chip_num, hex6, hcl_l, hcl_c, hcl_h,
                       brand_name, hue_cats, hue_cat_order, neutral_cats, is_stain, cluster_id
                FROM swatch_view WHERE id IN ($ph)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($ids);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['hex6'] = (string)$row['hex6'];
            $out[(int)$row['id']] = $row;
        }
        return $out;
    }

    public function getByIdsAsEntities(array $ids): array
    {
        $rows = $this->getByIds($ids);
        $out = [];
        foreach ($rows as $id => $row) $out[$id] = new Swatch($row);
        return $out;
    }

    public function fuzzySearchByNameCode(string $term, int $limit = 2000, string $nameMode = 'legacy'): array
    {
        $term = trim($term);
        $limit = max(1, (int)$limit);
        $limitPlusOne = $limit + 1;
        $nameMode = in_array($nameMode, ['legacy','starts_with','contains'], true) ? $nameMode : 'legacy';
        // Keep legacy prefix and HEX behavior for unrelated consumers.
        // Explicit MyPalette mode uses escaped text patterns and never searches HEX.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
        $prefix = $nameMode === 'legacy' ? $term . '%' : ($nameMode === 'contains' ? '%' . $escaped . '%' : $escaped . '%');
        $codeDelimitedPrefix = $term . '-%';
        $hexTerm = ltrim($term, '#');
        $hexPrefix = $nameMode === 'legacy' && (str_starts_with($term, '#') || strlen($hexTerm) >= 3)
            ? $hexTerm . '%' : '__NO_HEX_MATCH__';
        $codeCompact = str_replace(['-', ' '], '', $term);
        $codeAfterLettersPattern = ctype_digit($codeCompact) && strlen($codeCompact) >= 3
            ? '^[A-Za-z]+' . preg_quote($codeCompact, '/') . '$'
            : '__NO_ALPHA_PREFIX_CODE_MATCH__';

        $sql = "
            SELECT
              c.id, c.name, c.brand, c.code, c.chip_num,
              c.r, c.g, c.b, c.hex6, c.hex,
              c.hcl_l, c.hcl_c, c.hcl_h, c.brand_name,
              c.hue_cats, c.hue_cat_order, c.neutral_cats,
              c.is_stain, c.cluster_id,
              c.light_cat_id, c.light_cat_name, c.light_cat_order, c.int_only
            FROM mv_swatch_view c
            WHERE c.is_inactive = 0 AND (
              c.name LIKE :prefix_name ESCAPE '!'
              OR c.hex6 LIKE :prefix_hex
              OR c.code = :exact_code_filter
              OR c.code LIKE :delimited_code_filter
              OR REPLACE(REPLACE(c.code, '-', ''), ' ', '') = :compact_code_filter
              OR REPLACE(REPLACE(c.code, '-', ''), ' ', '') REGEXP :code_after_letters_filter
            )
            ORDER BY
              CASE
                WHEN c.code = :exact_code THEN 0
                WHEN c.code LIKE :delimited_code_order THEN 1
                WHEN REPLACE(REPLACE(c.code, '-', ''), ' ', '') = :compact_code_order THEN 2
                WHEN c.name = :exact_name THEN 3
                WHEN c.name LIKE :prefix_name_order ESCAPE '!' THEN 4
                WHEN c.hex6 LIKE :prefix_hex_order THEN 5
                WHEN REPLACE(REPLACE(c.code, '-', ''), ' ', '') REGEXP :code_after_letters_order THEN 6
                ELSE 8
              END,
              c.name, c.brand_name ASC
            LIMIT {$limitPlusOne}
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'prefix_name' => $prefix,
            'prefix_hex' => $hexPrefix,
            'exact_code_filter' => $term,
            'delimited_code_filter' => $codeDelimitedPrefix,
            'compact_code_filter' => $codeCompact,
            'code_after_letters_filter' => $codeAfterLettersPattern,
            'exact_code' => $term,
            'exact_name' => $term,
            'prefix_name_order' => $prefix,
            'prefix_hex_order' => $hexPrefix,
            'delimited_code_order' => $codeDelimitedPrefix,
            'compact_code_order' => $codeCompact,
            'code_after_letters_order' => $codeAfterLettersPattern,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $tooMany = count($rows) > $limit;
        return ['results'=>array_slice($rows, 0, $limit), 'too_many'=>$tooMany];
    }
}
