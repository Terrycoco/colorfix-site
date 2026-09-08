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
 * Dumb HTTP door for CREATE.
 *
 * Owns only:
 *   - HTTP method / JSON validation
 *   - waking CreateManager
 *   - passing orders + boss decisions through
 *   - translating Manager result to HTTP/JSON
 *
 * All CREATE workflow decisions remain in CreateManager.
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


        $projectRoot =
            dirname(
                __DIR__,
                3
            );


        $errorReporter =
            new PubErrorReporter(
                $projectRoot
                . '/app/PUB/Errors/pub_errors.log'
            );


        try {
            $data =
                self::requestJson();


            $orders =
                $data[
                    'orders'
                ]
                ?? null;


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
             * Boss decisions are passed through without interpretation.
             *
             * Preferred shape:
             *
             *   {
             *     "unshipped": "check" | "replace",
             *     "shipped":   "check" | "new_version"
             *   }
             *
             * duplicate_policy remains accepted temporarily so the backend
             * can be deployed before the frontend switch.
             */
            $existingPolicy =
                $data[
                    'existing_policy'
                ]
                ?? $data[
                    'duplicate_policy'
                ]
                ?? 'check';


            $manager =
                new CreateManager(
                    $pdo,
                    $projectRoot
                );


            $result =
                $manager->processBatch(
                    array_values(
                        $orders
                    ),
                    $existingPolicy
                );


            if (
                (
                    $result[
                        'code'
                    ]
                    ?? ''
                ) ===
                'existing_asset_warning'
            ) {
                self::sendJson(
                    409,
                    [
                        'ok' =>
                            false,

                        'code' =>
                            'existing_asset_warning',

                        'order_count' =>
                            count(
                                $orders
                            ),

                        'match_count' =>
                            (int)(
                                $result[
                                    'match_count'
                                ]
                                ?? 0
                            ),

                        'unshipped_count' =>
                            (int)(
                                $result[
                                    'unshipped_count'
                                ]
                                ?? 0
                            ),

                        'shipped_count' =>
                            (int)(
                                $result[
                                    'shipped_count'
                                ]
                                ?? 0
                            ),

                        'matches' =>
                            is_array(
                                $result[
                                    'matches'
                                ]
                                ?? null
                            )
                                ? array_values(
                                    $result[
                                        'matches'
                                    ]
                                )
                                : [],

                        'existing_policy' =>
                            is_array(
                                $result[
                                    'existing_policy'
                                ]
                                ?? null
                            )
                                ? $result[
                                    'existing_policy'
                                ]
                                : [
                                    'unshipped' =>
                                        'check',

                                    'shipped' =>
                                        'check',
                                ],

                        'choices' => [
                            'unshipped' => [
                                'replace',
                                'cancel',
                            ],

                            'shipped' => [
                                'new_version',
                                'cancel',
                            ],
                        ],
                    ]
                );


                return;
            }


            $created =
                is_array(
                    $result[
                        'created'
                    ]
                    ?? null
                )
                    ? array_values(
                        $result[
                            'created'
                        ]
                    )
                    : [];


            $queued =
                is_array(
                    $result[
                        'queued'
                    ]
                    ?? null
                )
                    ? array_values(
                        $result[
                            'queued'
                        ]
                    )
                    : [];


            $failed =
                is_array(
                    $result[
                        'failed'
                    ]
                    ?? null
                )
                    ? array_values(
                        $result[
                            'failed'
                        ]
                    )
                    : [];


            self::sendJson(
                200,
                [
                    'ok' =>
                        true,

                    'order_count' =>
                        count(
                            $orders
                        ),

                    'created_count' =>
                        count(
                            $created
                        ),

                    'queued_count' =>
                        count(
                            $queued
                        ),

                    'failed_count' =>
                        count(
                            $failed
                        ),

                    'existing_policy' =>
                        is_array(
                            $result[
                                'existing_policy'
                            ]
                            ?? null
                        )
                            ? $result[
                                'existing_policy'
                            ]
                            : [],

                    'existing_match_count' =>
                        (int)(
                            $result[
                                'existing_match_count'
                            ]
                            ?? 0
                        ),

                    'replaced_count' =>
                        (int)(
                            $result[
                                'replaced_count'
                            ]
                            ?? 0
                        ),

                    'new_version_count' =>
                        (int)(
                            $result[
                                'new_version_count'
                            ]
                            ?? 0
                        ),

                    'created' =>
                        $created,

                    'queued' =>
                        $queued,

                    'failed' =>
                        $failed,
                ]
            );

        } catch (Throwable $e) {
            $failure =
                $errorReporter->report(
                    $e,
                    [
                        'stage' =>
                            'create',

                        'code' =>
                            'create_endpoint_failure',
                    ]
                );


            self::sendJson(
                500,
                [
                    'ok' =>
                        false,

                    'error' =>
                        $failure[
                            'error'
                        ]
                        ?? $e->getMessage(),

                    'code' =>
                        $failure[
                            'code'
                        ]
                        ?? 'create_endpoint_failure',

                    'error_class' =>
                        get_class(
                            $e
                        ),

                    'error_file' =>
                        $e->getFile(),

                    'error_line' =>
                        $e->getLine(),
                ]
            );
        }
    }


    /**
     * @return array<string, mixed>
     */
    private static function requestJson(): array
    {
        $raw =
            file_get_contents(
                'php://input'
            )
            ?: '';


        $data =
            json_decode(
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
        http_response_code(
            $status
        );


        if (!headers_sent()) {
            header(
                'Content-Type: application/json; charset=UTF-8'
            );
        }


        $json =
            json_encode(
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
