<?php
declare(strict_types=1);

namespace App\PROJECTS\Endpoints;

use App\PROJECTS\Managers\ProjectManager;
use PDO;
use Throwable;

final class ListEndpoint
{
    public static function handle(PDO $pdo): void
    {
        try {
            $manager =
                new ProjectManager(
                    $pdo
                );

            self::respond([
                'ok' => true,
                'items' =>
                    $manager
                        ->listAdminProjects(),
            ]);

        } catch (Throwable $e) {
            self::respond([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );
    }
}
