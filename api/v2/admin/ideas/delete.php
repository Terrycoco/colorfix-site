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
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $ideaId = isset($data['idea_id']) ? (int)$data['idea_id'] : 0;
    if ($ideaId <= 0) {
        respond(['ok' => false, 'error' => 'idea_id required'], 400);
    }

    $stmt = $pdo->prepare('DELETE FROM ideas WHERE idea_id = :idea_id');
    $stmt->execute(['idea_id' => $ideaId]);

    respond(['ok' => true]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
