<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    scheduler_respond(['ok' => false, 'error' => 'POST only'], 405);
}

try {
    $payload = scheduler_payload();
    $publishingJobId = (int)($payload['package_batch_id'] ?? $payload['publish_job_id'] ?? 0);
    if ($publishingJobId <= 0) throw new RuntimeException('package_batch_id required');
    $service = scheduler_service($pdo);
    $item = $service->enqueueMissingForJob($publishingJobId, $payload);
    scheduler_respond(['ok' => true, 'item' => $item]);
} catch (Throwable $e) {
    scheduler_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
