<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    scheduler_respond(['ok' => false, 'error' => 'POST only'], 405);
}

try {
    $payload = scheduler_payload();
    $scheduleId = (int)($payload['queue_item_id'] ?? $payload['schedule_id'] ?? $payload['publication_schedule_id'] ?? 0);
    if ($scheduleId <= 0) throw new RuntimeException('queue_item_id required');
    $reason = trim((string)($payload['reason'] ?? 'Cancelled by admin.'));
    $service = scheduler_service($pdo);
    scheduler_respond(['ok' => true, 'item' => $service->cancel($scheduleId, $reason)]);
} catch (Throwable $e) {
    scheduler_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
