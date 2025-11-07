<?php
// import-vist-colors.php
require_once 'db.php';

$csvFile = __DIR__ . '/data/vist-colors.csv';
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
function toHex6FromHexOrRGB(?string $hex, $r, $g, $b): string {
    $hex = strtoupper(trim((string)$hex));
    if ($hex !== '') {
        $h = ltrim($hex, '#');
        if (preg_match('/^[0-9A-F]{6}$/', $h)) return $h;
    }
    if ($r !== '' && $g !== '' && $b !== '' && $r !== null && $g !== null && $b !== null) {
        return hexFromRGB((int)$r, (int)$g, (int)$b);
    }
    return '';
}

$h = fopen($csvFile, 'r');
if (!$h) die("<h2>❌ Unable to open CSV file</h2>");

$header = fgetcsv($h);
if (!$header) die("<h2>❌ Empty CSV</h2>");

// map headers (case-insensitive)
$idx = [];
foreach ($header as $i => $col) $idx[strtolower(trim($col))] = $i;

// prepare insert
$sql = "INSERT IGNORE INTO colors
(name, code, brand, r, g, b, hex6, color_url)
VALUES (?, ?, 'vist', ?, ?, ?, ?, ?)";
$stmt = $pdo->prepare($sql);

$attempted = 0;
$inserted  = 0;

while (($row = fgetcsv($h)) !== false) {
    $name = isset($idx['name']) ? titleCase($row[$idx['name']] ?? '') : '';
    $code = isset($idx['code']) ? strtoupper(trim($row[$idx['code']] ?? '')) : '';
    $r    = isset($idx['r'])    ? clampInt($row[$idx['r']] ?? '') : 0;
    $g    = isset($idx['g'])    ? clampInt($row[$idx['g']] ?? '') : 0;
    $b    = isset($idx['b'])    ? clampInt($row[$idx['b']] ?? '') : 0;
    $hex  = isset($idx['hex'])  ? trim($row[$idx['hex']] ?? '')   : '';
    $url  = isset($idx['url'])  ? trim($row[$idx['url']] ?? '')   : '';

    // compute hex6 from hex (preferred) or RGB
    $hex6 = toHex6FromHexOrRGB($hex, $r, $g, $b);

    // minimal sanity: need name + hex6
    if ($name === '' || $hex6 === '') continue;

    $attempted++;
    try {
        $stmt->execute([$name, $code, $r, $g, $b, $hex6, $url]);
        $inserted += $stmt->rowCount();
    } catch (Throwable $e) {
        error_log("VIST import error on '{$name}': " . $e->getMessage());
    }
}
fclose($h);

$dupes = $attempted - $inserted;

echo "<h2>✅ VIST import complete</h2>";
echo "<p>Attempted: {$attempted} rows (after header)</p>";
echo "<p>Inserted (new): {$inserted}</p>";
echo "<p>Duplicates ignored: {$dupes}</p>";
echo "<p>Source: {$csvFile}</p>";
