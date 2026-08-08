<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond(['ok' => false, 'error' => 'POST only'], 405);
    }
    $data = url_reservation_json_body();
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        workflow_respond(['ok' => false, 'error' => 'id required'], 400);
    }
    workflow_respond([
        'ok' => true,
        'item' => url_reservation_service($pdo)->updateReservation($id, $data),
    ]);
} catch (InvalidArgumentException $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
