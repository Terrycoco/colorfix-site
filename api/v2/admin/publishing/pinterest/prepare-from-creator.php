<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../../../autoload.php';
require_once __DIR__ . '/../../../../db.php';
require_once __DIR__ . '/../../auth.php';

use App\Controllers\PinterestPublishingController;
use App\Repos\PdoAssetCreatorRepository;
use App\Repos\PdoPublisherRepository;
use App\Repos\PdoPublishingRepository;
use App\Services\PinterestPublishingService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $controller = new PinterestPublishingController(
        new PinterestPublishingService(
            new PdoPublishingRepository($pdo),
            new PdoPublisherRepository($pdo),
            new PdoAssetCreatorRepository($pdo)
        )
    );
    respond($controller->prepareFromCreatorJob($payload));
} catch (RuntimeException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
