<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

// Raise upload limits (if host allows overrides)
@ini_set('upload_max_filesize', '40M');
@ini_set('post_max_size', '60M');
@ini_set('max_input_time', '120');
@ini_set('max_execution_time', '120');

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

  // Detect post_max_size overflow (PHP clears $_POST/$_FILES silently)
  $contentLen = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
  if ($contentLen > 0 && empty($_POST) && empty($_FILES)) {
    $limit = ini_get('post_max_size') ?: 'unknown';
    respond(413, [
      'error' => 'Upload failed',
      'message' => "Payload exceeded server post_max_size limit ({$limit}). Reduce file sizes or raise the limit.",
    ]);
  }

  // debug dump
  $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../../..', '/');
  $logDir = $docRoot . '/logs';
  if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
  }
  $debugLog = $logDir . '/photo-upload-debug.log';
  $snapshot = [
    'ts'    => date('c'),
    'post'  => array_keys($_POST),
    'asset_id' => $_POST['asset_id'] ?? null,
    'files' => array_keys($_FILES),
    'has_prepared_base' => isset($_FILES['prepared_base']) ? $_FILES['prepared_base']['error'] : null,
    'has_texture'       => isset($_FILES['texture_overlay']) ? $_FILES['texture_overlay']['error'] : null,
  ];
  file_put_contents($debugLog, json_encode($snapshot, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);

  // $pdo is provided by db.php
  if (!isset($pdo) || !$pdo instanceof PDO) {
    respond(500, ['error' => 'DB not initialized']);
  }

  // Controller
  $controller = new PhotosController($pdo);

  // Expect multipart/form-data with any of:
  // - prepared_base (single base)
  // - texture_overlay (optional PNG luminance layer)
  // - prepared_dark | prepared_medium | prepared_light (back-compat trio)
  // - masks[] (multiple files, roles inferred from filenames)
  // - extras[] (additional photos tied to asset; role from filename or extra_slugs[])
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
    'where'   => ($e instanceof \Throwable) ? ($e->getFile() . ':' . $e->getLine()) : null,
  ]);
}
