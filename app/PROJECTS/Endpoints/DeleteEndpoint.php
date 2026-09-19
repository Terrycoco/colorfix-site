<?php
declare(strict_types=1);

namespace App\PROJECTS\Endpoints;

use App\PROJECTS\Managers\ProjectManager;
use PDO;
use Throwable;

final class DeleteEndpoint
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

            $projectId =
                isset($payload['project_id'])
                    ? (int)$payload['project_id']
                    : 0;

            if ($projectId <= 0) {
                self::respond([
                    'ok' => false,
                    'error' => 'project_id required',
                ], 400);
                return;
            }

            $manager =
                new ProjectManager(
                    $pdo
                );

            $deleted =
                $manager
                    ->deleteProject(
                        $projectId
                    );

            if (!$deleted) {
                self::respond([
                    'ok' => false,
                    'error' => 'Project not found.',
                ], 404);
                return;
            }

            self::respond([
                'ok' => true,
                'project_id' => $projectId,
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
