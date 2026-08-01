<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

require_method('POST');

try {
    $data = read_json_payload();
    $id = milestone_int($data['id'] ?? 0);
    if ($id <= 0) respond(['ok' => false, 'error' => 'Category id required'], 400);

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('UPDATE milestone_categories SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute(['id' => $id]);
    $stmt = $pdo->prepare('UPDATE milestones SET deleted_at = NOW() WHERE category_id = :id AND deleted_at IS NULL');
    $stmt->execute(['id' => $id]);
    normalize_category_order($pdo);
    $pdo->commit();
    respond(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
