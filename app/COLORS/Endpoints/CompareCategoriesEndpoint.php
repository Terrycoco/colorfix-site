<?php
declare(strict_types=1);

namespace App\COLORS\Endpoints;

use App\COLORS\Managers\CategoryRecalcManager;
use PDO;
use Throwable;

/** Authenticated via admin/colors/compare.php doorbell. Never writes to DB. */
final class CompareCategoriesEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            return;
        }

        try {
            $body = json_decode((string)file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($body)) {
                throw new \InvalidArgumentException('JSON object required.');
            }
            foreach (['after_id', 'limit', 'sample_limit'] as $key) {
                if (isset($body[$key]) && filter_var($body[$key], FILTER_VALIDATE_INT) === false) {
                    throw new \InvalidArgumentException('Invalid integer: ' . $key);
                }
            }
            $result = (new CategoryRecalcManager($pdo))->comparePage(
                max(0, (int)($body['after_id'] ?? 0)),
                max(1, min(2000, (int)($body['limit'] ?? 500))),
                max(0, min(50, (int)($body['sample_limit'] ?? 20)))
            );
            echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException|\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            // Do not disclose SQL internals or stack traces through the endpoint.
            error_log('COLORS compare failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Category comparison failed; check the server log.']);
        }
    }
}
