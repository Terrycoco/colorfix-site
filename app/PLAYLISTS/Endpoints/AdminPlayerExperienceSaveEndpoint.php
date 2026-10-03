<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Endpoints;

use App\PLAYLISTS\Repos\PdoPlayerExperienceRepository;
use InvalidArgumentException;
use PDO;
use Throwable;

final class AdminPlayerExperienceSaveEndpoint
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
            ($_SERVER['REQUEST_METHOD'] ?? 'POST')
            !== 'POST'
        ) {
            self::respond(
                [
                    'ok' => false,
                    'error' => 'POST only',
                ],
                405
            );

            return;
        }

        try {
            $payload =
                self::requestJson();

            $repo =
                new PdoPlayerExperienceRepository(
                    $pdo
                );

            $id =
                $repo->save(
                    $payload
                );

            self::respond([
                'ok' => true,
                'player_experience_id' => $id,
            ]);
        } catch (InvalidArgumentException $e) {
            self::respond(
                [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ],
                400
            );
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

    private static function requestJson(): array
    {
        $raw =
            file_get_contents(
                'php://input'
            );

        $payload =
            json_decode(
                is_string($raw)
                    ? $raw
                    : '',
                true
            );

        if (!is_array($payload)) {
            throw new InvalidArgumentException(
                'Invalid JSON'
            );
        }

        return $payload;
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
