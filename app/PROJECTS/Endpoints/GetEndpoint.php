<?php
declare(strict_types=1);

namespace App\PROJECTS\Endpoints;

use App\PROJECTS\Managers\ProjectManager;
use PDO;
use Throwable;

final class GetEndpoint
{
    public static function handle(PDO $pdo): void
    {
        try {
            $projectId =
                isset($_GET['project_id'])
                    ? (int)$_GET['project_id']
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

            $project =
                $manager
                    ->getProject(
                        $projectId
                    );

            if ($project === null) {
                self::respond([
                    'ok' => false,
                    'error' => 'Project not found.',
                ], 404);
                return;
            }

            self::respond([
                'ok' => true,
                'project' => $project,
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
