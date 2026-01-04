<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoHoaRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$repo = new PdoHoaRepository($pdo);
$items = $repo->getAll();

if ($q !== '') {
    $needle = strtolower($q);
    $items = array_values(array_filter($items, static function (array $row) use ($needle): bool {
        $name = strtolower((string)($row['name'] ?? ''));
        $city = strtolower((string)($row['city'] ?? ''));
        $state = strtolower((string)($row['state'] ?? ''));
        return str_contains($name, $needle) || str_contains($city, $needle) || str_contains($state, $needle);
    }));
}

respond([
    'ok' => true,
    'items' => $items,
]);
