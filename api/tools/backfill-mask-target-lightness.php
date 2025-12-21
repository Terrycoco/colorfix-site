<?php
declare(strict_types=1);

// Run with: php api/tools/backfill-mask-target-lightness.php [--asset=PHO_ABC123] [--force]
if (PHP_SAPI !== 'cli') {
    exit("Run this script from the command line.\n");
}

require __DIR__ . '/../autoload.php';
require __DIR__ . '/../db.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(STDERR, "DB not initialized via api/db.php\n");
    exit(1);
}

$assetId = '';
$force = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--asset=')) {
        $assetId = trim(substr($arg, 8));
    } elseif ($arg === '--force') {
        $force = true;
    }
}

$where = ["m.color_id IS NOT NULL", "COALESCE(c.hcl_l, c.lab_l) IS NOT NULL"];
$params = [];
if ($assetId !== '') {
    $where[] = "m.asset_id = :asset_id";
    $params[':asset_id'] = $assetId;
}
if (!$force) {
    $where[] = "m.target_lightness IS NULL";
}

$sql = "
UPDATE mask_blend_settings m
JOIN colors c ON c.id = m.color_id
SET
  m.target_lightness = ROUND(COALESCE(c.hcl_l, c.lab_l), 0),
  m.target_h = c.hcl_h,
  m.target_c = c.hcl_c
WHERE " . implode(' AND ', $where);

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $count = $stmt->rowCount();
    echo "✅ updated rows: {$count}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Failed to backfill target lightness: " . $e->getMessage() . "\n");
    exit(1);
}
