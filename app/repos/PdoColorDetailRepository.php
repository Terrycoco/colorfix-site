<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoColorDetailRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Returns a merged detail object for the detail page:
     *  - colors.*  (raw color row)
     *  - company.name as brand_name, company.base_url
     *  - category_definitions (names/descr for light/chroma)
     *  - swatch_view extras: hex (with #), hcl_*, hsl_*, hue/neutral cats, color_url
     *  - cats[]: all category_definitions attached via color_category
     */
  // app/repos/PdoColorDetailRepository.php
public function getById(int $id): ?array
{
    // Base color + lookup names/descriptions (NO swatch_view)
    $sql = "
        SELECT
            c.*,
            co.name AS brand_name,
            co.base_url,
            lc.name        AS light_cat,
            lc.description AS light_cat_descr,
            cc.name        AS chroma_cat,
            cc.description AS chroma_cat_descr
        FROM colors c
        JOIN company co                 ON c.brand = co.code
        LEFT JOIN category_definitions lc ON c.light_cat_id  = lc.id
        LEFT JOIN category_definitions cc ON c.chroma_cat_id = cc.id
        WHERE c.id = :id
        LIMIT 1
    ";
    $st = $this->pdo->prepare($sql);
    $st->execute([':id' => $id]);
    $color = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$color) return null;

    // All categories attached to this color (full list)
    $st2 = $this->pdo->prepare("
        SELECT
            cd.id, cd.name, cd.type,
            cd.hue_min, cd.hue_max,
            cd.chroma_min, cd.chroma_max,
            cd.light_min, cd.light_max,
            cd.notes, cd.description
        FROM color_category cc
        JOIN category_definitions cd ON cc.category_id = cd.id
        WHERE cc.color_id = :id
    ");
    $st2->execute([':id' => $id]);
    $color['cats'] = $st2->fetchAll(\PDO::FETCH_ASSOC);

    return $color;
}

}
