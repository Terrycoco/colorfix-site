<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPhotoLibraryRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $id = isset($payload['photo_library_id']) ? (int)$payload['photo_library_id'] : 0;
    if ($id <= 0) {
        respond(['ok' => false, 'error' => 'photo_library_id required'], 400);
    }

    $status = array_key_exists('photo_permission_status', $payload)
        ? (string)($payload['photo_permission_status'] ?? '')
        : null;

    $repo = new PdoPhotoLibraryRepository($pdo);
    $permission = $repo->updatePermissionStatus($id, $status);

    respond(['ok' => true, 'permission' => $permission]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
