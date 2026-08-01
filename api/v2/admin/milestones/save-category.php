<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

require_method('POST');

try {
    $data = read_json_payload();
    $id = milestone_int($data['id'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    $previewCount = max(1, min(20, milestone_int($data['preview_count'] ?? 3, 3)));
    $isArchived = !empty($data['is_archived']) ? 1 : 0;

    if ($name === '') {
        respond(['ok' => false, 'error' => 'Category name is required'], 400);
    }

    $pdo->beginTransaction();
    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE milestone_categories SET name = :name, preview_count = :preview_count, is_archived = :is_archived WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['name' => $name, 'preview_count' => $previewCount, 'is_archived' => $isArchived, 'id' => $id]);
    } else {
        $sortOrder = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM milestone_categories WHERE deleted_at IS NULL')->fetchColumn();
        $stmt = $pdo->prepare('INSERT INTO milestone_categories (name, sort_order, preview_count, is_archived) VALUES (:name, :sort_order, :preview_count, :is_archived)');
        $stmt->execute(['name' => $name, 'sort_order' => $sortOrder, 'preview_count' => $previewCount, 'is_archived' => $isArchived]);
        $id = (int)$pdo->lastInsertId();
    }
    normalize_category_order($pdo);
    $pdo->commit();
    respond(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
