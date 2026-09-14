<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Endpoints;

use App\PLAYLISTS\Services\PlaylistSetService;
use JsonException;
use PDO;
use Throwable;

final class AdminPlaylistSetItemsSaveEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::respond(['ok' => false, 'error' => 'POST only'], 405);
        }

        try {
            $payload = json_decode(
                file_get_contents('php://input') ?: '{}',
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($payload)) {
                self::respond(['ok' => false, 'error' => 'JSON object required.'], 400);
            }

            $setId = isset($payload['set_id']) ? (int)$payload['set_id'] : 0;
            $items = $payload['items'] ?? null;

            if ($setId <= 0) {
                self::respond(['ok' => false, 'error' => 'Valid set_id is required.'], 400);
            }

            if (!is_array($items)) {
                self::respond(['ok' => false, 'error' => 'items array is required.'], 400);
            }

            $service = new PlaylistSetService($pdo);
            $service->replaceItems($setId, $items);
            $result = $service->getSet($setId);

            self::respond([
                'ok' => true,
                'set_id' => $setId,
                'items' => $result['items'] ?? [],
            ]);
        } catch (JsonException $e) {
            self::respond(['ok' => false, 'error' => 'Invalid JSON.'], 400);
        } catch (Throwable $e) {
            self::respond(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private static function respond(array $payload, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
}
