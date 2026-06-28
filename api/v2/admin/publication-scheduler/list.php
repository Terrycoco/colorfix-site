<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    scheduler_respond(['ok' => false, 'error' => 'GET only'], 405);
}

try {
    $service = scheduler_service($pdo);
    scheduler_respond(['ok' => true] + $service->listQueue($_GET));
} catch (Throwable $e) {
    scheduler_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
