<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'OPTIONS') { http_response_code(200); exit; }

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

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$hoaId = isset($data['hoa_id']) ? (int)$data['hoa_id'] : 0;
$schemeCode = trim((string)($data['scheme_code'] ?? ''));
$sourceBrand = trim((string)($data['source_brand'] ?? ''));
if ($hoaId <= 0 || $schemeCode === '') {
    respond(['ok' => false, 'error' => 'hoa_id and scheme_code required'], 400);
}

$repo = new PdoHoaSchemeRepository($pdo);
$id = isset($data['id']) ? (int)$data['id'] : 0;

$payload = [
    'hoa_id' => $hoaId,
    'scheme_code' => $schemeCode,
    'source_brand' => $sourceBrand,
    'notes' => $data['notes'] ?? null,
];

if ($id > 0) {
    $repo->updateScheme($id, $payload);
    $schemeId = $id;
} else {
    $schemeId = $repo->insertScheme($payload);
}

respond([
    'ok' => true,
    'id' => $schemeId,
]);
