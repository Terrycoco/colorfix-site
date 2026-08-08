<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond(['ok' => false, 'error' => 'GET only'], 405);
    }
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        workflow_respond(['ok' => false, 'error' => 'id required'], 400);
    }
    workflow_respond([
        'ok' => true,
        'item' => url_reservation_service($pdo)->getReservation($id),
    ]);
} catch (InvalidArgumentException $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 404);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
