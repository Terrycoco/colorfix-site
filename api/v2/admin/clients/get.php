<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoClientRepository;

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        respond(['ok' => false, 'error' => 'id required'], 400);
    }

    $repo = new PdoClientRepository($pdo);
    $row = $repo->findById($id);
    if (!$row) {
        respond(['ok' => false, 'error' => 'client not found'], 404);
    }
    $usage = $repo->getUsageSummary($id);

    respond([
        'ok' => true,
        'client' => [
            'id' => (int)$row['id'],
            'name' => (string)($row['name'] ?? ''),
            'first_name' => isset($row['first_name']) ? (string)$row['first_name'] : '',
            'last_name' => isset($row['last_name']) ? (string)$row['last_name'] : '',
            'email' => (string)($row['email'] ?? ''),
            'phone' => isset($row['phone']) ? (string)$row['phone'] : '',
            'notes' => isset($row['notes']) ? (string)$row['notes'] : '',
            'client_type' => isset($row['client_type_key']) ? (string)$row['client_type_key'] : '',
            'photo_permission_status' => (string)($row['photo_permission_status'] ?? 'unknown'),
            'photo_permission_requested_at' => isset($row['photo_permission_requested_at']) ? (string)$row['photo_permission_requested_at'] : '',
            'photo_permission_granted_at' => isset($row['photo_permission_granted_at']) ? (string)$row['photo_permission_granted_at'] : '',
            'photo_count' => (int)($usage['photo_count'] ?? 0),
            'applied_palette_count' => (int)($usage['applied_palette_count'] ?? 0),
            'share_count' => (int)($usage['share_count'] ?? 0),
        ],
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
