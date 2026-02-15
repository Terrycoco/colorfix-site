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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

try {
    $sql = <<<SQL
        SELECT
            idea_id,
            title,
            body,
            is_done,
            created_at,
            updated_at
        FROM ideas
        ORDER BY is_done ASC, updated_at DESC, created_at DESC
    SQL;
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = array_map(static function (array $row): array {
        return [
            'idea_id' => (int)$row['idea_id'],
            'title' => (string)$row['title'],
            'body' => (string)$row['body'],
            'is_done' => (int)$row['is_done'] === 1,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }, $rows);

    respond(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
