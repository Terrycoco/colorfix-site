<?php
declare(strict_types=1);

namespace App\COLORS\Endpoints;

use App\COLORS\Managers\CategoryRecalcManager;
use PDO;
use RuntimeException;
use Throwable;

/** HTTP boundary only; all classification and transaction logic stays in COLORS Manager. */
final class RecalculateCategoriesEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            self::respond(405, ['status' => 'error', 'message' => 'POST required.']);
            return;
        }
        // Reject cross-origin form or script requests. Authentication is supplied by admin/auth.php.
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin !== null) {
            $originParts = parse_url($origin);
            $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
            $originHost = strtolower((string)($originParts['host'] ?? ''));
            $originPort = isset($originParts['port']) ? ':' . $originParts['port'] : '';
            if ($originHost === '' || $originHost . $originPort !== $host) {
                self::respond(403, ['status' => 'error', 'message' => 'Cross-origin request refused.']);
                return;
            }
        }
        if (!str_starts_with(strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? ''))), 'application/json')) {
            self::respond(415, ['status' => 'error', 'message' => 'JSON content type required.']);
            return;
        }
        try {
            $payload = json_decode((string)file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || array_is_list($payload)) {
                throw new \InvalidArgumentException('JSON object required.');
            }
            $confirmation = $payload['confirm'] ?? null;
            if ($confirmation !== 'rebuild_categories') {
                throw new \InvalidArgumentException('Explicit rebuild confirmation required.');
            }
            $batchSize = $payload['batch'] ?? 500;
            if (!is_int($batchSize) || $batchSize < 1 || $batchSize > 2000) {
                throw new \InvalidArgumentException('batch must be an integer from 1 to 2000.');
            }
            $started = microtime(true);
            $result = (new CategoryRecalcManager($pdo))->rebuild($batchSize);
            self::respond(200, [
                'status' => 'success',
                'summary' => [
                    'processed' => $result['processed'],
                    'total_colors' => $result['processed'],
                    'materialized' => $result['materialized'],
                    'clusters' => $result['clusters'],
                    'seconds' => round(microtime(true) - $started, 2),
                    'legacy_color_caches_updated' => false,
                ],
            ]);
        } catch (\JsonException|\InvalidArgumentException $e) {
            self::respond(400, ['status' => 'error', 'message' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            error_log('COLORS recalculate rejected/failed: ' . $e->getMessage());
            $locked = str_contains($e->getMessage(), 'advisory lock');
            self::respond($locked ? 409 : 500, [
                'status' => 'error',
                'message' => $locked ? 'Another COLORS rebuild is running.' : 'Category rebuild failed. No partial changes committed; check server logs.',
            ]);
        } catch (Throwable $e) {
            error_log('COLORS recalculate failed: ' . $e->getMessage());
            self::respond(500, ['status' => 'error', 'message' => 'Category rebuild failed; check server logs.']);
        }
    }

    private static function respond(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
