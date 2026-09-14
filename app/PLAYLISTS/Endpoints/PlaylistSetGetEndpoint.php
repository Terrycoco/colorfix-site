<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Endpoints;

use App\PLAYLISTS\Services\PlaylistSetService;
use PDO;
use Throwable;

final class PlaylistSetGetEndpoint
{
    public static function handle(
        PDO $pdo
    ): void {
        if (
            ($_SERVER['REQUEST_METHOD'] ?? 'GET')
            === 'OPTIONS'
        ) {
            http_response_code(200);
            exit;
        }

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        if (
            ($_SERVER['REQUEST_METHOD'] ?? 'GET')
            !== 'GET'
        ) {
            self::respond(
                [
                    'ok' => false,
                    'error' => 'GET only',
                ],
                405
            );
        }

        header(
            'Cache-Control: public, max-age=120, stale-while-revalidate=300'
        );

        $id =
            isset($_GET['id'])
                ? (int)$_GET['id']
                : 0;

        $handle =
            trim(
                (string)(
                    $_GET['handle']
                    ?? ''
                )
            );

        if (
            $id <= 0
            && $handle === ''
        ) {
            self::respond(
                [
                    'ok' => false,
                    'error' =>
                        'Set id or handle is required.',
                ],
                400
            );
        }

        try {
            $service =
                new PlaylistSetService(
                    $pdo
                );

            $set =
                $service
                    ->getPublicSet(
                        $id > 0
                            ? $id
                            : null,
                        $handle !== ''
                            ? $handle
                            : null
                    );

            if ($set === null) {
                self::respond(
                    [
                        'ok' => false,
                        'error' => 'Set not found',
                    ],
                    404
                );
            }

            self::respond([
                'ok' => true,
                'set' => $set,
            ]);

        } catch (Throwable $e) {
            self::respond(
                [
                    'ok' => false,
                    'error' =>
                        $e->getMessage(),
                ],
                500
            );
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function respond(
        array $payload,
        int $status = 200
    ): never {
        http_response_code(
            $status
        );

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        exit;
    }
}
