<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoProjectLinkRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $projectId = isset($data['project_id']) ? (int)$data['project_id'] : 0;
    $assetType = trim((string)($data['asset_type'] ?? ''));
    $assetId = isset($data['asset_id']) ? (int)$data['asset_id'] : 0;
    $role = trim((string)($data['role'] ?? ''));
    $sortOrder = isset($data['sort_order']) ? (int)$data['sort_order'] : 0;
    $notes = isset($data['notes']) ? (string)$data['notes'] : null;

    if ($projectId <= 0 || $assetType === '' || $assetId <= 0) {
        respond(['ok' => false, 'error' => 'project_id, asset_type, and asset_id are required'], 400);
    }

    $repo = new PdoProjectLinkRepository($pdo);
    $payload = [
        'project_id' => $projectId,
        'asset_type' => $assetType,
        'asset_id' => $assetId,
        'role' => $role !== '' ? $role : null,
        'sort_order' => $sortOrder,
        'notes' => $notes !== '' ? $notes : null,
    ];

    if ($id > 0) {
        $repo->update($id, $projectId, $payload);
        respond(['ok' => true, 'id' => $id]);
    }

    respond(['ok' => true, 'id' => $repo->insert($payload)]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
