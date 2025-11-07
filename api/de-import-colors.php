<?php
// import_colors_web.php — run from browser to upsert colors from /data/de-details.json
declare(strict_types=1);
error_reporting(E_ALL);

require_once 'db.php'; // must define $pdo (PDO)

const SECRET_KEY = '02c71f20ec345c411377eca8dfe655dcef56d4d72aea57a36c5c9d3586acca8a';
const JSON_PATH  = __DIR__ . '/data/de-details.json';
const BRAND      = 'de';
const LOCK_FILE  = '/tmp/import_colors.lock';

header('Content-Type: text/plain; charset=UTF-8');

if (($_GET['key'] ?? '') !== SECRET_KEY) {
  http_response_code(403);
  exit("Forbidden\n");
}

@set_time_limit(0);
@ini_set('max_execution_time', '0');
ignore_user_abort(true);

// Lock to prevent concurrent runs
$lock = fopen(LOCK_FILE, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
  exit("Already running (lock)\n");
}
register_shutdown_function(function() use ($lock) {
  if ($lock) { flock($lock, LOCK_UN); fclose($lock); @unlink(LOCK_FILE); }
});

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit("db.php must define \$pdo (PDO)\n");
}

if (!is_file(JSON_PATH)) {
  http_response_code(404);
  exit("File not found: " . JSON_PATH . "\n");
}

echo "Reading " . JSON_PATH . " …\n"; @flush(); @ob_flush();
$data = json_decode(file_get_contents(JSON_PATH), true);
if (!is_array($data)) {
  http_response_code(400);
  exit("Invalid JSON in " . JSON_PATH . "\n");
}

$sql = "
INSERT INTO colors
 (brand, code, name, lrv, r, g, b, hex6, interior, exterior, notes)
VALUES
 (:brand, :code, :name, :lrv, :r, :g, :b, :hex6, :interior, :exterior, :notes)
ON DUPLICATE KEY UPDATE
 name=VALUES(name),
 lrv=VALUES(lrv),
 r=VALUES(r),
 g=VALUES(g),
 b=VALUES(b),
 hex6=VALUES(hex6),
 interior=VALUES(interior),
 exterior=VALUES(exterior),
 notes=VALUES(notes)
";
$stmt = $pdo->prepare($sql);

$ins=0; $upd=0; $skip=0; $n=0;

foreach ($data as $row) {
  $n++;

  $code = trim((string)($row['code'] ?? ''));
  $name = trim((string)($row['name'] ?? ''));
  $hex  = strtoupper(ltrim((string)($row['hex'] ?? ''), '#')); // HEX6 or ''

  $rgbStr = (string)($row['rgb'] ?? '');
  $r=$g=$b=null;
  if (preg_match('/^\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*$/', $rgbStr, $m)) {
    $r = max(0, min(255, (int)$m[1]));
    $g = max(0, min(255, (int)$m[2]));
    $b = max(0, min(255, (int)$m[3]));
  }

  // derive hex if missing but RGB present
  if ($hex === '' && $r !== null && $g !== null && $b !== null) {
    $hex = strtoupper(
      str_pad(dechex($r), 2, '0', STR_PAD_LEFT) .
      str_pad(dechex($g), 2, '0', STR_PAD_LEFT) .
      str_pad(dechex($b), 2, '0', STR_PAD_LEFT)
    );
  }
  if ($hex === '') $hex = null;

  // LRV
  $lrv = null;
  if (preg_match('/\d+(\.\d+)?/', (string)($row['lrv'] ?? ''), $lm)) {
    $lrv = (float)$lm[0];
  }

  // usage flags / notes — default BOTH to 1, only zero the other side if explicitly *Only*
  $usage = trim((string)($row['usage_note'] ?? ''));
  $lower = mb_strtolower($usage);
  $interior = 1;  // default allowed
  $exterior = 1;  // default allowed
  $notes = null;

  if (strpos($lower, 'interior use only') !== false) { $interior = 1; $exterior = 0; }
  elseif (strpos($lower, 'exterior use only') !== false) { $interior = 0; $exterior = 1; }
  elseif ($usage !== '') { $notes = $usage; } // keep other notes like "Alkali Sensitive", "Low Hide"

  if ($code === '' || $name === '') { $skip++; continue; }

  $stmt->execute([
    ':brand'    => BRAND,
    ':code'     => $code,
    ':name'     => $name,
    ':lrv'      => $lrv,
    ':r'        => $r,
    ':g'        => $g,
    ':b'        => $b,
    ':hex6'     => $hex,
    ':interior' => $interior,
    ':exterior' => $exterior,
    ':notes'    => $notes,
  ]);

  $affected = $stmt->rowCount(); // 1 insert, 2 update (MySQL)
  if ($affected === 1) $ins++; else $upd++;

  if (($n % 200) === 0) { echo "… processed $n (ins:$ins upd:$upd skip:$skip)\n"; @flush(); @ob_flush(); }
}

echo "Done. total:$n inserted:$ins updated:$upd skipped:$skip\n";
