<?php
declare(strict_types=1);

namespace App\ANA\Endpoints;

use App\ANA\Repos\PdoANAReportRepository;
use App\ANA\Services\ANAReportService;
use PDO;
use Throwable;

final class EventsEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        try {
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $service = new ANAReportService(
                new PdoANAReportRepository($pdo)
            );

            if ($method === 'GET') {
                self::handleGet($service);
            }

            if ($method === 'DELETE') {
                self::handleDelete($service);
            }

            self::respond([
                'ok' => false,
                'error' => 'GET or DELETE only',
            ], 405);
        } catch (Throwable $e) {
            self::respond([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private static function handleGet(ANAReportService $service): void
    {
        $resourceType = trim((string)($_GET['resource_type'] ?? ''));
        $resourceId = (int)($_GET['resource_id'] ?? 0);
        $eventKey = trim((string)($_GET['event_key'] ?? ''));

        // Missing source_key means SQL NULL. Present-but-empty remains ''.
        $sourceKey = array_key_exists('source_key', $_GET)
            ? (string)$_GET['source_key']
            : null;

        if ($resourceType === '' || $resourceId <= 0 || $eventKey === '') {
            self::respond([
                'ok' => false,
                'error' => 'resource_type, resource_id and event_key required',
            ], 400);
        }

        self::respond([
            'ok' => true,
            'items' => $service->listEventsForResource(
                $resourceType,
                $resourceId,
                $eventKey,
                $sourceKey
            ),
        ]);
    }

    private static function handleDelete(ANAReportService $service): void
    {
        $input = json_decode(
            (string)file_get_contents('php://input'),
            true
        );

        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            self::respond([
                'ok' => false,
                'error' => 'id required',
            ], 400);
        }

        if (!$service->deleteEventById($id)) {
            self::respond([
                'ok' => false,
                'error' => 'Analytics event not found',
            ], 404);
        }

        self::respond([
            'ok' => true,
            'deleted' => 1,
            'id' => $id,
        ]);
    }

    private static function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
}
