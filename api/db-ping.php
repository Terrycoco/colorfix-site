<?php
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors','0'); ini_set('log_errors','1');

set_error_handler(function($s,$m,$f,$l){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>"PHP error: $m",'at'=>"$f:$l"]); exit; });
set_exception_handler(function($e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; });

require_once  'db.php';

try {
  $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $pong = $pdo->query('SELECT 1')->fetchColumn();
  echo json_encode(['ok'=>true,'db'=>$db,'pong'=>(int)$pong]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
