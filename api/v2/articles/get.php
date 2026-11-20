<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\ARTICLES\Services\PublicArticleService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) respond(['ok' => false, 'error' => 'id required'], 400);

try {
    $isAdmin = \App\SYSTEM\Services\AdminAuthService::fromPdo($pdo)->currentUser(true) !== null;
    $result = (new PublicArticleService($pdo))->get($id, $isAdmin);
    $status = $result['status'];
    if ($status !== 200) respond(['ok' => false, 'error' => $result['error']], $status);
    respond(['ok' => true, 'item' => $result['item']]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
