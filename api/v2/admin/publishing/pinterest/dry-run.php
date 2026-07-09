<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../../../autoload.php';
require_once __DIR__ . '/../../../../db.php';
require_once __DIR__ . '/../../auth.php';

use App\Lib\SecretBox;
use App\Repos\PdoPublisherRepository;
use App\Repos\PdoPublishingRepository;
use App\Services\PinterestOAuthService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$payload = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($payload)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

try {
    $outputId = (int)($payload['package_id'] ?? $payload['publish_output_id'] ?? 0);
    if ($outputId <= 0) {
        throw new RuntimeException('package_id required');
    }
    $environment = trim((string)($payload['environment'] ?? 'test')) ?: 'test';
    $service = new PinterestOAuthService(
        new PdoPublisherRepository($pdo),
        new PdoPublishingRepository($pdo),
        new SecretBox()
    );
    respond(['ok' => true, 'item' => $service->dryRunForOutput($outputId, $environment)]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
