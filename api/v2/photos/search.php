<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';
use App\Controllers\PhotosController;

function respond($c,$p){ http_response_code($c); echo json_encode($p, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }

try {
  $ctl = new PhotosController($pdo);
  $res = $ctl->search([
    'tags'  => $_GET['tags'] ?? '',
    'q'     => $_GET['q'] ?? '',
    'page'  => isset($_GET['page']) ? (int)$_GET['page'] : 1,
    'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 24,
  ]);
  respond(200, $res);
} catch (Throwable $e) {
  respond(500, ['error'=>$e->getMessage(), 'message'=>$e->getMessage()]);
}
