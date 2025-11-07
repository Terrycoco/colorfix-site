<?php
require_once 'db.php';

$csvFile = __DIR__ . '/data/bm-details.csv';
$handle = fopen($csvFile, 'r');
if (!$handle) {
    die("Failed to open file: $csvFile");
}

// Skip the header
$headers = fgetcsv($handle);

$updated = 0;
$skipped = 0;

while (($row = fgetcsv($handle)) !== false) {
    $data = array_combine($headers, $row);
    $name = trim($data['name']);
    $collection = trim($data['collection']);

    if (!$collection || !$name) {
        $skipped++;
        continue;
    }

    // Update the colors table
    $stmt = $pdo->prepare("UPDATE colors SET collection = :collection WHERE name = :name AND brand = 'bm'");
    $stmt->execute(['collection' => $collection, 'name' => $name]);

    if ($stmt->rowCount() > 0) {
        $updated++;
    } else {
        $skipped++;
    }
}

fclose($handle);

echo "✅ Updated $updated colors. Skipped $skipped rows.\n";
?>
