<?php
// import-bm-friends.php
// Reads bm-friends-output.csv and inserts into color_friends
// Writes skipped rows to bm-friends-skipped.csv for manual review

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db.php'; // must define $pdo

$INPUT_CSV  = __DIR__ . '/data/bm-friends-output.csv';
$SKIPPED_CSV = __DIR__ . '/data/bm-friends-skipped.csv';

// --- Helpers ---
function normalize_hex(?string $h): ?string {
    if ($h === null) return null;
    $h = strtoupper(trim($h));
    $h = ltrim($h, '#');
    if (!preg_match('/^[0-9A-F]{6}$/', $h)) return null;
    return $h;
}

function unique_pairs(array $hexes): array {
    $pairs = [];
    $n = count($hexes);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = $hexes[$i];
            $b = $hexes[$j];
            if ($a < $b) { $hex1 = $a; $hex2 = $b; }
            else         { $hex1 = $b; $hex2 = $a; }
            $pairs[] = [$hex1, $hex2];
        }
    }
    return $pairs;
}

// --- Open files ---
if (!file_exists($INPUT_CSV)) {
    fwrite(STDERR, "CSV not found: $INPUT_CSV\n");
    exit(1);
}

$fh = fopen($INPUT_CSV, 'r');
if (!$fh) {
    fwrite(STDERR, "Unable to open CSV: $INPUT_CSV\n");
    exit(1);
}
$skippedFh = fopen($SKIPPED_CSV, 'w');
if (!$skippedFh) {
    fwrite(STDERR, "Unable to open skipped CSV for writing: $SKIPPED_CSV\n");
    exit(1);
}

// Read header
$header = fgetcsv($fh);
if (!$header) {
    fwrite(STDERR, "CSV appears empty.\n");
    exit(1);
}
// Write header to skipped file too
fputcsv($skippedFh, $header);

// Map headers
$hmap = [];
foreach ($header as $idx => $name) {
    $hmap[strtolower(trim($name))] = $idx;
}

// --- Prepare SQL ---
$sql = "
    INSERT INTO color_friends (hex1, hex2, source, notes)
    VALUES (:hex1, :hex2, :source, :notes)
    ON DUPLICATE KEY UPDATE
      source = VALUES(source),
      notes  = VALUES(notes)
";
$stmt = $pdo->prepare($sql);

// --- Counters ---
$seen = [];
$rows = 0;
$pairs_total = 0;
$inserted = 0;
$updated = 0;
$skipped = 0;

while (($row = fgetcsv($fh)) !== false) {
    $rows++;

    $anchor  = normalize_hex($row[$hmap['anchor_hex']] ?? null);
    $f1      = normalize_hex($row[$hmap['friend1_hex']] ?? null);
    $f2      = normalize_hex($row[$hmap['friend2_hex']] ?? null);
    $source  = trim((string)($row[$hmap['source']] ?? ''));
    $notes   = trim((string)($row[$hmap['notes']]  ?? ''));

    $hexes = array_values(array_unique(array_filter([$anchor, $f1, $f2])));
    if (count($hexes) < 2) {
        $skipped++;
        fputcsv($skippedFh, $row);
        continue;
    }

    $pairs = unique_pairs($hexes);
    foreach ($pairs as [$hex1, $hex2]) {
        $key = $hex1 . '-' . $hex2;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $pairs_total++;

        try {
            $stmt->execute([
                ':hex1'   => $hex1,
                ':hex2'   => $hex2,
                ':source' => $source ?: 'Benjamin Moore site',
                ':notes'  => $notes  ?: 'Scraped from Coordinating Colors'
            ]);
            $lid = $pdo->lastInsertId();
            if ($lid) $inserted++;
            else      $updated++;
        } catch (PDOException $e) {
            $skipped++;
            fputcsv($skippedFh, $row);
            fwrite(STDERR, "Skip pair {$hex1}-{$hex2}: {$e->getMessage()}\n");
        }
    }
}

fclose($fh);
fclose($skippedFh);

echo "=== Import complete ===\n";
echo "CSV rows read:      {$rows}\n";
echo "Pairs generated:    {$pairs_total}\n";
echo "Inserted (approx):  {$inserted}\n";
echo "Updated (approx):   {$updated}\n";
echo "Rows skipped:       {$skipped}\n";
echo "Skipped file:       {$SKIPPED_CSV}\n";
