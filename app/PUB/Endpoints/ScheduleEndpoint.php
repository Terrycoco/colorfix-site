<?php
declare(strict_types=1);

namespace App\PUB\Endpoints;

use App\PUB\Dispatch\DispatchManager;
use App\PUB\Errors\PubErrorReporter;
use App\PUB\Repos\PdoPubAssetRepository;
use App\PUB\Schedule\ScheduleManager;
use PDO;
use RuntimeException;
use Throwable;

/**
 * SCHEDULE ENDPOINT
 *
 * HTTP administration:
 *
 * GET
 *   Workbench:
 *     /schedule.php
 *     /schedule.php?channel=pinterest
 *     /schedule.php?asset_type=pin_palette
 *     /schedule.php?stage=queued
 *
 *   One drawer detail:
 *     /schedule.php?pub_asset_id=49
 *
 *   Controls only:
 *     /schedule.php?meta=config
 *
 * POST
 *   Queue one PACKED asset:
 *     {
 *       "action": "enqueue",
 *       "pub_asset_id": 49
 *     }
 *
 *   Remove one QUEUED asset from automatic Schedule:
 *     {
 *       "action": "dequeue",
 *       "pub_asset_id": 49
 *     }
 *
 *   Manual Send Now:
 *     {
 *       "action": "send_now",
 *       "pub_asset_id": 49
 *     }
 *
 *   Update global settings:
 *     {
 *       "action": "update_settings",
 *       "scheduler_enabled": true,
 *       "timezone": "America/Los_Angeles"
 *     }
 *
 *   Save one channel rule:
 *     {
 *       "action": "save_channel_rule",
 *       "channel": "pinterest",
 *       "enabled": true,
 *       "notify_on_publish": false,
 *       "release_interval_minutes": 360,
 *       "same_source_max": 1,
 *       "same_source_window_minutes": 1440
 *     }
 *
 * CLI
 *   Cron rings handleClock().
 *
 * The public files under api/v2/admin/pub remain dumb doorbells.
 * Scheduling policy belongs only to ScheduleManager.
 */
