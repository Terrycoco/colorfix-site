<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$groupId = isset($payload['group_id']) ? (int)$payload['group_id'] : 0;
$items = $payload['items'] ?? [];

if ($groupId <= 0) {
    respond(['ok' => false, 'error' => 'group_id required'], 400);
}
if (!is_array($items)) {
    respond(['ok' => false, 'error' => 'items must be array'], 400);
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('DELETE FROM cta_group_items WHERE cta_group_id = :group_id');
    $stmt->execute(['group_id' => $groupId]);

    $insert = $pdo->prepare(
        'INSERT INTO cta_group_items (cta_group_id, cta_id, order_index) VALUES (:group_id, :cta_id, :order_index)'
    );
    foreach ($items as $item) {
        $ctaId = isset($item['cta_id']) ? (int)$item['cta_id'] : 0;
        if ($ctaId <= 0) continue;
        $orderIndex = isset($item['order_index']) ? (int)$item['order_index'] : 0;
        $insert->execute([
            'group_id' => $groupId,
            'cta_id' => $ctaId,
            'order_index' => $orderIndex,
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}

respond(['ok' => true]);
