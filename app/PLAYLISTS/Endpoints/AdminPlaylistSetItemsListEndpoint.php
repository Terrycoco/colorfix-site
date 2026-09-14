<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Endpoints;

use App\PLAYLISTS\Services\PlaylistSetService;
use PDO;
use Throwable;

final class AdminPlaylistSetItemsListEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            self::respond(['ok' => false, 'error' => 'GET only'], 405);
        }

        $setId = isset($_GET['set_id']) ? (int)$_GET['set_id'] : 0;
        if ($setId <= 0) {
            self::respond(['ok' => false, 'error' => 'Valid set_id is required.'], 400);
        }

        try {
            $service = new PlaylistSetService($pdo);
            $result = $service->getSet($setId);

            if ($result === null) {
                self::respond(['ok' => false, 'error' => 'Set not found.'], 404);
            }

            self::respond([
                'ok' => true,
                'set_id' => $setId,
                'items' => $result['items'],
            ]);
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
