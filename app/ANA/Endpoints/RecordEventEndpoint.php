<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');



use App\ANA\DTO\ANAEvent;
use App\ANA\Repos\PdoANAEventRepository;
use App\ANA\Services\ANAService;

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {



    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respond(['ok' => false, 'error' => 'POST only'], 405);
    }

    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $eventKey = trim((string)($data['event_key'] ?? ''));

    if ($eventKey === '') {
        respond(['ok' => false, 'error' => 'event_key required'], 400);
    }

    $service = new ANAService(
        new PdoANAEventRepository($pdo)
    );

    $eventId = $service->record(
        new ANAEvent(
            eventKey: $eventKey,
            isTest: !empty($data['is_test']),
            reservationId: isset($data['reservation_id']) ? (int)$data['reservation_id'] : null,

            resolverKey: isset($data['resolver_key']) ? (string)$data['resolver_key'] : null,
            resourceType: isset($data['resource_type']) ? (string)$data['resource_type'] : null,
            resourceId: isset($data['resource_id']) ? (int)$data['resource_id'] : null,
            experienceKey: isset($data['experience_key']) ? (string)$data['experience_key'] : null,
            src: isset($data['src']) && $data['src'] !== '' ? (string)$data['src'] : null,
            sessionId: isset($data['session_id']) && $data['session_id'] !== ''
                ? (string)$data['session_id']
                : null,


            referrer: isset($data['referrer']) && $data['referrer'] !== ''
                ? (string)$data['referrer']
                : null,


            viewerId: isset($data['viewer_id']) ? (string)$data['viewer_id'] : null,
            path: isset($data['path']) ? (string)$data['path'] : null,
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
        )
    );

    respond([
        'ok' => true,
        'event_id' => $eventId,
    ]);
} catch (Throwable $e) {
    respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}