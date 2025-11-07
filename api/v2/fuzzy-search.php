<?php
declare(strict_types=1);

// CORS preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=UTF-8');
@ini_set('display_errors','0');
@ini_set('log_errors','1');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Repos\PdoSwatchRepository; // correct casing

// --- v2-safe JSON error wrappers (no 500s) ---
set_error_handler(function($sev,$msg,$file,$line){
  http_response_code(200);
  echo json_encode([
    'query'   => (string)($_GET['q'] ?? ''),
    'results' => [],
    'total'   => 0,
    '_err'    => "PHP error: $msg"
  ], JSON_UNESCAPED_SLASHES);
  exit;
});
set_exception_handler(function($e){
  http_response_code(200);
  echo json_encode([
    'query'   => (string)($_GET['q'] ?? ''),
    'results' => [],
    'total'   => 0,
    '_err'    => $e->getMessage()
  ], JSON_UNESCAPED_SLASHES);
  exit;
});

// --- Optional logger (guarded so missing class won't fatal) ---
$logInfo = $logError = static function(string $event, array $ctx = []){};
if (class_exists('\App\Lib\Logger')) {
  /** @var \App\Lib\Logger $Logger */
  $Logger = '\App\Lib\Logger';
  // Consistent log file (guarded)
  $logDir  = dirname(__DIR__, 2) . '/app/logs';
  if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
  $logPath = $logDir . '/search.log';
  try { $Logger::setFile($logPath); } catch (\Throwable $e) { /* ignore */ }
  $logInfo  = static function(string $event, array $ctx = []) use ($Logger) { try { $Logger::info($event, $ctx); } catch (\Throwable $e) {} };
  $logError = static function(string $event, array $ctx = []) use ($Logger) { try { $Logger::error($event, $ctx); } catch (\Throwable $e) {} };
}

function jok(array $payload): void {
  http_response_code(200);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES);
  exit;
}

$q = trim((string)($_GET['q'] ?? ''));

// Empty query → stable shape
if ($q === '') {
  jok([
    'query'   => '',
    'results' => [],
    'total'   => 0,
  ]);
}

try {
  // Non-fatal query logging (DB table may not exist yet)
  try {
    $stmtLog = $pdo->prepare("INSERT INTO search_log (query, created_at) VALUES (:q, NOW())");
    $stmtLog->execute(['q' => $q]);
  } catch (\Throwable $e) {
    $logError('search.log.insert_failed', ['err' => $e->getMessage()]);
  }

  $repo = new PdoSwatchRepository($pdo);
  // Keep existing behavior/limit; adjust if you add ?limit= later
  $out = $repo->fuzzySearchByNameCode($q, 2000);

  // Normalize shape
  $results = [];
  $total   = 0;

  if (is_array($out)) {
    if (array_key_exists('results', $out) && is_array($out['results'])) {
      $results = $out['results'];
      $total   = isset($out['total']) && is_numeric($out['total']) ? (int)$out['total'] : count($results);
    } else {
      // accept raw row array
      $results = array_values($out);
      $total   = count($results);
    }
  }

  if (!empty($results)) {
    $first = $results[0] ?? [];
    $logInfo('search.sample', ['q' => $q, 'name' => $first['name'] ?? null, 'code' => $first['code'] ?? null]);
  } else {
    $logInfo('search.empty', ['q' => $q]);
  }

  jok([
    'query'   => $q,
    'results' => $results,
    'total'   => $total,
  ]);

} catch (\Throwable $e) {
  $logError('search.exception', ['msg' => $e->getMessage()]);
  jok([
    'query'   => $q,
    'results' => [],
    'total'   => 0,
    '_err'    => 'Database error'
  ]);
}
