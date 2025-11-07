<?php declare(strict_types=1);
// de-code-hex.php
// Dump Dunn-Edwards colors (brand='de') as CSV: name,code,hex6
// All errors go to: __DIR__/de-code-hex.error.log

$LOG = __DIR__ . '/de-code-hex.error.log';
$IS_CLI = (php_sapi_name() === 'cli');

// --- error logging: everything -> same-dir log ---
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', $LOG);
if (!$IS_CLI) header('Content-Type: text/plain; charset=utf-8');

// convert warnings/notices to exceptions so they get logged
set_error_handler(function($sev, $msg, $file, $line) {
  throw new ErrorException($msg, 0, $sev, $file, $line);
});

set_exception_handler(function($e) use ($LOG, $IS_CLI) {
  $msg = '['.date('c')."] EXCEPTION: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n";
  file_put_contents($LOG, $msg, FILE_APPEND);
  if (!$IS_CLI) http_response_code(500);
  echo "ERROR. See log: $LOG\n";
  exit(1);
});

register_shutdown_function(function() use ($LOG, $IS_CLI) {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    $msg = '['.date('c')."] FATAL: {$e['message']} in {$e['file']}:{$e['line']}\n";
    file_put_contents($LOG, $msg, FILE_APPEND);
    if (!$IS_CLI) http_response_code(500);
    echo "FATAL. See log: $LOG\n";
  }
});

// --- DB: require from same dir ---
require_once __DIR__ . '/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
  throw new RuntimeException("db.php did not provide a valid \$pdo");
}

// --- output path (default: ./data/de-code-hex.csv next to this script) ---
$out = __DIR__ . '/data/de-code-hex.csv';
if ($IS_CLI) {
  foreach ($argv as $arg) {
    if (strpos($arg, '--out=') === 0) { $out = substr($arg, 6); }
  }
}

// --- query ---
$sql = "SELECT name, code, hex6
        FROM colors
        WHERE brand = 'de'
        ORDER BY code ASC";

try {
  $stmt = $pdo->query($sql);
} catch (Throwable $e) {
  throw new RuntimeException("Query failed: " . $e->getMessage());
}

// --- ensure output dir ---
$dir = dirname($out);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
  throw new RuntimeException("Failed to create output dir: {$dir}");
}

// --- write CSV ---
$fh = @fopen($out, 'w');
if (!$fh) {
  throw new RuntimeException("Cannot open {$out} for writing (permission/path issue)");
}

fputcsv($fh, ['name','code','hex6']); // header

$count = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $name = (string)($row['name'] ?? '');
  $code = (string)($row['code'] ?? '');
  $hex6 = strtoupper(ltrim((string)($row['hex6'] ?? ''), '#'));
  if (!preg_match('/^[0-9A-F]{6}$/', $hex6)) $hex6 = '';
  fputcsv($fh, [$name, $code, $hex6]);
  $count++;
}
fclose($fh);

echo "✅ Wrote {$count} rows to {$out}\n";
