<?php
require_once 'db.php'; // uses existing PDO connection in $pdo

$filename = __DIR__ . '/data/swatches_checkpoint.csv';
if (!file_exists($filename)) {
    die("CSV file not found.");
}

$handle = fopen($filename, 'r');
if (!$handle) {
    die("Could not open the file.");
}

// Skip header
$headers = fgetcsv($handle);
$count = 0;

while (($row = fgetcsv($handle)) !== false) {
    // Expecting: [name, rgb_string, sw_code]
    if (count($row) < 3) continue;

    list($name, $rgb_string, $code) = $row;

    // Extract r, g, b from "rgb(r, g, b)"
    if (preg_match('/rgb\((\d+),\s*(\d+),\s*(\d+)\)/', $rgb_string, $matches)) {
        $r = (int) $matches[1];
        $g = (int) $matches[2];
        $b = (int) $matches[3];
    } else {
        continue; // Skip if RGB format is bad
    }

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO colors 
        (name, code, brand, r, g, b)
        VALUES (?, ?, 'sw', ?, ?, ?)
    ");
    
    $stmt->execute([trim($name), trim($code), $r, $g, $b]);
    $count++;
}

fclose($handle);
echo "✅ Import complete. $count rows processed.";
?>
