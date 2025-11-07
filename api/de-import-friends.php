<?php
declare(strict_types=1);
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

require_once 'db.php'; // must define $pdo (PDO)

const SECRET_KEY = '02c71f20ec345c411377eca8dfe655dcef56d4d72aea57a36c5c9d3586acca8a';
const CSV_PATH   = __DIR__ . '/data/de-friends-cartesian.csv'; // <-- headered file
const SOURCE     = 'de site';
const LOCK_FILE  = null; // set to null to avoid lock issues; or use sys_get_temp_dir()

header('Content-Type: text/plain; charset=UTF-8');

function out($msg){ echo $msg, "\n"; @flush(); @ob_flush(); }

try {
  if (($_GET['key'] ?? '') !== SECRET_KEY) {
    http_response_code(403);
    throw new RuntimeException('Forbidden: bad key');
  }

  if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    throw new RuntimeException('db.php must define $pdo (PDO)');
  }

  $csv = CSV_PATH;
  if (!is_file($csv)) {
    http_response_code(404);
    throw new RuntimeException('File not found: ' . $csv);
  }
  if (!is_readable($csv)) {
    http_response_code(500);
    throw new RuntimeException('CSV not readable: ' . $csv);
  }

  // Optional file lock (commented if host blocks /tmp)
  if (LOCK_FILE !== null) {
    $lockPath = (LOCK_FILE === '') ? (sys_get_temp_dir().'/import_friends.lock') : LOCK_FILE;
    $lock = fopen($lockPath, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
      throw new RuntimeException('Already running (lock)');
    }
    register_shutdown_function(function() use ($lock, $lockPath) {
      if ($lock) { flock($lock, LOCK_UN); fclose($lock); @unlink($lockPath); }
    });
  }

  $sql = "INSERT IGNORE INTO color_friends (hex1, hex2, source) VALUES (:h1, :h2, :src)";
  $stmt = $pdo->prepare($sql);

  $fh = fopen($csv, 'r');
  if (!$fh) throw new RuntimeException('Failed to open CSV');

  // Consume header if present
  $header = fgetcsv($fh);
  $hasHeader = false;
  if ($header && count($header) >= 2) {
    $c0 = strtolower(trim((string)$header[0]));
    $c1 = strtolower(trim((string)$header[1]));
    if ($c0 === 'hex1' && $c1 === 'hex2') {
      $hasHeader = true;
    } else {
      // not a header: rewind
      rewind($fh);
    }
  }

  $ins=0; $skip=0; $dups=0; $n=0;

  $norm = function(string $s): ?string {
    $s = strtoupper(trim($s));
    if ($s === '') return null;
    if ($s[0] === '#') $s = substr($s, 1);
    if (!preg_match('/^[0-9A-F]{6}$/', $s)) return null;
    if ($s === 'FFFFFF') $s = 'F7F6F1'; // belt + suspenders
    return $s;
  };

  while (($row = fgetcsv($fh)) !== false) {
    $n++;
    if (count($row) < 2) { $skip++; continue; }

    $a = $norm((string)$row[0]);
    $b = $norm((string)$row[1]);
    if ($a === null || $b === null || $a === $b) { $skip++; continue; }

    // canonical order
    if ($a <= $b) { $h1 = $a; $h2 = $b; } else { $h1 = $b; $h2 = $a; }

    $stmt->execute([':h1'=>$h1, ':h2'=>$h2, ':src'=>SOURCE]);
    $ins += $stmt->rowCount();
    if ($stmt->rowCount() === 0) $dups++;

    if (($n % 2000) === 0) out("… processed $n (ins:$ins dup:$dups skip:$skip)");
  }
  fclose($fh);

  out("Done. rows:$n inserted:$ins duplicates:$dups skipped:$skip header_detected:" . ($hasHeader ? 'yes' : 'no'));

} catch (Throwable $e) {
  out("ERROR: " . $e->getMessage());
  out("at " . $e->getFile() . ':' . $e->getLine());
  // If you want full stack during debugging:
  // out($e->getTraceAsString());
  // Preserve the 500 status if set above, otherwise set it now
  if (http_response_code() === 200) http_response_code(500);
}
