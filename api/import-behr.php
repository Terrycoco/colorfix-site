<?php
require_once 'db.php';

$csvFile = __DIR__ . '/data/behr-5300-colors.csv';
if (!file_exists($csvFile)) {
    die("<h2>❌ CSV file not found: $csvFile</h2>");
}

$handle = fopen($csvFile, 'r');
if (!$handle) {
    die("<h2>❌ Unable to open CSV file</h2>");
}

$lineNumber = 0;
$inserted = 0;

while (($row = fgetcsv($handle)) !== false) {
    $lineNumber++;
    if ($lineNumber === 1) continue; // skip header

    [$name, $code, $rgb] = $row;
    [$r, $g, $b] = explode(',', $rgb);

    $stmt = $pdo->prepare("INSERT IGNORE INTO colors (name, code, brand, r, g, b) VALUES (?, ?, 'behr', ?, ?, ?)");
    $stmt->execute([$name, $code, $r, $g, $b]);
    $inserted++;
}

fclose($handle);
echo "<h2>✅ Attempted import of $inserted Behr colors (duplicates ignored)</h2>";
