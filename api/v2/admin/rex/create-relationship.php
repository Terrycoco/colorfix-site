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
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond(['ok' => false, 'error' => 'POST only'], 405);
    }

    $data = rex_admin_json_input();
    $repo = new PdoRexReservationRepository($pdo);
    $relationships = new RexReservationRelationships($repo);

    $link = $relationships->create(
        rex_admin_positive_int($data['parent_reservation_id'] ?? null, 'Parent reservation ID'),
        rex_admin_positive_int($data['child_reservation_id'] ?? null, 'Child reservation ID'),
        rex_admin_required_string($data['relationship_key'] ?? null, 'Relationship key'),
        rex_admin_nonnegative_int($data['sort_order'] ?? 0, 'Sort order')
    );

    workflow_respond([
        'ok' => true,
        'item' => [
            'id' => $link->id,
            'parent_reservation_id' => $link->parentReservationId,
            'child_reservation_id' => $link->childReservationId,
            'relationship_key' => $link->relationshipKey,
            'sort_order' => $link->sortOrder,
            'created_at' => $link->createdAt,
            'updated_at' => $link->updatedAt,
        ],
    ]);
} catch (InvalidArgumentException $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
