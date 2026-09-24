<?php
declare(strict_types=1);

namespace App\PROJECTS\Endpoints;

use App\PROJECTS\Managers\ProjectScopeManager;
use PDO;
use Throwable;

final class ScopeSaveEndpoint
{
    public static function handle(
        PDO $pdo
    ): void {
        try {
            if (
                strtoupper(
                    (string)(
                        $_SERVER['REQUEST_METHOD']
                        ?? ''
                    )
                ) !== 'POST'
            ) {
                self::respond([
                    'ok' =>
                        false,

                    'error' =>
                        'POST required.',
                ], 405);

                return;
            }

            $raw =
                file_get_contents(
                    'php://input'
                );

            $payload =
                json_decode(
                    $raw ?: '',
                    true
                );

            if (!is_array($payload)) {
                $payload =
                    $_POST;
            }

            $manager =
                new ProjectScopeManager(
                    $pdo
                );

            $scope =
                $manager
                    ->saveScope(
                        $payload
                    );

            self::respond([
                'ok' =>
                    true,

                'scope_id' =>
                    (int)$scope['id'],

                'scope' =>
                    $scope,
            ]);

        } catch (Throwable $e) {
            self::respond([
                'ok' =>
                    false,

                'error' =>
                    $e->getMessage(),
            ], 400);
        }
    }


    /**
     * @param array<string, mixed> $payload
     */
    private static function respond(
        array $payload,
        int $status = 200
    ): void {
        http_response_code(
            $status
        );

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );
    }
}
