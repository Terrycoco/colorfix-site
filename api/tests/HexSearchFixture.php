<?php
declare(strict_types=1);

use App\Lib\ColorCompute;

/** Isolated schema/data for hex-search tests only; never touches the live database. */
function hexSearchFixture(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE colors (
        id INTEGER PRIMARY KEY, name TEXT, brand TEXT, brand_name TEXT, code TEXT, chip_num TEXT,
        hex6 TEXT, r INTEGER, g INTEGER, b INTEGER, lab_l REAL, lab_a REAL, lab_b REAL,
        hcl_l REAL, hcl_c REAL, hcl_h REAL, hue_cats TEXT, neutral_cats TEXT,
        is_inactive INTEGER DEFAULT 0, is_stain INTEGER DEFAULT 0
    )');
    $pdo->exec('CREATE VIEW swatch_view AS SELECT * FROM colors WHERE is_inactive=0');
    $insert = $pdo->prepare('INSERT INTO colors (id,name,brand,brand_name,code,hex6,r,g,b,lab_l,lab_a,lab_b,hcl_l,hcl_c,hcl_h,hue_cats,is_inactive) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ([
        [1, 'Exact One', 'sw', '808080', 0],
        [2, 'Exact Two', 'bm', '808080', 0],
        [3, 'Nearest', 'sw', '818181', 0],
        [4, 'Further', 'bm', '999999', 0],
        [5, 'Far Away', 'sw', 'FF0000', 0],
        [6, 'Inactive Exact', 'sw', '808080', 1],
        [7, 'Exact Without Lab', 'behr', '808080', 0],
    ] as [$id, $name, $brand, $hex, $inactive]) {
        $all = ColorCompute::computeAll(null, null, null, $hex);
        $insert->execute([$id, $name, $brand, $brand, 'code-' . $id, $hex,
            $all['r'], $all['g'], $all['b'],
            $id === 7 ? null : $all['lab_l'], $id === 7 ? null : $all['lab_a'], $id === 7 ? null : $all['lab_b'],
            $all['hcl_l'], $all['hcl_c'], 360 - $id * 40, $id % 2 ? 'Reds' : 'Blues', $inactive]);
    }
    return $pdo;
}
