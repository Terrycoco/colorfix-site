<?php
// de-build-friends.php — base+friend AND friend+friend pairs (web runner)
// Input:  __DIR__/data/de-details.json
// Output: __DIR__/data/de-friends.csv
declare(strict_types=1);

// --- harden + debug ---
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
// bump memory; raise if needed
ini_set('memory_limit', '1024M');
// keep long jobs alive
@set_time_limit(0);
@ini_set('max_execution_time', '0');
ignore_user_abort(true);

// show fatal error at the end if any
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    echo "\nFATAL: {$e['message']} in {$e['file']}:{$e['line']}\n";
    @flush();
  }
});

require_once 'db.php'; // not used; fine to keep

const SECRET_KEY = '02c71f20ec345c411377eca8dfe655dcef56d4d72aea57a36c5c9d3586acca8a';
const JSON_PATH  = __DIR__ . '/data/de-details.json';
const OUT_PATH   = __DIR__ . '/data/de-friends.csv';
const LOCK_FILE  = '/tmp/rebuild_friend_pairs.lock';

// --- streaming-friendly headers ---
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no'); // nginx

@ini_set('output_buffering','off');
@ini_set('zlib.output_compression','0');
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
while (ob_get_level() > 0) { @ob_end_flush(); }
ob_implicit_flush(true);
echo str_repeat(' ', 4096) . "\n"; flush();

// --- auth ---
if (($_GET['key'] ?? '') !== SECRET_KEY) {
  http_response_code(403);
  echo "Forbidden\n"; flush(); exit;
}

// --- lock ---
$lock = fopen(LOCK_FILE, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo "Already running (lock)\n"; flush(); exit; }
register_shutdown_function(function() use ($lock) {
  if ($lock) { flock($lock, LOCK_UN); fclose($lock); @unlink(LOCK_FILE); }
});

// --- helpers ---
function to_hex6(?string $s): string {
  if ($s === null) return '';
  $s = trim($s);
  if ($s === '') return '';
  if (preg_match('/#?([0-9a-f]{6})/i', $s, $m)) return strtoupper($m[1]);
  if (preg_match('/^\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*$/', $s, $m)
   || preg_match('/rgba?\s*\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/i', $s, $m)) {
    $r = max(0, min(255, (int)$m[1]));
    $g = max(0, min(255, (int)$m[2]));
    $b = max(0, min(255, (int)$m[3]));
    return strtoupper(
      str_pad(dechex($r),2,'0',STR_PAD_LEFT) .
      str_pad(dechex($g),2,'0',STR_PAD_LEFT) .
      str_pad(dechex($b),2,'0',STR_PAD_LEFT)
    );
  }
  return '';
}

// Accept mixed types (PHP might give ints from array_keys), cast to string inside
function push_pair(array &$set, $x, $y): void {
  $x = (string)$x;
  $y = (string)$y;
  if ($x === '' || $y === '' || $x === $y) return;
  $a = $x <= $y ? $x : $y;
  $b = $x <= $y ? $y : $x;
  $set["$a,$b"] = true;
}

// --- load json ---
if (!is_file(JSON_PATH)) {
  http_response_code(404);
  echo "File not found: " . JSON_PATH . "\n"; flush(); exit;
}
clearstatcache(true, JSON_PATH);
$size = filesize(JSON_PATH);
echo "Reading " . JSON_PATH . " (" . number_format($size) . " bytes)…\n"; flush();

$json = file_get_contents(JSON_PATH);
if ($json === false) { echo "Failed to read file\n"; flush(); exit; }

echo "Decoding JSON…\n"; flush();
$data = json_decode($json, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
unset($json); // free memory
if (!is_array($data)) {
  echo "Invalid JSON. Error: " . json_last_error_msg() . "\n"; flush(); exit;
}
echo "Decoded OK. Colors: " . count($data) . "\n"; flush();

// ensure output dir
$dir = dirname(OUT_PATH);
if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
  echo "Cannot create directory: $dir\n"; flush(); exit;
}

// collect pairs
$pairSet = []; // key: "HEX6,HEX6" (A<=B)
$totalColors = 0;
$colorsWithFriends = 0;

foreach ($data as $row) {
  $totalColors++;

  // base hex (from hex, fallback to rgb)
  $baseHex = to_hex6($row['hex'] ?? '');
  if ($baseHex === '' && !empty($row['rgb'])) $baseHex = to_hex6($row['rgb']);

  // normalize/dedupe friends — store as VALUES so they remain strings
  $friends = is_array($row['friends'] ?? null) ? $row['friends'] : [];
  $friendSet = [];
  foreach ($friends as $f) {
    $h = to_hex6($f);
    if ($h !== '') $friendSet[$h] = $h;  // <— keep string value, not boolean
  }
  $friendHexes = array_values($friendSet); // dense, strings

  if (count($friendHexes) > 0) $colorsWithFriends++;

  // a+b, a+c (base↔friend)
  if ($baseHex !== '') {
    foreach ($friendHexes as $fx) push_pair($pairSet, $baseHex, $fx);
  }

  // b+c (friend↔friend)
  $n = count($friendHexes);
  for ($i = 0; $i < $n; $i++) {
    for ($j = $i + 1; $j < $n; $j++) {
      push_pair($pairSet, $friendHexes[$i], $friendHexes[$j]);
    }
  }

  if (($totalColors % 200) === 0) {
    echo "… processed colors: $totalColors | unique pairs: " . count($pairSet) . "\n"; flush();
  }
}

// write csv
$fh = fopen(OUT_PATH, 'w');
if (!$fh) { echo "Cannot open output: " . OUT_PATH . "\n"; flush(); exit; }

$linesWritten = 0;
foreach (array_keys($pairSet) as $line) {
  fwrite($fh, $line . "\n");
  $linesWritten++;
}
fclose($fh);

echo "Done.\n";
echo "Colors processed: $totalColors\n";
echo "Colors with >=1 friend: $colorsWithFriends\n";
echo "Unique pairs written: $linesWritten\n";
echo "Output: " . OUT_PATH . "\n"; flush();
