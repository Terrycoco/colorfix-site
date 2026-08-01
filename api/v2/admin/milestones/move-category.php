<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

require_method('POST');

try {
    $data = read_json_payload();
    $id = milestone_int($data['id'] ?? 0);
    $direction = (string)($data['direction'] ?? '');
    if ($id <= 0 || !in_array($direction, ['up', 'down'], true)) {
        respond(['ok' => false, 'error' => 'Category id and direction required'], 400);
    }

    $pdo->beginTransaction();
    normalize_category_order($pdo);
    $stmt = $pdo->prepare('SELECT id, sort_order FROM milestone_categories WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute(['id' => $id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) respond(['ok' => false, 'error' => 'Category not found'], 404);

    $op = $direction === 'up' ? '<' : '>';
    $order = $direction === 'up' ? 'DESC' : 'ASC';
    $stmt = $pdo->prepare("SELECT id, sort_order FROM milestone_categories WHERE deleted_at IS NULL AND sort_order {$op} :sort_order ORDER BY sort_order {$order}, id {$order} LIMIT 1");
    $stmt->execute(['sort_order' => (int)$current['sort_order']]);
    $other = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($other) {
        $update = $pdo->prepare('UPDATE milestone_categories SET sort_order = :sort_order WHERE id = :id');
        $update->execute(['sort_order' => (int)$other['sort_order'], 'id' => $id]);
        $update->execute(['sort_order' => (int)$current['sort_order'], 'id' => (int)$other['id']]);
    }
    normalize_category_order($pdo);
    $pdo->commit();
    respond(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
