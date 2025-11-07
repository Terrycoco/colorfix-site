<?php
require_once 'db.php';
require_once __DIR__ . '/functions/RGBtoHCL.php';

$step = isset($_GET['step']) ? intval($_GET['step']) : 6;
$start = isset($_GET['start']) ? intval($_GET['start']) : 0;
$end = isset($_GET['end']) ? intval($_GET['end']) : 16777215; // 256^3 - 1

// Build fast RGB lookup
$existingRGBs = [];
$stmt = $pdo->query("SELECT r, g, b FROM hcl_rgb_verified");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $existingRGBs["{$row['r']},{$row['g']},{$row['b']}"] = true;
}

$insert = $pdo->prepare("
    INSERT INTO hcl_rgb_verified (hcl_hue, r, g, b, hex, hcl_l, hcl_c)
    VALUES (:hue, :r, :g, :b, :hex, :l, :c)
");

$count = 0;

// Loop through RGB as flat index (0 to 16777215), stepped
for ($i = $start; $i <= $end; $i += $step) {
    $r = ($i >> 16) & 0xFF;
    $g = ($i >> 8) & 0xFF;
    $b = $i & 0xFF;

    $rgbKey = "$r,$g,$b";
    if (isset($existingRGBs[$rgbKey])) continue;

    $hcl = RGBtoHCL($r, $g, $b);
    if (!is_numeric($hcl['hcl_h']) || !isset($hcl['hcl_l']) || !isset($hcl['hcl_c'])) continue;

    $roundedHue = round($hcl['hcl_h']);
    $hex = sprintf("#%02X%02X%02X", $r, $g, $b);

    try {
        $insert->execute([
