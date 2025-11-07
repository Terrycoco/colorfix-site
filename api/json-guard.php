<?php
// FIRST LINE in every endpoint: require_once __DIR__ . '/json_guard.php';
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors','0');        // never echo notices/warnings into JSON
ini_set('log_errors','1');

set_error_handler(function($sev, $msg, $file, $line) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>"PHP error: $msg",'at'=>"$file:$line"], JSON_UNESCAPED_SLASHES);
  exit;
});
set_exception_handler(function($e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
  exit;
});

// CORS + preflight (safe for dev)
if (!headers_sent()) {
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
  if (isset($_SERVER['HTTP_ORIGIN'])) { header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']); header('Vary: Origin'); }
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function fail(int $code, string $msg, array $ctx=[]): void {
  http_response_code($code);
  echo json_encode(['ok'=>false,'error'=>$msg,'ctx'=>$ctx], JSON_UNESCAPED_SLASHES);
  exit;
}
