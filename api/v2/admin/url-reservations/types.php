<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond(['ok' => false, 'error' => 'GET only'], 405);
    }
    $includeInactive = ($_GET['include_inactive'] ?? '1') !== '0';
    workflow_respond([
        'ok' => true,
        'items' => url_reservation_service($pdo)->listTypes($includeInactive),
    ]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
