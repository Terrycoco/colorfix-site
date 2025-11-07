<?php
require_once 'db.php';
require_once __DIR__ . '/functions/getOppositeHue.php';

$updated = 0;

// Fetch all rows with LAB data
$stmt = $pdo->query("SELECT hcl_hue, lab_l, lab_a, lab_b FROM hcl_rgb_lookup");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare update statement
$update = $pdo->prepare("
    UPDATE hcl_rgb_lookup
    SET opposite_hcl_h = :opposite
    WHERE hcl_hue = :hue
");

foreach ($rows as $row) {
    $h = intval($row['hcl_hue']);
    $lab_l = floatval($row['lab_l']);
    $lab_a = floatval($row['lab_a']);
    $lab_b = floatval($row['lab_b']);

    // Skip if LAB is incomplete
    if (!is_numeric($lab_l) || !is_numeric($lab_a) || !is_numeric($lab_b)) continue;

    // Compute opposite hue
    $opposite = getOppositeHue($h, $lab_l, $lab_a, $lab_b, $pdo);

    // Update the DB
    $update->execute([
        ':opposite' => $opposite,
        ':hue' => $h
    ]);

    echo "Hue $h → Opposite hue $opposite\n";
    $updated++;
}

echo "✅ Updated $updated rows with opposite hues.\n";
?>
