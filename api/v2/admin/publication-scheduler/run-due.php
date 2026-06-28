<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__ . '/_bootstrap.php';

use App\Lib\SecretBox;
use App\Repos\PdoPublicationScheduleRepository;
use App\Repos\PdoPublisherRepository;
use App\Services\PublicationExecutor;
use App\Services\PublicationScheduler;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    scheduler_respond(['ok' => false, 'error' => 'POST only'], 405);
}

try {
    $payload = scheduler_payload();
    $limit = max(1, min(25, (int)($payload['limit'] ?? 5)));
    $workerId = trim((string)($payload['worker_id'] ?? ''));
    $repo = new PdoPublicationScheduleRepository($pdo);
    $service = new PublicationScheduler($repo);
    $executor = new PublicationExecutor($repo, new PdoPublisherRepository($pdo), new SecretBox());
    scheduler_respond(['ok' => true, 'item' => $service->runDue($executor, $limit, $workerId ?: null)]);
} catch (Throwable $e) {
    scheduler_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
