<?php
declare(strict_types=1);

namespace App\REX\Endpoints;

use App\REX\Services\RexPlaylistExperienceSyncService;
use InvalidArgumentException;
use PDO;
use Throwable;

final class RexPlaylistExperiencesEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        try {
            $method = strtoupper(trim(
                (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
            ));

            $service = new RexPlaylistExperienceSyncService($pdo);

            if ($method === 'GET') {
                $playlistId = (int)($_GET['playlist_id'] ?? 0);

                if ($playlistId <= 0) {
                    throw new InvalidArgumentException(
                        'Valid playlist ID is required.'
                    );
                }

                self::respond([
                    'ok' => true,
                    'data' => $service->inspectPlaylist($playlistId),
                ]);
            }

            if ($method === 'POST') {
                $input = self::jsonInput();
                $playlistId = (int)($input['playlist_id'] ?? 0);

                if ($playlistId <= 0) {
                    throw new InvalidArgumentException(
                        'Valid playlist ID is required.'
                    );
                }

                $sync = $service->syncPlaylist($playlistId);
                $inspection = $service->inspectPlaylist($playlistId);

                self::respond([
                    'ok' => true,
                    'sync' => $sync,
                    'data' => $inspection,
                ]);
            }

            self::respond([
                'ok' => false,
                'error' => 'GET or POST only',
            ], 405);
        } catch (InvalidArgumentException $e) {
            self::respond([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
        } catch (Throwable $e) {
            self::respond([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private static function jsonInput(): array
    {
        $raw = file_get_contents('php://input');

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new InvalidArgumentException(
                'Request body must be valid JSON.'
            );
        }

        return $decoded;
    }

    private static function respond(
        array $payload,
        int $status = 200,
    ): never {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
}
