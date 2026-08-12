<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/_helpers.php';

use App\REX\DTO\RexCreateReservationRequest;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReserver;
use App\REX\Services\RexTokenGenerator;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond(['ok' => false, 'error' => 'POST only'], 405);
    }

    $data = rex_admin_json_input();
    $repo = new PdoRexReservationRepository($pdo);
    $reservation = (new RexReserver(
        $repo,
        new RexTokenGenerator()
    ))->reserve(new RexCreateReservationRequest(
        label: rex_admin_required_string($data['label'] ?? null, 'Label'),
        resolverKey: rex_admin_required_string($data['resolver_key'] ?? null, 'Resolver key'),
        resourceType: rex_admin_required_string($data['resource_type'] ?? null, 'Resource type'),
        resourceId: rex_admin_positive_int($data['resource_id'] ?? null, 'Resource ID'),
        sourceKey: rex_admin_optional_string($data['source_key'] ?? null),
        adminNote: rex_admin_optional_string($data['admin_note'] ?? null),
        context: rex_admin_context($data['context'] ?? []),
    ));

    workflow_respond([
        'ok' => true,
        'item' => rex_admin_reservation_payload($reservation),
    ]);
} catch (InvalidArgumentException $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
