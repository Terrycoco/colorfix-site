<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Controllers\ArticleController;
use App\Repos\PdoArticleRepository;
use App\Services\ArticleService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    respond(['ok' => false, 'error' => 'id required'], 400);
}

try {
    $repo = new PdoArticleRepository($pdo);
    $service = new ArticleService($repo);
    $controller = new ArticleController($service);

    $item = $controller->get($id);
    if (!$item) {
        respond(['ok' => false, 'error' => 'Not found'], 404);
    }
    respond(['ok' => true, 'item' => $item]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
