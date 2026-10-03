<?php
declare(strict_types=1);

namespace App\PALETTES\Endpoints;

use App\PALETTES\Managers\PVManager;
use InvalidArgumentException;
use PDO;
use Throwable;

final class AdminPVListEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                http_response_code(405);
                echo json_encode(['ok' => false, 'error' => 'GET required.']);
                return;
            }

            $projectId = (int)($_GET['project_id'] ?? 0);
            if ($projectId <= 0) {
                throw new InvalidArgumentException('project_id required');
            }

            $ids = [];
            $rawIds = trim((string)($_GET['saved_palette_ids'] ?? ''));
            if ($rawIds !== '') {
                foreach (explode(',', $rawIds) as $value) {
                    $id = (int)trim($value);
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
            }

            $items = (new PVManager($pdo))->listPVsForProject(
                $projectId,
                array_values(array_unique($ids))
            );

            echo json_encode(['ok' => true, 'items' => $items]);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
