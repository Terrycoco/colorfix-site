<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoUserEventRepository;
use App\Repos\PdoAppConfigRepository;
use App\Services\UserEventService;

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$filters = [
    'q' => isset($_GET['q']) ? trim((string)$_GET['q']) : '',
    'audience' => isset($_GET['audience']) ? trim((string)$_GET['audience']) : 'all',
    'source' => isset($_GET['source']) ? trim((string)$_GET['source']) : 'all',
    'include_internal' => isset($_GET['include_internal']) && (string)$_GET['include_internal'] === '1',
];

try {
    $service = new UserEventService(new PdoUserEventRepository($pdo), new PdoAppConfigRepository($pdo));
    $dashboard = $service->getPlaylistFunnelDashboard($filters);

    respond([
        'ok' => true,
        'filters' => $filters,
        'totals' => $dashboard['totals'],
        'items' => $dashboard['items'],
        'baseline' => $dashboard['baseline'] ?? ['cutoff_at' => '', 'cutoff_at_iso' => null],
    ]);
} catch (Throwable $e) {
    respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
