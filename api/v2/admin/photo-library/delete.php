<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
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

    $id = isset($payload['photo_library_id']) ? (int)$payload['photo_library_id'] : 0;
    if ($id <= 0) {
        respond(['ok' => false, 'error' => 'photo_library_id required'], 400);
    }

    $stmt = $pdo->prepare('SELECT source_type, rel_path FROM photo_library WHERE photo_library_id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        respond(['ok' => false, 'error' => 'Photo not found'], 404);
    }

    $sourceType = (string)$row['source_type'];
    $rel = (string)($row['rel_path'] ?? '');
    if (in_array($sourceType, ['progression', 'article'], true) && $rel !== '' && str_starts_with($rel, '/photos/')) {
        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../../../..'), '/');
        $abs = $docRoot . $rel;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    $pdo->prepare('DELETE FROM photo_library WHERE photo_library_id = :id')->execute([':id' => $id]);

    respond(['ok' => true]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
