<?php
declare(strict_types=1);

namespace App\PUB\Endpoints;

use App\PUB\Dispatch\DispatchManager;
use App\PUB\Errors\PubErrorReporter;
use App\PUB\Repos\PdoPubAssetRepository;
use PDO;
use RuntimeException;
use Throwable;

/**
 * DISPATCH ENDPOINT
 *
 * GET
 *   Workbench list:
 *     /dispatch.php
 *     /dispatch.php?channel=pinterest
 *     /dispatch.php?asset_type=...
 *
 *   One drawer detail:
 *     /dispatch.php?pub_asset_id=49
 *
 *   Filter values:
 *     /dispatch.php?meta=filters
 *
 * POST
 *   Ship one:
 *     { "pub_asset_id": 49 }
 *
 *   Ship all rows already at pipeline_stage=shipping:
 *     {}
 *
 * The endpoint knows PUB administration only.
 * External-channel behavior belongs to DispatchManager/Shippers.
 */
final class DispatchEndpoint
{
    public static function handle(
        PDO $pdo
    ): void {
        if (
            (
                $_SERVER[
                    'REQUEST_METHOD'
                ]
                ?? 'GET'
            ) === 'OPTIONS'
        ) {
            self::sendJson(
                200,
                [
                    'ok' =>
                        true,
                ]
            );

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
            $method =
                strtoupper(
                    (string)(
                        $_SERVER[
                            'REQUEST_METHOD'
                        ]
                        ?? 'GET'
                    )
                );


            if ($method === 'GET') {
                self::handleGet(
                    $pdo
                );

                return;
            }


            if ($method !== 'POST') {
                self::sendJson(
                    405,
                    [
                        'ok' =>
                            false,

                        'error' =>
                            'GET or POST only',
                    ]
                );

                return;
            }


            self::handlePost(
                $pdo,
                $projectRoot
            );

        } catch (Throwable $e) {
            $failure =
                $errorReporter
                    ->report(
                        $e,
                        [
                            'stage' =>
                                'dispatch',

                            'code' =>
                                'dispatch_endpoint_failure',
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
                        ?? 'dispatch_endpoint_failure',
                ]
            );
        }
    }


    private static function handleGet(
        PDO $pdo
    ): void {
        $repo =
            new PdoPubAssetRepository(
                $pdo
            );


        if (
            strtolower(
                trim(
                    (string)(
                        $_GET[
                            'meta'
                        ]
                        ?? ''
                    )
                )
            ) === 'filters'
        ) {
            self::sendJson(
                200,
                [
                    'ok' =>
                        true,

                    'channels' =>
                        $repo
                            ->listDispatchChannels(),

                    'asset_types' =>
                        $repo
                            ->listDispatchAssetTypes(),
                ]
            );

            return;
        }


        $pubAssetId =
            (int)(
                $_GET[
                    'pub_asset_id'
                ]
                ?? 0
            );


        if ($pubAssetId > 0) {
            $item =
                $repo
                    ->getDispatchAdminById(
                        $pubAssetId
                    );


            if ($item === null) {
                self::sendJson(
                    404,
                    [
                        'ok' =>
                            false,

                        'error' =>
                            'Dispatch asset not found.',
                    ]
                );

                return;
            }


            self::sendJson(
                200,
                [
                    'ok' =>
                        true,

                    'item' =>
                        $item,
                ]
            );

            return;
        }


        $channel =
            trim(
                (string)(
                    $_GET[
                        'channel'
                    ]
                    ?? ''
                )
            );

        $assetType =
            trim(
                (string)(
                    $_GET[
                        'asset_type'
                    ]
                    ?? ''
                )
            );


        self::sendJson(
            200,
            [
                'ok' =>
                    true,

                'items' =>
                    $repo
                        ->listForDispatchAdmin(
                            $channel !== ''
                                ? $channel
                                : null,

                            $assetType !== ''
                                ? $assetType
                                : null
                        ),
            ]
        );
    }


    private static function handlePost(
        PDO $pdo,
        string $projectRoot
    ): void {
        $data =
            self::requestJson();


        $pubAssetId =
            (int)(
                $data[
                    'pub_asset_id'
                ]
                ?? 0
            );


        $manager =
            new DispatchManager(
                $pdo,
                $projectRoot
            );


        if ($pubAssetId > 0) {
            $result =
                $manager
                    ->shipOne(
                        $pubAssetId
                    );


            self::sendJson(
                !empty(
                    $result[
                        'ok'
                    ]
                )
                    ? 200
                    : 409,
                [
                    'ok' =>
                        !empty(
                            $result[
                                'ok'
                            ]
                        ),

                    'mode' =>
                        'single',

                    'pub_asset_id' =>
                        $pubAssetId,

                    'result' =>
                        $result,
                ]
            );

            return;
        }


        $result =
            $manager
                ->processShipping();


        $shipped =
            is_array(
                $result[
                    'shipped'
                ]
                ?? null
            )
                ? array_values(
                    $result[
                        'shipped'
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
                    count(
                        $failed
                    ) === 0,

                'mode' =>
                    'batch',

                'shipped_count' =>
                    count(
                        $shipped
                    ),

                'failed_count' =>
                    count(
                        $failed
                    ),

                'shipped' =>
                    $shipped,

                'failed' =>
                    $failed,
            ]
        );
    }


    private static function requestJson(): array
    {
        $raw =
            file_get_contents(
                'php://input'
            );


        if (
            $raw === false
            || trim(
                $raw
            ) === ''
        ) {
            return [];
        }


        $decoded =
            json_decode(
                $raw,
                true
            );


        if (
            !is_array(
                $decoded
            )
        ) {
            throw new RuntimeException(
                'Request body must be valid JSON.'
            );
        }


        return $decoded;
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
                | JSON_UNESCAPED_UNICODE
            );


        if ($json === false) {
            echo '{"ok":false,"error":"Could not encode PUB response as JSON."}';

            return;
        }


        echo $json;
    }
}
