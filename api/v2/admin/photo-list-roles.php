<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Controllers\PhotosController;

function respond(int $code, array $payload): never {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $pdo = $GLOBALS['pdo'] ?? null;
  if (!$pdo instanceof PDO) throw new RuntimeException('DB not initialized');

  $ctrl = new PhotosController($pdo);
  $out  = $ctrl->listRoles($_GET);

  respond(200, $out);
} catch (Throwable $e) {
  respond(500, ['error' => $e->getMessage()]);
}
