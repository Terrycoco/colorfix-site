<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'OPTIONS') { http_response_code(200); exit; }

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

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$name = trim((string)($data['name'] ?? ''));
if ($name === '') {
    respond(['ok' => false, 'error' => 'Name is required'], 400);
}

$repo = new PdoHoaRepository($pdo);
$id = isset($data['id']) ? (int)$data['id'] : 0;

$payload = [
    'name' => $name,
    'city' => $data['city'] ?? null,
    'state' => $data['state'] ?? null,
    'hoa_type' => $data['hoa_type'] ?? 'unknown',
    'eligibility_status' => $data['eligibility_status'] ?? 'potential',
    'reason_not_eligible' => $data['reason_not_eligible'] ?? null,
    'source' => $data['source'] ?? 'other',
    'notes' => $data['notes'] ?? null,
];

if ($id > 0) {
    $repo->update($id, $payload);
    $hoaId = $id;
} else {
    $hoaId = $repo->insert($payload);
}

respond([
    'ok' => true,
    'id' => $hoaId,
]);
