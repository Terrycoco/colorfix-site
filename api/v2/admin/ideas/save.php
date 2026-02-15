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
    $title = isset($data['title']) ? trim((string)$data['title']) : '';
    $body = isset($data['body']) ? (string)$data['body'] : '';
    $isDone = !empty($data['is_done']) ? 1 : 0;

    if ($title === '') {
        respond(['ok' => false, 'error' => 'Title is required'], 400);
    }

    if ($ideaId > 0) {
        $sql = <<<SQL
            UPDATE ideas
            SET title = :title,
                body = :body,
                is_done = :is_done
            WHERE idea_id = :idea_id
        SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'title' => $title,
            'body' => $body,
            'is_done' => $isDone,
            'idea_id' => $ideaId,
        ]);
        respond(['ok' => true, 'idea_id' => $ideaId]);
    }

    $sql = <<<SQL
        INSERT INTO ideas (title, body, is_done)
        VALUES (:title, :body, :is_done)
    SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'title' => $title,
        'body' => $body,
        'is_done' => $isDone,
    ]);
    $newId = (int)$pdo->lastInsertId();

    respond(['ok' => true, 'idea_id' => $newId]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
