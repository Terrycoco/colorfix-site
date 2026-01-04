<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoHoaSchemeRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$hoaId = isset($_GET['hoa_id']) ? (int)$_GET['hoa_id'] : 0;
if ($hoaId <= 0) {
    respond(['ok' => false, 'error' => 'Missing hoa_id'], 400);
}

$repo = new PdoHoaSchemeRepository($pdo);
$items = $repo->getSchemesByHoaId($hoaId);

respond([
    'ok' => true,
    'items' => $items,
]);
