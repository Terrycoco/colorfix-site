<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\ANA\Repos\PdoANAReportRepository;
use App\ANA\Services\ANAReportService;
use App\Repos\PdoPlaylistRepository;
use App\Repos\PdoArticleRepository;

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $resourceType = trim((string)($_GET['resource_type'] ?? ''));
    $eventKey = trim((string)($_GET['event_key'] ?? ''));

    if ($resourceType === '' || $eventKey === '') {
        respond([
            'ok' => false,
            'error' => 'resource_type and event_key required',
        ], 400);
    }

    $service = new ANAReportService(
        new PdoANAReportRepository($pdo),
        new PdoPlaylistRepository($pdo),
        new PdoArticleRepository($pdo)
    );

    $rows = $service->countEventsByResourceType(
        $resourceType,
        $eventKey
    );

    $resourceTypes = $service->listResourceTypes();

    respond([
        'ok' => true,
        'resource_type' => $resourceType,
        'event_key' => $eventKey,
        'resource_types' => $resourceTypes,
        'items' => $rows,
    ]);

} catch (Throwable $e) {
    respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}