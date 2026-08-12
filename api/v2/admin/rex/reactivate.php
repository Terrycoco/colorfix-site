<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/_helpers.php';

use App\REX\Repos\PdoRexReservationRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond(['ok' => false, 'error' => 'POST only'], 405);
    }

    $data = rex_admin_json_input();
    $id = rex_admin_positive_int($data['id'] ?? null, 'Reservation ID');
    $repo = new PdoRexReservationRepository($pdo);
    if (!$repo->findById($id)) {
        workflow_respond(['ok' => false, 'error' => 'REX reservation not found'], 404);
    }

    workflow_respond([
        'ok' => true,
        'item' => rex_admin_reservation_payload($repo->reactivate($id)),
    ]);
} catch (InvalidArgumentException $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
