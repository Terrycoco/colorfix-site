<?php
declare(strict_types=1);

namespace App\PROJECTS\Endpoints;

use App\PROJECTS\Managers\ProjectManager;
use PDO;
use Throwable;

final class SaveEndpoint
{
    public static function handle(PDO $pdo): void
    {
        try {
            if (
                strtoupper(
                    (string)($_SERVER['REQUEST_METHOD'] ?? '')
                ) !== 'POST'
            ) {
                self::respond([
                    'ok' => false,
                    'error' => 'POST required.',
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
                $payload = $_POST;
            }

            $manager =
                new ProjectManager(
                    $pdo
                );

            $projectId =
                $manager
                    ->saveProject(
                        $payload
                    );

            $project =
                $manager
                    ->getProject(
                        $projectId
                    );

            self::respond([
                'ok' => true,
                'project_id' => $projectId,
                'project' => $project,
            ]);

        } catch (Throwable $e) {
            self::respond([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
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
