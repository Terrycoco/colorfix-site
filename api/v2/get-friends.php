<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php'; // provides $pdo

use App\Controllers\FriendsController; // <-- fixed casing

set_error_handler(function($sev,$msg,$file,$line){
  http_response_code(200); // FE expects JSON payload
  echo json_encode(['items'=>[],'_err'=>"PHP error: $msg"], JSON_UNESCAPED_SLASHES);
  exit;
});
set_exception_handler(function($e){
  http_response_code(200);
  echo json_encode(['items'=>[],'_err'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
  exit;
});

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];

$ctrl = new FriendsController($pdo);
$out  = $ctrl->handle($_GET, $body);

echo json_encode($out, JSON_UNESCAPED_SLASHES);
