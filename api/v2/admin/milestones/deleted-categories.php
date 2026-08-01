<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

require_method('GET');

try {
    $stmt = $pdo->query('SELECT id, name, preview_count, is_archived, deleted_at FROM milestone_categories WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC, id DESC');
    $items = array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'preview_count' => (int)$row['preview_count'],
            'is_archived' => (int)$row['is_archived'] === 1,
            'deleted_at' => $row['deleted_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    respond(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
