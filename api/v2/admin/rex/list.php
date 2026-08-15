<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
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
use App\REX\Resolvers\PlaylistExperienceResolver;
use App\REX\Resolvers\RexResolverRegistry;
use App\REX\Resolvers\ViewerResolver;
use App\REX\Services\RexResolver;
use App\REX\Services\RexReservationRelationships;
use App\REX\Resolvers\RouteResolver;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond([
            'ok' => false,
            'error' => 'GET only',
        ], 405);
    }

    $repo = new PdoRexReservationRepository($pdo);

    $ids = array_values(array_unique(array_filter(
        array_map(
            'intval',
            explode(',', trim((string)($_GET['ids'] ?? '')))
        ),
        static fn(int $id): bool => $id > 0
    )));

    if ($ids) {
        $items = [];

        foreach ($ids as $id) {
            $reservation = $repo->findById($id);

            if ($reservation) {
                $items[] = $reservation;
            }
        }
    } else {
        $resourceType = rex_admin_required_string(
            $_GET['resource_type'] ?? null,
            'Resource type'
        );

        $resourceId = isset($_GET['resource_id']) && $_GET['resource_id'] !== ''
            ? rex_admin_positive_int(
                $_GET['resource_id'],
                'Resource ID'
            )
            : null;

        $items = $repo->search(
            new \App\REX\DTO\RexReservationSearchCriteria(
                resourceType: $resourceType,
                resourceId: $resourceId,
                limit: 500,
            )
        );
    }

    $registry = new RexResolverRegistry();

    $registry->register(
        'playlist_experience',
        new PlaylistExperienceResolver($pdo)
    );
    $registry->register(
        'viewer',
        new ViewerResolver($pdo)
    );

    $registry->register(
        'route',
        new RouteResolver()
    );

    $rexResolver = new RexResolver(
        $repo,
        $registry
    );
    $relationships = new RexReservationRelationships($repo);

    $payload = array_map(
        static function ($reservation) use ($rexResolver, $relationships): array {
            $item = rex_admin_reservation_payload($reservation);

            try {
                $descriptor = $rexResolver->describeReservation($reservation);

                $item['descriptor'] = [
                    'title' => $descriptor->title,
                    'fields' => $descriptor->fields,
                ];
            } catch (Throwable $e) {
                $item['descriptor'] = [
                    'title' => $reservation->label,
                    'fields' => [],
                    'error' => $e->getMessage(),
                ];
            }

            return $item;
        },
        $items
    );

   $resourceTypes = $repo->listResourceTypes();

    if (!in_array('page', $resourceTypes, true)) {
        $resourceTypes[] = 'page';
        sort($resourceTypes);
    }

    workflow_respond([
        'ok' => true,
        'resource_types' => $resourceTypes,
        'items' => $payload,
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
