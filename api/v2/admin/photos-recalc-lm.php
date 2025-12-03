<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Controllers\PhotosController;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = null;
if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        respond(['ok' => false, 'error' => 'Invalid JSON body'], 400);
    }
    $input = $decoded;
} elseif ($method === 'GET') {
    $assetId = trim((string)($_GET['asset_id'] ?? ''));
    $roles = isset($_GET['roles'])
        ? array_filter(array_map('trim', explode(',', (string)$_GET['roles'])))
        : null;
    $input = $assetId !== '' ? ['asset_id' => $assetId, 'roles' => $roles] : null;
    if (!$input) {
        respond(['ok' => false, 'error' => 'asset_id required'], 400);
    }
} else {
    respond(['ok' => false, 'error' => 'Method not allowed'], 405);
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    respond(['ok' => false, 'error' => 'DB not initialized'], 500);
}

 $logFile = __DIR__ . '/../../../logs/recalc-lm.log';
 $controller = new PhotosController($pdo);

try {
    $result = $controller->recalcLm($input);
    file_put_contents($logFile, json_encode(['ts'=>date('c'),'result'=>$result], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
    respond(['ok' => true, 'result' => $result]);
} catch (Throwable $e) {
    file_put_contents($logFile, json_encode(['ts'=>date('c'),'error'=>$e->getMessage(),'trace'=>$e->getTraceAsString()], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
    $code = $e->getCode() ?: 400;
    if ($code >= 500) {
        respond(['ok' => false, 'error' => 'server'], 500);
    }
    respond(['ok' => false, 'error' => $e->getMessage()], $code);
}
