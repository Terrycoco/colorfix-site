<?php
function checkHues($color) {
    global $pdo;

    $h = floatval($color['hcl_h'] ?? null);
    $c = floatval($color['hcl_c'] ?? null);
    $l = floatval($color['hcl_l'] ?? null);
    if ($h === null) return [];

    $categories = [];

    // Fetch hue-based ranges from DB
    $stmt = $pdo->query("SELECT category, hue_start, hue_end FROM category WHERE hue_start IS NOT NULL AND hue_end IS NOT NULL");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $start = floatval($row['hue_start']);
        $end = floatval($row['hue_end']);
        $cat = $row['category'];

        $inRange = false;

        // Normalize ranges that wrap around 0
        if ($start < 0) {
            if ($h >= (360 + $start) || $h <= $end) {
                $inRange = true;
            }
        } else {
            if ($h >= $start && $h <= $end) {
                $inRange = true;
            }
        }

        if ($inRange) {
            if ($cat === 'Purples') {
                // Refine purple further by chroma/lightness
                 //eliminates blacks and intense blues
                 if ($c >= 2 && $c <= 130) {
                    $categories[] = $cat;
                }
            } else {
                $categories[] = $cat;
            }
        }
    }

    return array_unique($categories);
}
?>
