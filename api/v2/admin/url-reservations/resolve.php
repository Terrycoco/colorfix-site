<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond(['ok' => false, 'error' => 'GET only'], 405);
    }
    $token = trim((string)($_GET['token'] ?? ''));
    workflow_respond([
        'ok' => true,
        'result' => url_reservation_service($pdo)->resolveReservation($token),
    ]);
} catch (InvalidArgumentException $e) {
    workflow_respond(['ok' => false, 'error' => 'Reservation not found'], 404);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
