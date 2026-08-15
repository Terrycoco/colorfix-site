<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Analytics\DTO\AnalyticsEvent;
use App\Analytics\Repos\PdoAnalyticsEventRepository;
use App\Analytics\Services\AnalyticsService;

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

    $service = new AnalyticsService(
        new PdoAnalyticsEventRepository($pdo)
    );

    $eventId = $service->record(
        new AnalyticsEvent(
            eventKey: $eventKey,
            reservationId: isset($data['reservation_id']) ? (int)$data['reservation_id'] : null,
            reservationToken: isset($data['reservation_token']) ? (string)$data['reservation_token'] : null,
            resolverKey: isset($data['resolver_key']) ? (string)$data['resolver_key'] : null,
            resourceType: isset($data['resource_type']) ? (string)$data['resource_type'] : null,
            resourceId: isset($data['resource_id']) ? (int)$data['resource_id'] : null,
            experienceKey: isset($data['experience_key']) ? (string)$data['experience_key'] : null,
            src: isset($data['src']) && $data['src'] !== '' ? (string)$data['src'] : null,
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