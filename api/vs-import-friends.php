<?php
/**
 * Build undirected friend pairs from Valspar coordinating-color groups.
 * - Input: data/valspar_friends.csv
 *   Header (from scraper): key_id,key_name,key_hex6,key_url,friend_name,friend_hex6,friend_url,relationship
 * - Logic: For each anchor group (key_*), take its friend list {F1..Fn} and insert all pairs (Fi, Fj), i<j
 * - Pairs go to color_friends(hex1, hex2, source, notes) with hex1 < hex2; source='vs site'
 * - Safe to re-run (unique index prevents duplicates).
 */

declare(strict_types=1);

// -------- Config --------
$DEFAULT_INPUT = __DIR__ . '/data/valspar_friends.csv';
$SOURCE = 'vs site';
$ONLY_EXISTING_COLORS = false; // set true to only keep pairs where both hexes exist in `colors`

// -------- Bootstrap --------
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

require_once  'db.php'; // must define $pdo (PDO)
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo "DB bootstrap failed.\n";
  exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// -------- Input --------
$inputPath = $argv[1] ?? $DEFAULT_INPUT;
if (!is_file($inputPath)) {
  echo "FATAL: CSV not found: $inputPath\n";
  exit(1);
}
echo "Starting friends import from: $inputPath\n";

// -------- Ensure table & index --------
ensureTable($pdo);
ensureUniqueIndex($pdo, 'color_friends', 'uniq_hex1_hex2_source', ['hex1','hex2','source']);

// Optional: preload existing color HEXes to filter pairs
$existingHex = [];
if ($ONLY_EXISTING_COLORS) {
  $existingHex = loadExistingHex($pdo);
}

// -------- Read CSV into anchor groups --------
[$hdr, $rows] = readCsvAssoc($inputPath);
$need = ['key_url','key_hex6','key_name','friend_hex6','friend_name'];
foreach ($need as $k) {
  if (!array_key_exists($k, $hdr)) {
    echo "FATAL: Missing CSV column: $k\n";
    exit(1);
  }
}

// Group: anchorKey => set of friend hexes (uppercase, 6)
$groups = [];
$totalRows = 0;
$badRows = 0;
foreach ($rows as $r) {
  $totalRows++;

  $keyUrl  = trim((string)($r['key_url'] ?? ''));
  $keyHex  = strtoupper(trim((string)($r['key_hex6'] ?? '')));
  $keyName = normalizeName($r['key_name'] ?? '');

  // anchor grouping key preference: URL > HEX > NAME
  $anchorKey = $keyUrl !== '' ? "url:".normalizeUrl($keyUrl)
             : ($keyHex !== '' ? "hex:".$keyHex
             : ($keyName !== '' ? "name:".$keyName : ''));

  $friendHex = strtoupper(trim((string)($r['friend_hex6'] ?? '')));
  if (!preg_match('/^[0-9A-F]{6}$/', $friendHex)) {
    $badRows++; // skip friends with no valid hex
    continue;
  }

  if ($ONLY_EXISTING_COLORS && (!isset($existingHex[$friendHex]))) {
    continue; // skip if not in colors table
  }

  if ($anchorKey === '') {
    $badRows++;
    continue;
  }

  if (!isset($groups[$anchorKey])) $groups[$anchorKey] = [];
$groups[$anchorKey][] = $friendHex; // keep as a list of strings
}

echo "Anchors grouped: ".count($groups)." | CSV rows: $totalRows | invalid rows: $badRows\n";

// -------- Create pairs & insert --------
$insSql = <<<SQL
INSERT INTO color_friends (hex1, hex2, source, notes)
VALUES (:h1, :h2, :src, :notes)
ON DUPLICATE KEY UPDATE notes = VALUES(notes)
SQL;
$ins = $pdo->prepare($insSql);

$inserted = 0;
$skipped  = 0;
$anchorsProcessed = 0;

