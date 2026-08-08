<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond(['ok' => false, 'error' => 'GET only'], 405);
    }
    $filters = [
        'type_key' => $_GET['type_key'] ?? '',
        'resource_id' => $_GET['resource_id'] ?? '',
        'active' => $_GET['active'] ?? '',
    ];
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
    workflow_respond([
        'ok' => true,
        'items' => url_reservation_service($pdo)->listReservations($filters, $limit),
    ]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
