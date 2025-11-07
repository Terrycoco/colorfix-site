<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Controllers\PhotosController;

function respond(int $c, array $p){ http_response_code($c); echo json_encode($p, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error'=>'method_not_allowed']);
  }
  $body = json_decode(file_get_contents('php://input'), true) ?? [];
  $ctl  = new PhotosController($pdo);
  $res  = $ctl->renderApply($body);
  respond(200, $res);
} catch (Throwable $e) {
  respond(500, [
    'error'   => 'server',
    'message' => $e->getMessage(),
    'file'    => $e->getFile(),
    'line'    => $e->getLine(),
    'type'    => get_class($e),
  ]);
}
