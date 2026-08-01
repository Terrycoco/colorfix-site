<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

require_method('POST');

try {
    $data = read_json_payload();
    $id = milestone_int($data['id'] ?? 0);
    $categoryId = milestone_int($data['category_id'] ?? 0);
    $title = trim((string)($data['title'] ?? ''));
    $notes = (string)($data['notes'] ?? '');

    if ($categoryId <= 0 || !category_exists($pdo, $categoryId)) {
        respond(['ok' => false, 'error' => 'Valid category is required'], 400);
    }
    if ($title === '') {
        respond(['ok' => false, 'error' => 'Milestone title is required'], 400);
    }

    $pdo->beginTransaction();
    if ($id > 0) {
        $existing = get_milestone($pdo, $id);
        if (!$existing) respond(['ok' => false, 'error' => 'Milestone not found'], 404);
        normalize_milestone_order($pdo, (int)$existing['category_id']);
        if ((int)$existing['category_id'] !== $categoryId || isset($data['position_mode'])) {
            $oldCategoryId = (int)$existing['category_id'];
            $stmt = $pdo->prepare('UPDATE milestones SET category_id = :category_id, sort_order = 999999 WHERE id = :id');
            $stmt->execute(['category_id' => $categoryId, 'id' => $id]);
            normalize_milestone_order($pdo, $oldCategoryId);
            $position = requested_position($pdo, $categoryId, $data, $id);
            move_active_milestones_for_insert($pdo, $categoryId, $position);
            $stmt = $pdo->prepare('UPDATE milestones SET title = :title, notes = :notes, sort_order = :sort_order WHERE id = :id');
            $stmt->execute(['title' => $title, 'notes' => $notes, 'sort_order' => $position, 'id' => $id]);
        } else {
            $stmt = $pdo->prepare('UPDATE milestones SET title = :title, notes = :notes WHERE id = :id');
            $stmt->execute(['title' => $title, 'notes' => $notes, 'id' => $id]);
        }
        normalize_milestone_order($pdo, $categoryId);
    } else {
        normalize_milestone_order($pdo, $categoryId);
        $position = requested_position($pdo, $categoryId, $data, null);
        move_active_milestones_for_insert($pdo, $categoryId, $position);
        $stmt = $pdo->prepare('INSERT INTO milestones (category_id, title, notes, sort_order) VALUES (:category_id, :title, :notes, :sort_order)');
        $stmt->execute(['category_id' => $categoryId, 'title' => $title, 'notes' => $notes, 'sort_order' => $position]);
        $id = (int)$pdo->lastInsertId();
        normalize_milestone_order($pdo, $categoryId);
    }
    $pdo->commit();
    respond(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
