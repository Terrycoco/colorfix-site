<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Endpoints;

use App\PLAYLISTS\Managers\PlaylistManager;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class SaveItemsEndpoint
{
    public static function handle(PDO $pdo): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            self::sendJson(200, [
                'ok' => true,
            ]);

            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::sendJson(405, [
                'ok' => false,
                'error' => 'POST only',
            ]);

            return;
        }

        try {
            $payload =
                self::requestJson();

            $playlistId =
                isset($payload['playlist_id'])
                    ? (int)$payload['playlist_id']
                    : 0;

            $items =
                $payload['items']
                ?? null;

            if ($playlistId <= 0) {
                throw new InvalidArgumentException(
                    'playlist_id required'
                );
            }

            if (!is_array($items)) {
                throw new InvalidArgumentException(
                    'items must be array'
                );
            }

            $manager =
                new PlaylistManager(
                    $pdo
                );

            $result =
                $manager->saveAdminItems(
                    $playlistId,
                    $items
                );

            self::sendJson(200, [
                'ok' => true,
                'rex_sync' =>
                    $result['rex_sync'],
            ]);

        } catch (InvalidArgumentException $e) {
            self::sendJson(400, [
                'ok' => false,
                'error' => $e->getMessage(),
            ]);

        } catch (Throwable $e) {
            self::sendJson(500, [
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestJson(): array
    {
        $raw =
            file_get_contents(
                'php://input'
            ) ?: '';

        $data =
            json_decode(
                $raw,
                true
            );

        if (!is_array($data)) {
            throw new RuntimeException(
                'Valid JSON body required.'
            );
        }

        return $data;
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
