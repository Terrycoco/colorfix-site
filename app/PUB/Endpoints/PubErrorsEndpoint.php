<?php
declare(strict_types=1);

namespace App\PUB\Endpoints;

use App\PUB\Repos\PdoPubErrorRepository;
use PDO;
use Throwable;

/**
 * PUB ERRORS ENDPOINT
 *
 * Admin read-only access to the permanent PUB error log.
 *
 * Owns only:
 *   - HTTP method validation
 *   - reading pub_errors through the repository
 *   - returning JSON for the PUB Errors screen
 *
 * This is not a PUB workflow stage.
 */
final class PubErrorsEndpoint
{
    public static function handle(
        PDO $pdo
    ): void {
        $method =
            strtoupper(
                (string)(
                    $_SERVER[
                        'REQUEST_METHOD'
                    ]
                    ?? 'GET'
                )
            );


        if ($method === 'OPTIONS') {
            self::sendJson(
                200,
                [
                    'ok' =>
                        true,
                ]
            );

            return;
        }


        if ($method !== 'GET') {
            self::sendJson(
                405,
                [
                    'ok' =>
                        false,

                    'error' =>
                        'GET only.',
                ]
            );

            return;
        }


        try {
            $limit =
                (int)(
                    $_GET[
                        'limit'
                    ]
                    ?? 200
                );

            $limit =
                max(
                    1,
                    min(
                        $limit,
                        500
                    )
                );


            $repo =
                new PdoPubErrorRepository(
                    $pdo
                );


            $items =
                $repo->listRecent(
                    $limit
                );


            self::sendJson(
                200,
                [
                    'ok' =>
                        true,

                    'count' =>
                        count(
                            $items
                        ),

                    'items' =>
                        $items,
                ]
            );

        } catch (Throwable $e) {
            /*
             * Do not try to write this failure back into pub_errors.
             * If the error-log table itself is unavailable, recursive
             * error reporting would only obscure the original problem.
             */
            self::sendJson(
                500,
                [
                    'ok' =>
                        false,

                    'error' =>
                        $e->getMessage(),
                ]
            );
        }
    }


    /**
     * @param array<string, mixed> $payload
     */
    private static function sendJson(
        int $status,
        array $payload
    ): void {
        http_response_code(
            $status
        );

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        header(
            'Cache-Control: no-store'
        );


        $json =
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            );


        if (!is_string($json)) {
            http_response_code(
                500
            );

            echo '{"ok":false,"error":"Failed to encode PUB Errors response."}';

            return;
        }


        echo $json;
    }
}
