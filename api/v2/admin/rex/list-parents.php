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
use App\REX\Services\RexReservationRelationships;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $relationships = new RexReservationRelationships(
        new PdoRexReservationRepository($pdo)
    );
    $items = $relationships->parentRelationships(
        rex_admin_positive_int($_GET['child_reservation_id'] ?? null, 'Child reservation ID'),
        rex_admin_optional_string($_GET['relationship_key'] ?? null)
    );

    workflow_respond([
        'ok' => true,
        'items' => rex_admin_relationships_payload($items),
    ]);
} catch (InvalidArgumentException $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
