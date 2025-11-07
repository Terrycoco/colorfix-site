<?php
// import-vist-friends.php
require_once 'db.php';

$csvFile = __DIR__ . '/data/vist-friends.csv';
if (!file_exists($csvFile)) {
    die("<h2>❌ CSV file not found: $csvFile</h2>");
}

function norm_hex6(string $h): string {
    $h = strtoupper(trim($h));
    if (!preg_match('/^[0-9A-F]{6}$/', $h)) return '';
    return $h;
}
function titleCase(string $s): string {
    return ucwords(mb_strtolower(trim($s)));
}
function insertPair(PDOStatement $stmt, string $a, string $b): int {
    if ($a === '' || $b === '' || $a === $b) return 0;
    $hex1 = ($a <= $b) ? $a : $b;
    $hex2 = ($a <= $b) ? $b : $a;
    $stmt->execute([$hex1, $hex2]);
    return $stmt->rowCount(); // 1 new, 0 duplicate (INSERT IGNORE)
}

$h = fopen($csvFile, 'r');
if (!$h) die("<h2>❌ Unable to open CSV file</h2>");

$header = fgetcsv($h);
if (!$header) die("<h2>❌ Empty CSV</h2>");

// header map (case-insensitive)
$idx = [];
foreach ($header as $i => $col) $idx[strtolower(trim($col))] = $i;

// Prepared statements
$sqlHexByName = $pdo->prepare("SELECT hex6 FROM colors WHERE brand='vist' AND name = ? LIMIT 1");
$sqlInsertEdge = $pdo->prepare("
  INSERT IGNORE INTO color_friends (hex1, hex2, source)
  VALUES (?, ?, 'vist site')
");

// accumulators
$rowsRead            = 0;
$anchorsProcessed    = 0;
$schemesSkipped      = 0;
$anchorsMissingHex   = 0;
$friendLookupsMiss   = 0;
$pairsAttempted      = 0;
$pairsInserted       = 0;

// Process grouped by (anchor_name, scheme) so we can mesh friends within the same scheme
// We'll read the CSV and build a map: key = anchor_name|scheme -> [anchor_hex6, friends[]]
$groups = []; // key => ['anchor_hex6' => ..., 'friends' => [friend_name,...]]

while (($row = fgetcsv($h)) !== false) {
    $rowsRead++;

    $anchor_name = isset($idx['anchor_name']) ? titleCase($row[$idx['anchor_name']] ?? '') : '';
    $anchor_hex6 = isset($idx['anchor_hex6']) ? norm_hex6($row[$idx['anchor_hex6']] ?? '') : '';
    $friend_name = isset($idx['friend_name']) ? titleCase($row[$idx['friend_name']] ?? '') : '';
    $scheme      = isset($idx['scheme']) ? (int)($row[$idx['scheme']] ?? 0) : 0;

    // Skip "family" scheme
    if ($scheme === 1) {
        $schemesSkipped++;
        continue;
    }

    if ($anchor_name === '') continue; // nothing to do
    if ($anchor_hex6 === '') {
        $anchorsMissingHex++;
        continue;
    }

    $key = $anchor_name . '|' . $scheme;
    if (!isset($groups[$key])) {
        $groups[$key] = ['anchor_hex6' => $anchor_hex6, 'friends' => []];
    }
    if ($friend_name !== '') {
        $groups[$key]['friends'][$friend_name] = true; // dedupe by name within scheme
    }
}
fclose($h);

// Now iterate groups and insert edges
foreach ($groups as $key => $bundle) {
    $anchorsProcessed++;
    $anchor_hex6 = $bundle['anchor_hex6'];
    $friendNames = array_keys($bundle['friends']);

    // Resolve friend hex6s by (brand='vist', name)
    $resolved = [];
    foreach ($friendNames as $fname) {
        $sqlHexByName->execute([$fname]);
        $row = $sqlHexByName->fetch(PDO::FETCH_ASSOC);
        $fhex = $row['hex6'] ?? '';
        if ($fhex === '' || $fhex === $anchor_hex6) {
            if ($fhex === '') $friendLookupsMiss++;
            continue;
        }
        $resolved[$fhex] = true; // dedupe by hex
    }
    $resolvedHexes = array_keys($resolved);
    $n = count($resolvedHexes);
    if ($n === 0) continue;

    // Anchor ↔ each friend
    foreach ($resolvedHexes as $fh) {
        $pairsAttempted++;
        $pairsInserted += insertPair($sqlInsertEdge, $anchor_hex6, $fh);
    }

    // Full mesh among friends within the same scheme
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $pairsAttempted++;
            $pairsInserted += insertPair($sqlInsertEdge, $resolvedHexes[$i], $resolvedHexes[$j]);
        }
    }
}

$dupesIgnored = $pairsAttempted - $pairsInserted;

echo "<h2>✅ VIST friends import complete</h2>";
echo "<p>CSV rows read: {$rowsRead}</p>";
echo "<p>Anchors processed (schemes != 1): {$anchorsProcessed}</p>";
echo "<p>Schemes skipped (scheme=1): {$schemesSkipped}</p>";
echo "<p>Anchors missing hex6 (skipped): {$anchorsMissingHex}</p>";
echo "<p>Friend lookups not found: {$friendLookupsMiss}</p>";
echo "<p>Pairs attempted: {$pairsAttempted}</p>";
echo "<p>Pairs inserted (new): {$pairsInserted}</p>";
echo "<p>Pairs duplicates ignored: {$dupesIgnored}</p>";
echo "<p>Source: {$csvFile}</p>";
