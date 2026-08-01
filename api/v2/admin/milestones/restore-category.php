<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

require_method('POST');

try {
    $data = read_json_payload();
    $id = milestone_int($data['id'] ?? 0);
    if ($id <= 0) respond(['ok' => false, 'error' => 'Category id required'], 400);

    $pdo->beginTransaction();
    $sortOrder = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM milestone_categories WHERE deleted_at IS NULL')->fetchColumn();
    $stmt = $pdo->prepare('UPDATE milestone_categories SET deleted_at = NULL, sort_order = :sort_order WHERE id = :id');
    $stmt->execute(['sort_order' => $sortOrder, 'id' => $id]);
    $stmt = $pdo->prepare('UPDATE milestones SET deleted_at = NULL WHERE category_id = :id');
    $stmt->execute(['id' => $id]);
    normalize_category_order($pdo);
    normalize_milestone_order($pdo, $id);
    $pdo->commit();
    respond(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
