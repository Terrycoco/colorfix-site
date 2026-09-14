<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Endpoints;

use App\PLAYLISTS\Services\PlaylistSetService;
use JsonException;
use PDO;
use Throwable;

final class AdminPlaylistSetSaveEndpoint
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

            $service = new PlaylistSetService($pdo);
            $saved = $service->saveSet($payload);
            $result = $saved->id !== null ? $service->getSet($saved->id) : null;

            self::respond([
                'ok' => true,
                'set' => $result['set'] ?? ['id' => $saved->id],
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
