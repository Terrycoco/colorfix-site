<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

// --- Basic CORS (keeps your current "soft" approach; adjust origins if needed) ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Vary: Origin');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Preflight
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Controllers\PhotosController;
use PDO;

// Helper: consistent JSON response + exit
function respond(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if ($method !== 'POST') {
    respond(405, ['error' => 'Method not allowed', 'allowed' => ['POST']]);
  }

  // $pdo is provided by db.php
  if (!isset($pdo) || !$pdo instanceof PDO) {
    respond(500, ['error' => 'DB not initialized']);
  }

  // Controller
  $controller = new PhotosController($pdo);

  // Expect multipart/form-data with any of:
  // - prepared_base (legacy single)
  // - prepared_dark | prepared_medium | prepared_light (new trio)
  // - masks[] (multiple files, roles inferred from filenames)
  // Optional: asset_id, style, verdict, status, lighting, rights, tags
  $result = $controller->upload($_POST, $_FILES);

  respond(200, $result);

} catch (Throwable $e) {
  // Return clean error JSON; avoid leaking stack details in production
  $code = 500;
  $msg  = $e->getMessage();
  // Common client errors → 400
  if (str_contains($msg, 'required') || str_contains($msg, 'not found') || str_contains($msg, 'Invalid')) {
    $code = 400;
  }
  respond($code, [
    'error'   => 'Upload failed',
    'message' => $msg,
  ]);
}
