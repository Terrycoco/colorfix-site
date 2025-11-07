<?php
function getOppositeHue($sourceHue, $lab_l, $lab_a, $lab_b, $pdo) {
    $maxDelta = -1;
    $oppositeHue = null;

    // Get all LAB values from the table
    $stmt = $pdo->query("SELECT hcl_hue, lab_l, lab_a, lab_b FROM hcl_rgb_lookup");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $targetHue = intval($row['hcl_hue']);

        // Skip self and optionally nearby hues (< 100° apart)
        $hueDistance = abs($sourceHue - $targetHue);
        $hueDistance = min($hueDistance, 360 - $hueDistance); // wraparound
        if ($hueDistance < 100) continue;

        // Get target LAB
        $L2 = floatval($row['lab_l']);
        $a2 = floatval($row['lab_a']);
        $b2 = floatval($row['lab_b']);

        // ΔE76 calculation (Euclidean distance)
        $deltaE = sqrt(
            pow($lab_l - $L2, 2) +
            pow($lab_a - $a2, 2) +
            pow($lab_b - $b2, 2)
        );

        if ($deltaE > $maxDelta) {
            $maxDelta = $deltaE;
            $oppositeHue = $targetHue;
        }
    }

    return $oppositeHue;
}
?>
