<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

require_method('GET');

try {
    $stmt = $pdo->query(
        'SELECT m.id, m.category_id, c.name AS category_name, m.title, m.notes, m.achieved_at, m.deleted_at
         FROM milestones m
         LEFT JOIN milestone_categories c ON c.id = m.category_id
         WHERE m.deleted_at IS NOT NULL
         ORDER BY m.deleted_at DESC, m.id DESC'
    );
    $items = array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'category_id' => (int)$row['category_id'],
            'category_name' => (string)($row['category_name'] ?? ''),
            'title' => (string)$row['title'],
            'notes' => (string)($row['notes'] ?? ''),
            'achieved_at' => $row['achieved_at'],
            'deleted_at' => $row['deleted_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    respond(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
