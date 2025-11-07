<?php
require_once 'db.php';
require_once __DIR__ . '/functions/RGBtoHCL.php';

$start = isset($_GET['start']) ? intval($_GET['start']) : 0;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100000;
$end = min($start + $limit, 16777216); // cap at max RGB

$count = 0;

// Prepared insert
$insert = $pdo->prepare("
    INSERT INTO hcl_rgb_candidates (hcl_hue, r, g, b, hex, hcl_l, hcl_c)
    VALUES (:hue, :r, :g, :b, :hex, :l, :c)
");

// Prepared delete (lower chroma for same hue)
$delete = $pdo->prepare("
    DELETE FROM hcl_rgb_candidates
    WHERE hcl_hue = :hue AND hcl_c < :c
");

for ($i = $start; $i < $end; $i++) {
    $r = ($i >> 16) & 0xFF;
    $g = ($i >> 8) & 0xFF;
    $b = $i & 0xFF;

    $hcl = RGBtoHCL($r, $g, $b);

    if (!is_numeric($hcl['hcl_h']) || !isset($hcl['hcl_l']) || !isset($hcl['hcl_c'])) {
        continue;
    }

    $hue = round($hcl['hcl_h']);
    $hex = sprintf("#%02X%02X%02X", $r, $g, $b);

    // Get current max chroma for this hue
    $existing = $pdo->prepare("SELECT hcl_c FROM hcl_rgb_candidates WHERE hcl_hue = :hue ORDER BY hcl_c DESC LIMIT 1");
    $existing->execute([':hue' => $hue]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);

    // If existing chroma is greater or equal, skip
    if ($row && $row['hcl_c'] >= $hcl['hcl_c']) continue;

    try {
        // Delete lower-chroma entries for this hue
        $delete->execute([
            ':hue' => $hue,
            ':c' => $hcl['hcl_c']
        ]);

        // Insert new best
        $insert->execute([
            ':hue' => $hue,
            ':r' => $r,
            ':g' => $g,
            ':b' => $b,
            ':hex' => $hex,
            ':l' => $hcl['hcl_l'],
            ':c' => $hcl['hcl_c']
        ]);

        $count++;
    } catch (PDOException $e) {
        echo "❌ Error at $hex: " . $e->getMessage() . "\n";
    }
}

echo "✅ Added or replaced $count best-chroma rows\n";
echo "🧭 Start: $start → End: " . ($end - 1) . "\n";
echo "➡️ Next start: $end\n";
