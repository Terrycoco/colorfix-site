<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

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
    $groupId = isset($payload['group_id']) ? (int)$payload['group_id'] : 0;
    $photoId = isset($payload['photo_library_id']) ? (int)$payload['photo_library_id'] : 0;
    if ($groupId <= 0 || $photoId <= 0) {
        respond(['ok' => false, 'error' => 'group_id and photo_library_id required'], 400);
    }

    $stmt = $pdo->prepare("DELETE FROM photo_group_items WHERE group_id = :gid AND photo_library_id = :pid");
    $stmt->execute([':gid' => $groupId, ':pid' => $photoId]);
    respond(['ok' => true]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
