<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoTrackingLookupRepository;

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

try {
    $repo = new PdoTrackingLookupRepository($pdo);
    respond([
        'ok' => true,
        'audiences' => $repo->listActive('audiences'),
        'sources' => $repo->listActive('sources'),
        'events' => $repo->listActive('events'),
    ]);
} catch (Throwable $e) {
    respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
