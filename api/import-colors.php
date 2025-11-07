<?php
require_once 'db.php'; // uses existing PDO connection in $pdo

//$filename = 'sw-colors.csv';
$filename = __DIR__ . '/data/sw-colors.csv';
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
    // Skip empty lines or malformed rows
    if (count($row) < 7) continue;

    list($name, $code, $url, $family, $r, $g, $b) = $row;

    // Clean trailing commas or whitespace
    $name = trim($name);
    $code = trim($code);
    $url  = trim($url);
    $family = trim($family); // not needed 
    $r = (int) $r;
    $g = (int) $g;
    $b = (int) $b;

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO colors 
        (name, code, brand,  color_url, r, g, b)
        VALUES (?, ?, 'sw', ?, ?, ?, ?)
    ");

    $stmt->execute([$name, $code, $url,  $r, $g, $b]);
    $count++;
}

fclose($handle);
echo "✅ Import complete. $count rows processed.";
?>
