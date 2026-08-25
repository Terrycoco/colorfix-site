<?php
declare(strict_types=1);

namespace App\PUB\Endpoints;

use App\PUB\Create\CreateManager;
use App\PUB\Errors\PubErrorReporter;
use PDO;
use RuntimeException;
use Throwable;

/**
 * CREATE ENDPOINT
 *
 * Public doorbell for the CREATE department.
 *
 * This endpoint owns ONLY HTTP/request concerns:
 *   - accept one CREATE request
 *   - validate that orders were supplied
 *   - wake CreateManager
 *   - hand the Manager the orders
 *   - translate the Manager result back to HTTP/JSON
 *
 * Each order contains EITHER:
 *
 *   NEW
 *     { "box": { ... } }
 *
 *   REDO
 *     { "pub_asset_id": 123 }
 *
 * CreateManager decides which kind of order it is.
 *
 * It must NOT:
 *   - decide NEW vs REDO
 *   - fetch filed Creator ingredients
 *   - instantiate any Creator
 *   - choose a Creator
 *   - inspect Creator ingredients
 *   - persist finished assets
 *
 * CreateManager owns everything after the doorbell rings.
 */
final class CreateEndpoint
{
    public static function handle(PDO $pdo): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            self::sendJson(200, [
                'ok' => true,
            ]);

            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::sendJson(405, [
                'ok' => false,
                'error' => 'POST only',
            ]);

            return;
        }

        $projectRoot = dirname(__DIR__, 3);

        $errorReporter = new PubErrorReporter(
            $projectRoot . '/app/PUB/Errors/pub_errors.log'
        );

        try {
            $data = self::requestJson();

            $orders = $data['orders'] ?? null;

            /*
             * REQUEST COMPLETENESS ONLY.
             *
             * The endpoint does not interpret individual orders.
             * CreateManager owns NEW vs REDO and all CREATE routing.
             */
            if (!is_array($orders)) {
                throw new RuntimeException(
                    'orders array required.'
                );
            }

            if ($orders === []) {
                throw new RuntimeException(
                    'At least one CREATE order is required.'
                );
            }

            /*
             * WAKE CREATE MANAGER.
             *
             * CreateManager is intentionally created only when a
             * CREATE request arrives. The Manager is responsible for
             * waking the one specialist Creator needed for each order.
             *
             * The Manager constructor is expected to own lazy staffing;
             * the endpoint must never construct Creators itself.
             */
            $manager = new CreateManager(
                $pdo,
                $projectRoot
            );

            $result = $manager->processBatch(
                array_values($orders)
            );

            $created = is_array($result['created'] ?? null)
                ? array_values($result['created'])
                : [];

            $queued = is_array($result['queued'] ?? null)
                ? array_values($result['queued'])
                : [];

            $failed = is_array($result['failed'] ?? null)
                ? array_values($result['failed'])
                : [];

            self::sendJson(200, [
                'ok' => true,
                'order_count' => count($orders),
                'created_count' => count($created),
                'queued_count' => count($queued),
                'failed_count' => count($failed),
                'created' => $created,
                'queued' => $queued,
                'failed' => $failed,
            ]);

        } catch (Throwable $e) {
            $failure = $errorReporter->report(
                $e,
                [
                    'stage' => 'create',
                    'code' => 'create_endpoint_failure',
                ]
            );

            self::sendJson(500, [
                'ok' => false,
                'error' =>
                    $failure['error']
                    ?? $e->getMessage(),
                'code' =>
                    $failure['code']
                    ?? 'create_endpoint_failure',
                'error_class' =>
                    get_class($e),
                'error_file' =>
                    $e->getFile(),
                'error_line' =>
                    $e->getLine(),
            ]);
        }
    }


    /**
     * @return array<string, mixed>
     */
    private static function requestJson(): array
    {
        $raw = file_get_contents('php://input') ?: '';

        $data = json_decode(
            $raw,
            true
        );

        if (!is_array($data)) {
            throw new RuntimeException(
                'Valid JSON body required.'
            );
        }

        return $data;
    }


    private static function sendJson(
        int $status,
        array $payload
    ): void {
        http_response_code($status);

        if (!headers_sent()) {
            header(
                'Content-Type: application/json; charset=UTF-8'
            );
        }

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            echo '{"ok":false,"error":"Could not encode PUB response as JSON."}';
            return;
        }

        echo $json;
    }
}