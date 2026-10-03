<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Endpoints;

use App\PLAYLISTS\Repos\PdoPlayerExperienceRepository;
use PDO;
use Throwable;

final class AdminPlayerExperienceListEndpoint
{
    public static function handle(PDO $pdo): void
    {
        if (
            ($_SERVER['REQUEST_METHOD'] ?? 'GET')
            === 'OPTIONS'
        ) {
            self::respond(
                [
                    'ok' => true,
                ]
            );

            return;
        }

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

            return;
        }

        try {
            $repo =
                new PdoPlayerExperienceRepository(
                    $pdo
                );

            self::respond([
                'ok' => true,
                'items' => $repo->listAll(),
            ]);
        } catch (Throwable $e) {
            self::respond(
                [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    private static function respond(
        array $payload,
        int $status = 200
    ): void {
        http_response_code($status);

        if (!headers_sent()) {
            header(
                'Content-Type: application/json; charset=utf-8'
            );

            header(
                'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
            );
        }

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );
    }
}
