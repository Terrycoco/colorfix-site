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
    $sql = "SELECT g.group_id,
                   g.title,
                   COUNT(i.photo_library_id) AS item_count
            FROM photo_groups g
            LEFT JOIN photo_group_items i ON i.group_id = g.group_id
            GROUP BY g.group_id
            ORDER BY g.title ASC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = array_map(static function(array $row): array {
        return [
            'group_id' => (int)$row['group_id'],
            'title' => (string)$row['title'],
            'item_count' => (int)$row['item_count'],
        ];
    }, $rows);
    respond(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
