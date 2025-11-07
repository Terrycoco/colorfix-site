<?php declare(strict_types=1);
/**
 * sw-insert-friends.php
 * Insert Sherwin-Williams friend pairs into EXISTING color_friends(hex1, hex2).
 *
 * Reads one or more CSVs (default: ./data/sw-friend-edges.csv),
 * normalizes each pair so hex1 < hex2, de-dupes, and inserts.
 *
 * CSV header expected (from sw-friends.js):
 *   source_anchor_slug,source_anchor_code,source_anchor_name,source_family,source_url,
 *   a_slug,a_code,a_name,a_rgb,a_hex,
 *   b_slug,b_code,b_name,b_rgb,b_hex
 *
 * Usage:
 *   php sw-insert-friends.php
 *   php sw-insert-friends.php --in=./data/sw-friend-edges*.csv
 *   php sw-insert-friends.php --dry-run --verbose
 */

require_once __DIR__ . '/db.php'; // must define $pdo (PDO)
if (!isset($pdo) || !($pdo instanceof PDO)) {
  fwrite(STDERR, "db.php did not provide a valid \$pdo\n");
  exit(2);
}

// -------- args --------
$args = [];
foreach ($argv as $i => $arg) {
  if ($i === 0 || strpos($arg, '--') !== 0) continue;
  [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
  $args[$k] = $v === null ? true : $v;
}
$inArg   = $args['in'] ?? (__DIR__ . '/data/sw-friend-edges.csv'); // glob ok
$dryRun  = !empty($args['dry-run']);
$verbose = !empty($args['verbose']);

// -------- helpers --------
function normalize_hex6(?string $s): string {
  $h = strtoupper(ltrim((string)$s, '#'));
  return preg_match('/^[0-9A-F]{6}$/', $h) ? $h : '';
}
function parse_csv_line(string $line): array {
  $out = []; $cur=''; $inQ=false; $len=strlen($line);
  for ($i=0; $i<$len; $i++) {
    $ch = $line[$i];
    if ($inQ) {
      if ($ch === '"') {
        if ($i+1<$len && $line[$i+1]==='"') { $cur.='"'; $i++; } else { $inQ=false; }
      } else { $cur .= $ch; }
    } else {
      if ($ch === '"') { $inQ = true; }
      elseif ($ch === ',') { $out[] = $cur; $cur=''; }
      else { $cur .= $ch; }
    }
  }
  $out[] = $cur;
  return $out;
}
function read_edges_csv(string $path, bool $verbose=false): array {
  $pairs = [];
  $fh = @fopen($path, 'r');
  if (!$fh) { if ($verbose) fwrite(STDERR, "Cannot open $path\n"); return $pairs; }
  $header = null;
  while (($line = fgets($fh)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($line === '') continue;
    $cols = parse_csv_line($line);

    if ($header === null) {
      $header = array_map(fn($h)=>strtolower(trim($h)), $cols);
      $ia = array_search('a_hex', $header);
      $ib = array_search('b_hex', $header);
      if ($ia === false || $ib === false) {
        fclose($fh);
        throw new RuntimeException("Missing a_hex/b_hex columns in $path");
      }
      continue;
    }

    $ia = array_search('a_hex', $header);
    $ib = array_search('b_hex', $header);
    $aHex = normalize_hex6($cols[$ia] ?? '');
    $bHex = normalize_hex6($cols[$ib] ?? '');

    if (!$aHex || !$bHex || $aHex === $bHex) continue;

    // order: lowest first
    $low  = $aHex < $bHex ? $aHex : $bHex;
    $high = $aHex < $bHex ? $bHex : $aHex;

    $pairs[] = [$low, $high];
  }
  fclose($fh);
  if ($verbose) fwrite(STDOUT, "Read ".count($pairs)." pairs from $path\n");
  return $pairs;
}

// -------- expand inputs --------
$files = [];
foreach (explode(',', (string)$inArg) as $part) {
  $part = trim($part);
  if ($part === '') continue;
  $matches = glob($part, GLOB_BRACE) ?: [];
  if (!$matches && file_exists($part)) $matches = [$part];
  $files = array_merge($files, $matches);
}
$files = array_values(array_unique($files));
if (!$files) { fwrite(STDERR, "No input files match: $inArg\n"); exit(1); }
if ($verbose) { fwrite(STDOUT, "Input files:\n- ".implode("\n- ", $files)."\n"); }

// -------- read + de-dupe --------
$allPairs = [];
foreach ($files as $f) { $allPairs = array_merge($allPairs, read_edges_csv($f, $verbose)); }
$set = []; $uniq = [];
foreach ($allPairs as [$L,$H]) {
  $key = "$L:$H";
  if (isset($set[$key])) continue;
  $set[$key] = true;
  $uniq[] = [$L,$H];
}
if ($verbose) fwrite(STDOUT, "Unique pairs: ".count($uniq)."\n");

if ($dryRun) {
  echo "DRY RUN — would insert ".count($uniq)." pair(s) into color_friends(hex1, hex2)\n";
  exit(0);
}

// -------- insert --------
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->beginTransaction();

$ins = $pdo->prepare("INSERT IGNORE INTO `color_friends` (`hex1`, `hex2`) VALUES (?, ?)");

$inserted = 0; $ignored = 0;
foreach ($uniq as [$L,$H]) {
  $ins->execute([$L,$H]);
  if ($ins->rowCount() > 0) $inserted++; else $ignored++;
}
$pdo->commit();

echo "✅ Inserted {$inserted} pair(s). Ignored (dupes) {$ignored}. Total unique: ".count($uniq)."\n";
