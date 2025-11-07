<?php
// import-bm.php — Base import for BM colors (no HCL here)
// - Reads bm-details.csv
// - Parses rgb(...) -> r,g,b and hex6
// - Inserts/updates colors with: name, code, brand, brand_descr, hex6, r,g,b, lrv, color_url, brand_recommendations

require_once 'db.php';  // must define $pdo (PDO with ERRMODE_EXCEPTION)

ini_set('display_errors', '1');
error_reporting(E_ALL);

$csvFile     = __DIR__ . '/data/bm-details.csv';
$logFile     = __DIR__ . '/import-bm-error.log';
$brand       = 'bm';
$brand_descr = 'Benjamin Moore';

function logError(string $msg): void {
  global $logFile;
  @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

// --- open CSV
if (!is_file($csvFile)) {
  logError("CSV not found: $csvFile");
  exit("❌ CSV file not found.\n");
}
$handle = fopen($csvFile, 'r');
if (!$handle) {
  logError("Failed to open: $csvFile");
  exit("❌ Failed to open file.\n");
}

// Read headers (normalize: trim, lowercase, strip BOM)
$headers = fgetcsv($handle);
if (!$headers) {
  logError("No headers in CSV");
  exit("❌ No headers.\n");
}
$headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]); // strip UTF-8 BOM
$headers = array_map('trim', $headers);
$map = array_change_key_case(array_flip($headers), CASE_LOWER);

$inserted = 0;
$updated  = 0;
$skipped  = 0;

// Prepared statements (upsert by brand+code)
$sel = $pdo->prepare("SELECT id FROM colors WHERE brand = :brand AND code = :code LIMIT 1");
$ins = $pdo->prepare("
  INSERT INTO colors
    (name, code, brand, brand_descr, hex6, r, g, b, lrv, color_url, brand_recommendations)
  VALUES
    (:name, :code, :brand, :brand_descr, :hex6, :r, :g, :b, :lrv, :url, :recs)
");
$upd = $pdo->prepare("
  UPDATE colors
     SET name = :name,
         brand_descr = :brand_descr,
         hex6 = :hex6,
         r = :r, g = :g, b = :b,
         lrv = :lrv,
         color_url = :url,
         brand_recommendations = :recs
   WHERE id = :id
");

while (($row = fgetcsv($handle)) !== false) {
  if (count($row) !== count($headers)) {
    logError("Header/data length mismatch: " . json_encode($row));
    $skipped++;
    continue;
  }

  // Helper to fetch a field by name (case-insensitive)
  $get = function(string $key) use ($row, $map) {
    $k = strtolower($key);
    return array_key_exists($k, $map) ? trim((string)$row[$map[$k]]) : null;
  };

  $name = $get('name');
  $code = $get('code');
  $rgb  = $get('rgb');         // e.g. 'rgb(123, 45, 67)'
  $url  = $get('detail_url');  // stored into colors.color_url
  $lrv  = $get('lrv');         // may be '23%' or '23'
  $recs = $get('recommendations');

  if ($name === null || $name === '' || $code === null || $code === '') {
    logError("Missing name/code: " . json_encode(['name'=>$name, 'code'=>$code]));
    $skipped++;
    continue;
  }

  // Robust parse of rgb(...) with whitespace
  if (!preg_match('/rgb\s*\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)/i', (string)$rgb, $m)) {
    logError("Invalid RGB '{$rgb}' for {$brand} {$code} {$name}");
    $skipped++;
    continue;
  }
  $r = (int)$m[1];
  $g = (int)$m[2];
  $b = (int)$m[3];
  if ($r < 0 || $r > 255 || $g < 0 || $g > 255 || $b < 0 || $b > 255) {
    logError("RGB out of range '{$rgb}' for {$brand} {$code} {$name}");
    $skipped++;
    continue;
  }

  // Compute hex6 (uppercase, zero-padded)
  $hex6 = strtoupper(sprintf('%02X%02X%02X', $r, $g, $b));

  // Clean LRV to numeric (nullable)
  $lrvVal = null;
  if ($lrv !== null && $lrv !== '') {
    $clean = preg_replace('/[^0-9.]+/', '', $lrv);
    $lrvVal = ($clean === '') ? null : (float)$clean;
  }

  try {
    // Upsert by (brand, code)
    $sel->execute([':brand' => $brand, ':code' => $code]);
    $existing = $sel->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
      $upd->execute([
        ':id'           => (int)$existing['id'],
        ':name'         => $name,
        ':brand_descr'  => $brand_descr,
        ':hex6'         => $hex6,
        ':r'            => $r,
        ':g'            => $g,
        ':b'            => $b,
        ':lrv'          => $lrvVal,
        ':url'          => $url,
        ':recs'         => $recs,
      ]);
      $updated++;
    } else {
      $ins->execute([
        ':name'         => $name,
        ':code'         => $code,
        ':brand'        => $brand,
        ':brand_descr'  => $brand_descr,
        ':hex6'         => $hex6,
        ':r'            => $r,
        ':g'            => $g,
        ':b'            => $b,
        ':lrv'          => $lrvVal,
        ':url'          => $url,
        ':recs'         => $recs,
      ]);
      $inserted++;
    }
  } catch (Throwable $e) {
    logError("DB error for {$brand} {$code} {$name}: " . $e->getMessage());
    $skipped++;
  }
}

fclose($handle);

echo "✅ Import complete.\n";
echo "✅ Inserted: $inserted\n";
echo "🔄 Updated:  $updated\n";
echo "⚠️  Skipped:  $skipped\n";
echo "📄 Log: $logFile\n";
