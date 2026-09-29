<?php
declare(strict_types=1);

namespace App\PROJECTS\Endpoints;

use App\PROJECTS\Repos\PdoProjectActivityRepository;
use PDO;
use Throwable;

final class ProjectActivityListEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $projectId = (int)($_GET['project_id'] ?? 0);

            if ($projectId <= 0) {
                throw new \InvalidArgumentException(
                    'Valid project ID required.'
                );
            }

            $repository = new PdoProjectActivityRepository($pdo);

            echo json_encode([
                'ok' => true,
                'items' => $repository->listByProjectId($projectId),
            ], JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(400);

            echo json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES);
        }
    }
}
