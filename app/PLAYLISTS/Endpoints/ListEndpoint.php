<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Endpoints;

use App\PLAYLISTS\Managers\PlaylistManager;
use PDO;
use Throwable;

final class ListEndpoint
{
    public static function handle(PDO $pdo): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            self::sendJson(200, [
                'ok' => true,
            ]);

            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            self::sendJson(405, [
                'ok' => false,
                'error' => 'GET only',
            ]);

            return;
        }

        try {
            $manager =
                new PlaylistManager(
                    $pdo
                );

            self::sendJson(200, [
                'ok' => true,
                'items' =>
                    $manager->listAdminPlaylists(),
            ]);

        } catch (Throwable $e) {
            self::sendJson(500, [
                'ok' => false,
                'error' =>
                    'Failed to load playlists.',
            ]);
        }
    }

    private static function sendJson(
        int $status,
        array $payload
    ): void {
        http_response_code(
            $status
        );

        if (!headers_sent()) {
            header(
                'Content-Type: application/json; charset=utf-8'
            );

            header(
                'Cache-Control: private, max-age=60, stale-while-revalidate=180'
            );
        }

        $json =
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES
            );

        echo $json !== false
            ? $json
            : '{"ok":false,"error":"Could not encode playlist response as JSON."}';
    }
}
