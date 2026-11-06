<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');
@ini_set('display_errors','0');
@ini_set('log_errors','1');
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Repos\PdoSwatchRepository;

set_error_handler(function($sev,$msg,$file,$line){
  http_response_code(200);
  echo json_encode(['query'=>(string)($_GET['q'] ?? ''),'results'=>[],'total'=>0,'_err'=>"PHP error: $msg"], JSON_UNESCAPED_SLASHES);
  exit;
});
set_exception_handler(function($e){
  http_response_code(200);
  echo json_encode(['query'=>(string)($_GET['q'] ?? ''),'results'=>[],'total'=>0,'_err'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
  exit;
});
$logInfo = $logError = static function(string $event, array $ctx = []){};
if (class_exists('\App\Lib\Logger')) {
  $Logger = '\App\Lib\Logger';
  $logDir = dirname(__DIR__, 2) . '/app/logs';
  if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
  try { $Logger::setFile($logDir . '/search.log'); } catch (\Throwable $e) {}
  $logInfo = static function(string $event, array $ctx = []) use ($Logger) { try { $Logger::info($event, $ctx); } catch (\Throwable $e) {} };
  $logError = static function(string $event, array $ctx = []) use ($Logger) { try { $Logger::error($event, $ctx); } catch (\Throwable $e) {} };
}
function jok(array $payload): void {
  http_response_code(200);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES);
  exit;
}
$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') jok(['query'=>'','results'=>[],'total'=>0]);
// Legacy remains default so existing ColorSelect callers and front-page HEX search are unchanged.
$nameMode = (string)($_GET['name_mode'] ?? 'legacy');
if (!in_array($nameMode, ['legacy','starts_with','contains'], true)) $nameMode = 'legacy';
try {
  try {
    $stmtLog = $pdo->prepare("INSERT INTO search_log (query, created_at) VALUES (:q, NOW())");
    $stmtLog->execute(['q'=>$q]);
  } catch (\Throwable $e) { $logError('search.log.insert_failed', ['err'=>$e->getMessage()]); }
  $repo = new PdoSwatchRepository($pdo);
  $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 80;
  $limit = max(1, min(100, $limit));
  $out = $repo->fuzzySearchByNameCode($q, $limit, $nameMode);
  $results = [];
  $total = 0;
  if (is_array($out)) {
    if (array_key_exists('results', $out) && is_array($out['results'])) {
      $results = $out['results'];
      $total = isset($out['total']) && is_numeric($out['total']) ? (int)$out['total'] : count($results);
    } else {
      $results = array_values($out);
      $total = count($results);
    }
  }
  if ($results) {
    $logInfo('search.sample', ['q'=>$q,'name'=>$results[0]['name'] ?? null,'code'=>$results[0]['code'] ?? null]);
  } else { $logInfo('search.empty', ['q'=>$q]); }
  jok(['query'=>$q,'results'=>$results,'total'=>$total]);
} catch (\Throwable $e) {
  $logError('search.exception', ['msg'=>$e->getMessage()]);
  jok(['query'=>$q,'results'=>[],'total'=>0,'_err'=>'Database error']);
}
