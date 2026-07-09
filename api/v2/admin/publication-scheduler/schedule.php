<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    scheduler_respond(['ok' => false, 'error' => 'POST only'], 405);
}

try {
    $payload = scheduler_payload();
    $publicationId = (int)($payload['package_id'] ?? $payload['publish_output_id'] ?? $payload['published_asset_id'] ?? 0);
    $scheduledAt = trim((string)($payload['scheduled_at'] ?? ''));
    if ($publicationId <= 0) throw new RuntimeException('package_id required');
    $service = scheduler_service($pdo);
    $item = $scheduledAt === ''
        ? $service->scheduleNextAvailable($publicationId, $payload)
        : $service->schedulePublication($publicationId, $scheduledAt, $payload);
    scheduler_respond(['ok' => true, 'item' => $item]);
} catch (Throwable $e) {
    scheduler_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