$pdo->beginTransaction();
try {
  foreach ($groups as $anchorKey => $set) {
    $anchorsProcessed++;

    // unique friend list for this anchor
   $friends = array_values(array_unique($set, SORT_STRING)); // dedupe as strings

    // Optional filter to ensure friends exist in colors
 if ($ONLY_EXISTING_COLORS && !isset($existingHex['~' . $friendHex])) {
    continue;
}

    $n = count($friends);
    if ($n < 2) continue; // need at least two to form a pair

    // all nC2 pairs
    for ($i = 0; $i < $n; $i++) {
      for ($j = $i + 1; $j < $n; $j++) {
        [$h1, $h2] = orderHex($friends[$i], $friends[$j]);
        if ($h1 === $h2) { $skipped++; continue; }

        $ins->execute([
          ':h1'   => $h1,
          ':h2'   => $h2,
          ':src'  => $SOURCE,
          ':notes'=> null, // keep null; you can add context later
        ]);
        $inserted += ($ins->rowCount() > 0) ? 1 : 0; // on duplicate update, rowCount may be 2; fine either way
      }
    }
  }
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  echo "FATAL: Transaction failed: ".$e->getMessage()."\n";
  exit(1);
}

echo "✅ Done. anchorsProcessed=$anchorsProcessed pairsInserted~$inserted skipped=$skipped\n";
exit(0);

/* ================= Helpers ================= */

function ensureTable(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS color_friends (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      hex1 CHAR(6) NOT NULL,
      hex2 CHAR(6) NOT NULL,
      source VARCHAR(64) NULL,
      notes TEXT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
}

function ensureUniqueIndex(PDO $pdo, string $table, string $indexName, array $cols): void {
  $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i");
  $st->execute([':t'=>$table, ':i'=>$indexName]);
  if ((int)$st->fetchColumn() > 0) return;

  $colsSql = implode('`,`', $cols);
  $pdo->exec("CREATE UNIQUE INDEX `$indexName` ON `$table` (`$colsSql`)");
}

function readCsvAssoc(string $path): array {
  $fh = fopen($path, 'rb');
  if (!$fh) throw new RuntimeException("Cannot open CSV: $path");

  $header = fgetcsv($fh);
  if ($header === false) throw new RuntimeException("Empty CSV: $path");

  $map = [];
  foreach ($header as $i => $h) { $map[strtolower(trim($h))] = $i; }

  $rows = [];
  while (($row = fgetcsv($fh)) !== false) {
    if (count($row) === 1 && trim($row[0]) === '') continue;
    $assoc = [];
    foreach ($map as $k => $i) { $assoc[$k] = $row[$i] ?? ''; }
    $rows[] = $assoc;
  }
  fclose($fh);
  return [$map, $rows];
}

function loadExistingHex(PDO $pdo): array {
  $out['~' . $h] = true;  
  $sql = "SELECT UPPER(hex6) AS h FROM colors WHERE hex6 IS NOT NULL AND hex6 <> ''";
  foreach ($pdo->query($sql) as $row) {
    $h = strtoupper((string)$row['h']);
    if (preg_match('/^[0-9A-F]{6}$/', $h)) $out[$h] = true;
  }
  return $out;
}

function orderHex(string $a, string $b): array {
  $a = strtoupper($a); $b = strtoupper($b);
  if (strcmp($a, $b) <= 0) return [$a, $b];
  return [$b, $a];
}

function normalizeUrl(string $u): string {
  $u = trim($u);
  if ($u === '') return '';
  try {
    $x = new URL($u);
    $x->hash = '';
    $path = rtrim($x->pathname, '/');
    return $x->protocol . '//' . strtolower($x->hostname) . $path . $x->search;
  } catch (Throwable $e) {
    return rtrim($u, '/');
  }
}

function normalizeName($s): string {
  $s = trim((string)$s);
  return preg_replace('/\s+/u', ' ', $s);
}
