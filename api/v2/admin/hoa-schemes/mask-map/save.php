<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../../autoload.php';
require_once __DIR__ . '/../../../../db.php';

use App\Repos\PdoHoaSchemeRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$hoaId = isset($input['hoa_id']) ? (int)$input['hoa_id'] : 0;
$schemeId = isset($input['scheme_id']) ? (int)$input['scheme_id'] : 0;
$assetId = trim((string)($input['asset_id'] ?? ''));
$items = $input['items'] ?? [];
if ($hoaId <= 0 || $schemeId <= 0 || $assetId === '') {
    respond(['ok' => false, 'error' => 'hoa_id, scheme_id, and asset_id required'], 400);
}
if (!is_array($items)) {
    respond(['ok' => false, 'error' => 'items must be an array'], 400);
}

$repo = new PdoHoaSchemeRepository($pdo);
try {
    $repo->replaceMaskMap($hoaId, $schemeId, $assetId, $items);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}

respond(['ok' => true]);
