<?php
// import-ppg-colors.php
require_once 'db.php';

$csvFile = __DIR__ . '/data/ppg-colors.csv';
if (!file_exists($csvFile)) {
    die("<h2>❌ CSV file not found: $csvFile</h2>");
}

function titleCase(string $s): string {
    return ucwords(mb_strtolower(trim($s)));
}
function clampInt($v, $min=0, $max=255): int {
    $n = (int)preg_replace('/[^\d\-]/', '', (string)$v);
    return max($min, min($max, $n));
}
function hexFromRGB(int $r, int $g, int $b): string {
    return strtoupper(sprintf('%02X%02X%02X', $r, $g, $b));
}

$handle = fopen($csvFile, 'r');
if (!$handle) {
    die("<h2>❌ Unable to open CSV file</h2>");
}

$line = 0;
$attempted = 0;
$inserted = 0;

// Phase-1 header expected:
// name,code,r,g,b,hex,brand,url
$header = fgetcsv($handle);
if (!$header) {
    die("<h2>❌ Empty CSV</h2>");
}
// Build a lowercase->index map for safety
$idx = [];
foreach ($header as $i => $col) {
    $idx[strtolower(trim($col))] = $i;
}

// Prepare insert
$sql = "INSERT IGNORE INTO colors
(name, code, brand, r, g, b, hex6, color_url)
VALUES (?, ?, 'ppg', ?, ?, ?, ?, ?)";
$stmt = $pdo->prepare($sql);

while (($row = fgetcsv($handle)) !== false) {
    $line++;

    $name = isset($idx['name']) ? titleCase($row[$idx['name']] ?? '') : '';
    $code = isset($idx['code']) ? strtoupper(trim($row[$idx['code']] ?? '')) : '';
    $r    = isset($idx['r'])    ? clampInt($row[$idx['r']] ?? 0) : 0;
    $g    = isset($idx['g'])    ? clampInt($row[$idx['g']] ?? 0) : 0;
    $b    = isset($idx['b'])    ? clampInt($row[$idx['b']] ?? 0) : 0;
    $hex  = isset($idx['hex'])  ? strtoupper(trim($row[$idx['hex']] ?? '')) : '';
    $url  = isset($idx['url'])  ? trim($row[$idx['url']] ?? '') : '';

    // Normalize hex/hex6 (compute from RGB if hex missing)
    if ($hex === '' || $hex === '#') {
        $hex6 = hexFromRGB($r, $g, $b);
        $hex  = '#' . $hex6;
    } else {
        // Strip leading '#' if present
        $hex6 = ltrim($hex, '#');
        $hex6 = strtoupper($hex6);
        $hex  = '#' . $hex6;
    }

    // Basic sanity: must have name & code
    if ($name === '' || $code === '') {
        continue;
    }

    $attempted++;
    try {
      $stmt->execute([$name, $code, $r, $g, $b, $hex6, $url]);
        $inserted += $stmt->rowCount(); // INSERT IGNORE returns 1 for new, 0 for dup
    } catch (Throwable $e) {
        // Soft-fail, continue
        error_log("PPG import error on line {$line}: " . $e->getMessage());
    }
}
fclose($handle);

echo "<h2>✅ PPG import complete</h2>";
echo "<p>Attempted: {$attempted} rows (after header)</p>";
echo "<p>Inserted (new): {$inserted} (duplicates ignored)</p>";
echo "<p>Source: {$csvFile}</p>";
