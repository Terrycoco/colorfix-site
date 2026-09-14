<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Endpoints;

use App\PLAYLISTS\Managers\PlaylistManager;
use PDO;
use RuntimeException;
use Throwable;

final class GetEndpoint
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
            $playlistId =
                isset($_GET['playlist_id'])
                    ? (int)$_GET['playlist_id']
                    : 0;

            if ($playlistId <= 0) {
                throw new RuntimeException(
                    'playlist_id required'
                );
            }

            $manager =
                new PlaylistManager(
                    $pdo
                );

            $result =
                $manager->getAdminPlaylist(
                    $playlistId
                );

            if ($result === null) {
                self::sendJson(404, [
                    'ok' => false,
                    'error' => 'Playlist not found',
                ]);

                return;
            }

            self::sendJson(200, [
                'ok' => true,
                'playlist' =>
                    $result['playlist'],
                'items' =>
                    $result['items'],
            ]);

        } catch (RuntimeException $e) {
            self::sendJson(400, [
                'ok' => false,
                'error' => $e->getMessage(),
            ]);

        } catch (Throwable $e) {
            self::sendJson(500, [
                'ok' => false,
                'error' => 'Failed to load playlist.',
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
                'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
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
