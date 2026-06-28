<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    scheduler_respond(['ok' => false, 'error' => 'POST only'], 405);
}

try {
    $payload = scheduler_payload();
    $ids = $payload['publishing_asset_ids']
        ?? $payload['publish_output_ids']
        ?? $payload['publishing_job_ids']
        ?? [];
    if (!is_array($ids)) {
        $ids = [$ids];
    }
    $service = scheduler_service($pdo);
    scheduler_respond(['ok' => true, 'item' => $service->deleteUnscheduled($ids)]);
} catch (RuntimeException $e) {
    scheduler_respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    scheduler_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
