<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../../autoload.php';
require_once __DIR__ . '/../../../../db.php';

use App\Lib\EnvLoader;
use App\Services\PhotoAltTextQueueService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $token = EnvLoader::get('ALT_TEXT_WORKER_TOKEN');
    if ($token && hash_equals($token, (string)($_GET['token'] ?? $_POST['token'] ?? '')) === false) {
        respond(['ok' => false, 'error' => 'Unauthorized'], 401);
    }

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : (isset($_POST['limit']) ? (int)$_POST['limit'] : 200);
    $service = PhotoAltTextQueueService::fromPdo($pdo);
    $count = $service->enqueueMissing($limit);

    respond(['ok' => true, 'queued' => $count]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
