<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

require_method('POST');

try {
    $data = read_json_payload();
    $id = milestone_int($data['id'] ?? 0);
    if ($id <= 0) respond(['ok' => false, 'error' => 'Milestone id required'], 400);

    $milestone = get_milestone($pdo, $id, true);
    if (!$milestone) respond(['ok' => false, 'error' => 'Milestone not found'], 404);

    $categoryId = (int)$milestone['category_id'];
    if (!category_exists($pdo, $categoryId)) {
        respond(['ok' => false, 'error' => 'Restore the category before restoring this milestone'], 400);
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM milestones WHERE category_id = :category_id AND deleted_at IS NULL');
    $stmt->execute(['category_id' => $categoryId]);
    $sortOrder = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('UPDATE milestones SET deleted_at = NULL, sort_order = :sort_order WHERE id = :id');
    $stmt->execute(['sort_order' => $sortOrder, 'id' => $id]);
    normalize_milestone_order($pdo, $categoryId);
    $pdo->commit();
    respond(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