final class ScheduleEndpoint
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
                    $pdo,
                    $projectRoot
                );

                return;
            }


            if ($method === 'POST') {
                self::handlePost(
                    $pdo,
                    $projectRoot
                );

                return;
            }


            self::sendJson(
                405,
                [
                    'ok' =>
                        false,

                    'error' =>
                        'GET or POST only',
                ]
            );

        } catch (Throwable $e) {
            $failure =
                $errorReporter
                    ->report(
                        $e,
                        [
                            'stage' =>
                                'schedule',

                            'code' =>
                                'schedule_endpoint_failure',
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
                        ?? 'schedule_endpoint_failure',
                ]
            );
        }
    }


    /**
     * CLI-only cron entry.
     */
    public static function handleClock(
        PDO $pdo
    ): void {
        if (PHP_SAPI !== 'cli') {
            http_response_code(
                404
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


        /*
         * DISPATCH SAFETY SWEEP
         *
         * The Schedule clock is PUB's existing reliable hourly wake-up.
         * Before Schedule releases any new work, give Dispatch a chance
         * to reconcile boxes that have remained at SHIPPING beyond the
         * driver's maximum runtime + grace period.
         *
         * DispatchManager remains the lifecycle authority. This endpoint
         * merely rings that department's bell.
         *
         * A reconciliation failure is logged but must not prevent the
         * independent Schedule department from running its own clock.
         */
        try {
            $dispatchManager =
                new DispatchManager(
                    $pdo,
                    $projectRoot
                );


            $dispatchManager
                ->reconcileStaleShipping();

        } catch (Throwable $e) {
            $errorReporter
                ->report(
                    $e,
                    [
                        'stage' =>
                            'dispatch',

                        'code' =>
                            'dispatch_stale_reconciliation_failure',
                    ]
                );
        }


        try {
            $manager =
                new ScheduleManager(
                    $pdo,
                    $projectRoot
                );


            $manager->run();


            /*
             * Healthy cron wake-ups stay silent.
             * Many hosts email cron stdout.
             */
            exit(0);

        } catch (Throwable $e) {
            $failure =
                $errorReporter
                    ->report(
                        $e,
                        [
                            'stage' =>
                                'schedule',

                            'code' =>
                                'schedule_clock_failure',
                        ]
                    );


            $message =
                (string)(
                    $failure[
                        'error'
                    ]
                    ?? $e->getMessage()
                );


            fwrite(
                STDERR,
                '[PUB Schedule] '
                . $message
                . PHP_EOL
            );


            exit(1);
        }
    }


    private static function handleGet(
        PDO $pdo,
        string $projectRoot
    ): void {
        $repo =
            new PdoPubAssetRepository(
                $pdo
            );


        $manager =
            new ScheduleManager(
                $pdo,
                $projectRoot
            );


        $pubAssetId =
            (int)(
                $_GET[
                    'pub_asset_id'
                ]
                ?? 0
            );


        if ($pubAssetId > 0) {
            $asset =
                $repo
                    ->getScheduleAdminById(
                        $pubAssetId
                    );


            if ($asset === null) {
                self::sendJson(
                    404,
                    [
                        'ok' =>
                            false,

                        'error' =>
                            'Schedule asset not found.',
                    ]
                );

                return;
            }


            self::sendJson(
                200,
                [
                    'ok' =>
                        true,

                    'asset' =>
                        $asset,
                ]
            );

            return;
        }


        $meta =
            strtolower(
                trim(
                    (string)(
                        $_GET[
                            'meta'
                        ]
                        ?? ''
                    )
                )
            );


        if ($meta === 'config') {
            self::sendJson(
                200,
                [
                    'ok' =>
                        true,

                    'config' =>
                        $manager
                            ->getConfig(),
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


        $stage =
            trim(
                (string)(
                    $_GET[
                        'stage'
                    ]
                    ?? ''
                )
            );


        self::sendJson(
            200,
            [
                'ok' =>
                    true,

                'assets' =>
                    $repo
                        ->listForScheduleAdmin(
                            $channel !== ''
                                ? $channel
                                : null,

                            $assetType !== ''
                                ? $assetType
                                : null,

                            $stage !== ''
                                ? $stage
                                : null
                        ),

                'filters' => [
                    'channels' =>
                        $repo
                            ->listScheduleChannels(),

                    'asset_types' =>
                        $repo
                            ->listScheduleAssetTypes(),
                ],

                'config' =>
                    $manager
                        ->getConfig(),
            ]
        );
    }


    private static function handlePost(
        PDO $pdo,
        string $projectRoot
    ): void {
        $data =
            self::requestJson();


        $action =
            strtolower(
                trim(
                    (string)(
                        $data[
                            'action'
                        ]
                        ?? ''
                    )
                )
            );


        if ($action === '') {
            throw new RuntimeException(
                'Schedule action is required.'
            );
        }


        $manager =
            new ScheduleManager(
                $pdo,
                $projectRoot
            );


        switch ($action) {
            case 'enqueue':
                $pubAssetId =
                    self::requirePubAssetId(
                        $data
                    );


                $result =
                    $manager
                        ->enqueueAsset(
                            $pubAssetId
                        );


                self::sendJson(
                    200,
                    [
                        'ok' =>
                            true,

                        'action' =>
                            'enqueue',

                        'result' =>
                            $result,
                    ]
                );

                return;


            case 'dequeue':
                $pubAssetId =
                    self::requirePubAssetId(
                        $data
                    );


                $result =
                    $manager
                        ->dequeueAsset(
                            $pubAssetId
                        );


                self::sendJson(
                    200,
                    [
                        'ok' =>
                            true,

                        'action' =>
                            'dequeue',

                        'result' =>
                            $result,
                    ]
                );

                return;


            case 'send_now':
                $pubAssetId =
                    self::requirePubAssetId(
                        $data
                    );


                $result =
                    $manager
                        ->sendNow(
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

                        'action' =>
                            'send_now',

                        'pub_asset_id' =>
                            $pubAssetId,

                        'result' =>
                            $result,
                    ]
                );

                return;


            case 'update_settings':
                $settings =
                    $manager
                        ->updateSettings(
                            self::toBool(
                                $data[
                                    'scheduler_enabled'
                                ]
                                ?? false
                            ),

                            trim(
                                (string)(
                                    $data[
                                        'timezone'
                                    ]
                                    ?? ''
                                )
                            )
                        );


                self::sendJson(
                    200,
                    [
                        'ok' =>
                            true,

                        'action' =>
                            'update_settings',

                        'settings' =>
                            $settings,

                        'config' =>
                            $manager
                                ->getConfig(),
                    ]
                );

                return;


            case 'save_channel_rule':
                $rule =
                    $manager
                        ->saveChannelRule(
                            trim(
                                (string)(
                                    $data[
                                        'channel'
                                    ]
                                    ?? ''
                                )
                            ),

                            self::toBool(
                                $data[
                                    'enabled'
                                ]
                                ?? false
                            ),

                            (int)(
                                $data[
                                    'release_interval_minutes'
                                ]
                                ?? 0
                            ),

                            (int)(
                                $data[
                                    'same_source_max'
                                ]
                                ?? 0
                            ),

                            (int)(
                                $data[
                                    'same_source_window_minutes'
                                ]
                                ?? 0
                            ),

                            self::toBool(
                                $data[
                                    'notify_on_publish'
                                ]
                                ?? false
                            )
                        );


                self::sendJson(
                    200,
                    [
                        'ok' =>
                            true,

                        'action' =>
                            'save_channel_rule',

                        'rule' =>
                            $rule,

                        'config' =>
                            $manager
                                ->getConfig(),
                    ]
                );

                return;


            default:
                self::sendJson(
                    400,
                    [
                        'ok' =>
                            false,

                        'error' =>
                            "Unknown Schedule action '{$action}'.",
                    ]
                );

                return;
        }
    }


    private static function requirePubAssetId(
        array $data
    ): int {
        $pubAssetId =
            (int)(
                $data[
                    'pub_asset_id'
                ]
                ?? 0
            );


        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }


        return $pubAssetId;
    }


    private static function toBool(
        mixed $value
    ): bool {
        if (is_bool($value)) {
            return $value;
        }


        if (is_int($value)) {
            return $value !== 0;
        }


        $normalized =
            strtolower(
                trim(
                    (string)$value
                )
            );


        return in_array(
            $normalized,
            [
                '1',
                'true',
                'yes',
                'on',
            ],
            true
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
            ||
            trim(
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
