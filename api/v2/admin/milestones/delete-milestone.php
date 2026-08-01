<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

require_method('POST');

try {
    $data = read_json_payload();
    $id = milestone_int($data['id'] ?? 0);
    if ($id <= 0) respond(['ok' => false, 'error' => 'Milestone id required'], 400);

    $pdo->beginTransaction();
    $milestone = get_milestone($pdo, $id);
    if (!$milestone) respond(['ok' => false, 'error' => 'Milestone not found'], 404);
    $stmt = $pdo->prepare('UPDATE milestones SET deleted_at = NOW() WHERE id = :id');
    $stmt->execute(['id' => $id]);
    normalize_milestone_order($pdo, (int)$milestone['category_id']);
    $pdo->commit();
    respond(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
