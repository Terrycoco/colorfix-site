<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'OPTIONS') { http_response_code(200); exit; }

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

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$schemeId = isset($data['scheme_id']) ? (int)$data['scheme_id'] : 0;
$colorId = isset($data['color_id']) ? (int)$data['color_id'] : 0;
$allowedRoles = trim((string)($data['allowed_roles'] ?? 'any'));

if ($schemeId <= 0 || $colorId <= 0) {
    respond(['ok' => false, 'error' => 'scheme_id and color_id required'], 400);
}

$repo = new PdoHoaSchemeRepository($pdo);
$repo->insertSchemeColor($schemeId, $colorId, $allowedRoles, $data['notes'] ?? null);

respond(['ok' => true]);
