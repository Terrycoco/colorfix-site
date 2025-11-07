<?php
require_once 'db.php';
require_once __DIR__ . '/functions/HCLtoLAB.php';

$updated = 0;

$stmt = $pdo->query("SELECT hcl_hue, hcl_l, hcl_c FROM hcl_rgb_lookup");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$update = $pdo->prepare("
    UPDATE hcl_rgb_lookup
    SET lab_l = :lab_l, lab_a = :lab_a, lab_b = :lab_b
    WHERE hcl_hue = :hue
");

foreach ($rows as $row) {
    $h = $row['hcl_hue'];
    $l = $row['hcl_l'];
    $c = $row['hcl_c'];

    if (!is_numeric($h) || !is_numeric($l) || !is_numeric($c)) continue;

    list($lab_l, $lab_a, $lab_b) = HCLtoLAB($h, $c, $l);

    $update->execute([
        ':lab_l' => $lab_l,
        ':lab_a' => $lab_a,
        ':lab_b' => $lab_b,
        ':hue' => $h,
    ]);

    echo "Updated hue $h → L: $lab_l, a: $lab_a, b: $lab_b\n";
    $updated++;
}

echo "✅ $updated LAB values updated in hcl_rgb_lookup\n";
?>
