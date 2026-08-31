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

use App\REX\DTO\RexCreateReservationRequest;
use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationSearchCriteria;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReserver;
use App\REX\Services\RexTokenGenerator;

function rex_create_normalized_context(array $context): array
{
    foreach ($context as $key => $value) {
        if (is_array($value)) {
            $context[$key] = rex_create_normalized_context($value);
        }
    }

    ksort($context);

    return $context;
}

function rex_create_normalized_experience_key(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = strtolower(trim($value));

    return $value !== ''
        ? $value
        : null;
}

function rex_create_find_reusable_reservation(
    PdoRexReservationRepository $repo,
    RexCreateReservationRequest $request
): ?RexReservation {
    $matches = $repo->search(new RexReservationSearchCriteria(
        resolverKey: $request->resolverKey,
        resourceType: $request->resourceType,
        resourceId: $request->resourceId,
        status: RexReserver::STATUS_ACTIVE,
        limit: 500,
        experienceKey: $request->experienceKey,
    ));

    $requestContext = rex_create_normalized_context($request->context);
    $requestExperienceKey = rex_create_normalized_experience_key(
        $request->experienceKey
    );

    $reusable = [];

    foreach ($matches as $reservation) {
        if (
            rex_create_normalized_experience_key($reservation->experienceKey)
            !== $requestExperienceKey
        ) {
            continue;
        }

        if (
            rex_create_normalized_context($reservation->context)
            !== $requestContext
        ) {
            continue;
        }

        $reusable[] = $reservation;
    }

    usort(
        $reusable,
        fn(RexReservation $a, RexReservation $b): int =>
            $a->id <=> $b->id
    );

    return $reusable[0] ?? null;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond([
            'ok' => false,
            'error' => 'POST only',
        ], 405);
    }

    $data = rex_admin_json_input();
    $repo = new PdoRexReservationRepository($pdo);

    $context = rex_admin_context(
        $data['context'] ?? []
    );

    /*
     * experience_key is now first-class reservation data.
     *
     * Temporary compatibility: if the current admin UI still sends
     * context.experience_key, accept it as input, then remove it from
     * context so new reservations no longer store the duplicate value.
     */
    $experienceKey = rex_create_normalized_experience_key(
        rex_admin_optional_string(
            $data['experience_key']
                ?? ($context['experience_key'] ?? null)
        )
    );

    unset($context['experience_key']);

    $request = new RexCreateReservationRequest(
        label: rex_admin_required_string(
            $data['label'] ?? null,
            'Label'
        ),
        resolverKey: rex_admin_required_string(
            $data['resolver_key'] ?? null,
            'Resolver key'
        ),
        resourceType: rex_admin_required_string(
            $data['resource_type'] ?? null,
            'Resource type'
        ),
        resourceId: rex_admin_positive_int(
            $data['resource_id'] ?? null,
            'Resource ID'
        ),
        adminNote: rex_admin_optional_string(
            $data['admin_note'] ?? null
        ),
        context: $context,
        experienceKey: $experienceKey,
    );

    if (!empty($data['reuse_existing'])) {
        $reservation = rex_create_find_reusable_reservation(
            $repo,
            $request
        );

        if ($reservation) {
            workflow_respond([
                'ok' => true,
                'item' => rex_admin_reservation_payload($reservation),
                'reused' => true,
            ]);
        }
    }

    $reservation = (new RexReserver(
        $repo,
        new RexTokenGenerator() 
    ))->reserve($request);

    workflow_respond([
        'ok' => true,
        'item' => rex_admin_reservation_payload($reservation),
        'reused' => false,
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
s