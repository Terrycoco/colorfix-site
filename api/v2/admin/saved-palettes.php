<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Controllers\SavedPaletteController;
use App\Repos\PdoClientRepository;
use App\Repos\PdoSavedPaletteRepository;
use App\Services\SavedPaletteService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $paletteRepo = new PdoSavedPaletteRepository($pdo);
    $clientRepo  = new PdoClientRepository($pdo);
    $service     = new SavedPaletteService($paletteRepo, $clientRepo);
    $controller  = new SavedPaletteController($service);

    $brand = isset($_GET['brand']) ? strtolower(trim((string)$_GET['brand'])) : '';
    $filters = [];
    if ($brand !== '') {
        $filters['brand'] = substr($brand, 0, 4);
    }

    if (isset($_GET['terry_fav'])) {
        $filters['terry_fav'] = (int) (bool) $_GET['terry_fav'];
    }

    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    if ($q !== '') {
        $filters['q'] = $q;
    }

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
    $limit = max(1, min(200, $limit));

    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $offset = max(0, $offset);

    $withMembers = !empty($_GET['with_members']) && (int) $_GET['with_members'] === 1;

    $rows = $controller->list($filters, $limit, $offset);

    if ($withMembers && $rows) {
        foreach ($rows as &$row) {
            $full = $controller->getById((int) $row['id'], false);
            $row['members'] = $full['members'] ?? [];
        }
        unset($row);
    }

    respond([
        'ok' => true,
        'items' => $rows,
        'meta' => [
            'limit'  => $limit,
            'offset' => $offset,
            'count'  => count($rows),
        ],
    ]);
} catch (\Throwable $e) {
    respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
