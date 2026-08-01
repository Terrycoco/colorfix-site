<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

require_method('POST');

try {
    $data = read_json_payload();
    $id = milestone_int($data['id'] ?? 0);
    $achieved = !empty($data['achieved']);
    if ($id <= 0) respond(['ok' => false, 'error' => 'Milestone id required'], 400);

    $milestone = get_milestone($pdo, $id);
    if (!$milestone) respond(['ok' => false, 'error' => 'Milestone not found'], 404);

    $stmt = $pdo->prepare('UPDATE milestones SET achieved_at = ' . ($achieved ? 'NOW()' : 'NULL') . ' WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute(['id' => $id]);
    respond(['ok' => true]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
