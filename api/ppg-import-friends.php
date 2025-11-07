<?php
/**
 * import-ppg-friends.php
 * Reads: data/ppg-colors-details.csv
 * Expects header incl: name, accent_friends, trim_friends
 * Writes edges to color_friends(hex1, hex2, source) with source='ppg site'
 */

require_once 'db.php';

$csvFile = __DIR__ . '/data/ppg-colors-details.csv';
if (!file_exists($csvFile)) {
    die("<h2>❌ CSV file not found: $csvFile</h2>");
}

function titleCase(string $s): string {
    return ucwords(mb_strtolower(trim($s)));
}

function parseFriendList(?string $s): array {
    // e.g. "SOURDOUGH (PPG1084-3); MAGIC DUST (PPG13-24)"
    if (!$s) return [];
    $parts = array_filter(array_map('trim', explode(';', $s)));
    $names = [];
    foreach ($parts as $p) {
        if ($p === '') continue;
        if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/', $p, $m)) {
            $name = titleCase($m[1]);
        } else {
            $name = titleCase($p);
        }
        if ($name !== '') $names[$name] = true; // de-dupe by name
    }
    return array_keys($names);
}

// Prepared statements
$sqlHexByName = $pdo->prepare("SELECT hex6 FROM colors WHERE brand='ppg' AND name = ? LIMIT 1");
$sqlInsertEdge = $pdo->prepare("
    INSERT IGNORE INTO color_friends (hex1, hex2, source)
    VALUES (?, ?, 'ppg site')
");

// Lookup cache
$hexCache = []; // name => hex6 or '' if missing
function lookupHex6(PDOStatement $stmt, array &$cache, string $name): ?string {
    if (array_key_exists($name, $cache)) {
        return $cache[$name] ?: null;
    }
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $hex6 = $row['hex6'] ?? '';
    $cache[$name] = $hex6; // cache empty string for miss
    return $hex6 ?: null;
}

function insertPair(PDOStatement $stmt, string $a, string $b): int {
    if ($a === '' || $b === '' || $a === $b) return 0;
    $hex1 = ($a <= $b) ? $a : $b;
    $hex2 = ($a <= $b) ? $b : $a;
    $stmt->execute([$hex1, $hex2]);
    return $stmt->rowCount(); // 1 if new, 0 if duplicate ignored
}

$h = fopen($csvFile, 'r');
if (!$h) die("<h2>❌ Unable to open CSV file</h2>");

$header = fgetcsv($h);
if (!$header) die("<h2>❌ Empty CSV</h2>");

// case-insensitive header map
$idx = [];
foreach ($header as $i => $col) $idx[strtolower(trim($col))] = $i;

$anchorsProcessed   = 0;
$anchorsNotFound    = 0;
$friendLookupsMiss  = 0;
$pairsAttempted     = 0;
$pairsInserted      = 0;

while (($row = fgetcsv($h)) !== false) {
    $nameCol = $idx['name'] ?? null;
    if ($nameCol === null) break;

    $anchorName = titleCase($row[$nameCol] ?? '');
    if ($anchorName === '') continue;

    $anchorsProcessed++;

    $anchorHex6 = lookupHex6($sqlHexByName, $hexCache, $anchorName);
    if (!$anchorHex6) {
        $anchorsNotFound++;
        error_log("PPG friends import: anchor not found in colors: '{$anchorName}'");
        continue;
    }

    // Combine accents + trims into a single group
    $accentNames = parseFriendList($row[$idx['accent_friends']] ?? '');
    $trimNames   = parseFriendList($row[$idx['trim_friends']] ?? '');
    $allNamesMap = [];
    foreach ($accentNames as $n) $allNamesMap[$n] = true;
    foreach ($trimNames as $n)   $allNamesMap[$n] = true;
    $allFriends = array_keys($allNamesMap);

    // Resolve friend hex6s
    $resolved = []; // list of hex6
    foreach ($allFriends as $friendName) {
        if ($friendName === $anchorName) continue;
        $fHex6 = lookupHex6($sqlHexByName, $hexCache, $friendName);
        if (!$fHex6) {
            $friendLookupsMiss++;
            error_log("PPG friends import: friend not found: '{$friendName}' (anchor '{$anchorName}')");
            continue;
        }
        if ($fHex6 === $anchorHex6) continue;
        $resolved[$fHex6] = true; // de-dupe by hex
    }
    $resolved = array_keys($resolved);
    $n = count($resolved);
    if ($n === 0) continue;

    // Anchor <-> each friend
    foreach ($resolved as $hex) {
        $pairsAttempted++;
        $pairsInserted += insertPair($sqlInsertEdge, $anchorHex6, $hex);
    }

    // Full mesh among all friends (accent+trim combined)
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $pairsAttempted++;
            $pairsInserted += insertPair($sqlInsertEdge, $resolved[$i], $resolved[$j]);
        }
    }
}
fclose($h);

$dupesIgnored = $pairsAttempted - $pairsInserted;

echo "<h2>✅ PPG friends import complete</h2>";
echo "<p>Anchors processed: {$anchorsProcessed}</p>";
echo "<p>Anchors not found in colors: {$anchorsNotFound}</p>";
echo "<p>Friend lookups not found: {$friendLookupsMiss}</p>";
echo "<p>Pairs attempted: {$pairsAttempted}</p>";
echo "<p>Pairs inserted (new): {$pairsInserted}</p>";
echo "<p>Pairs duplicates ignored: {$dupesIgnored}</p>";
echo "<p>Source: {$csvFile}</p>";
