<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/_helpers.php';

use App\REX\Repos\PdoRexReservationRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond([
            'ok' => false,
            'error' => 'POST only',
        ], 405);
    }

    $data = rex_admin_json_input();

    $reservationId = rex_admin_positive_int(
        $data['reservation_id'] ?? null,
        'Reservation ID'
    );

    $alias = rex_admin_required_string(
        $data['alias'] ?? null,
        'Alias'
    );

    $repo = new PdoRexReservationRepository($pdo);

    $reservation = $repo->findById($reservationId);

    if (!$reservation) {
        throw new InvalidArgumentException(
            "REX reservation {$reservationId} was not found."
        );
    }

    $existing = $repo->findByAlias($alias);

    if ($existing) {
        throw new InvalidArgumentException(
            "REX alias '{$alias}' is already in use."
        );
    }

    $created = $repo->addAlias(
        $reservationId,
        $alias
    );

    workflow_respond([
        'ok' => true,
        'item' => [
            'id' => $created->id,
            'reservation_id' => $created->reservationId,
            'alias' => $created->alias,
            'created_at' => $created->createdAt,
            'updated_at' => $created->updatedAt,
        ],
    ]);

} catch (InvalidArgumentException $e) {
    workflow_respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 400);

} catch (Throwable $e) {
    workflow_respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}