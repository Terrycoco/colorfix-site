<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Endpoints;

use App\PLAYLISTS\Services\PlaylistSetService;
use PDO;
use Throwable;

final class AdminPlaylistSetListEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            self::respond(['ok' => false, 'error' => 'GET only'], 405);
        }

        try {
            $service = new PlaylistSetService($pdo);
            self::respond([
                'ok' => true,
                'sets' => $service->listSets(true),
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
