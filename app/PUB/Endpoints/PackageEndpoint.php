<?php
declare(strict_types=1);

namespace App\PUB\Endpoints;

use App\PUB\Errors\PubErrorReporter;
use App\PUB\Package\PackageManager;
use App\PUB\Repos\PdoPubAssetRepository;
use PDO;
use Throwable;

/**
 * PACKAGE ENDPOINT
 *
 * Public doorbell / admin read door for the PACKAGE department.
 *
 * GET
 *   Package workbench rows.
 *
 * GET ?pub_asset_id=123
 *   One Package drawer detail row, including package JSON.
 *
 * POST
 *   Wake PackageManager.
 *
 * Operational Package rules remain in PackageManager / Packagers.
 */
final class PackageEndpoint
{
    public static function handle(PDO $pdo): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            self::sendJson(200, [
                'ok' => true,
            ]);

            return;
        }


        $projectRoot = dirname(__DIR__, 3);

        $errorReporter = new PubErrorReporter(
            $projectRoot . '/app/PUB/Errors/pub_errors.log'
        );


        try {
            $method =
                strtoupper(
                    (string)(
                        $_SERVER['REQUEST_METHOD']
                        ?? 'GET'
                    )
                );


            if ($method === 'GET') {
                self::handleGet(
                    $pdo
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


            self::sendJson(405, [
                'ok' => false,
                'error' => 'GET or POST only',
            ]);

        } catch (Throwable $e) {
            $failure =
                $errorReporter->report(
                    $e,
                    [
                        'stage' =>
                            'package',

                        'code' =>
                            'package_endpoint_failure',
                    ]
                );


            self::sendJson(500, [
                'ok' =>
                    false,

                'error' =>
                    $failure['error']
                    ?? $e->getMessage(),

                'code' =>
                    $failure['code']
                    ?? 'package_endpoint_failure',

                'error_class' =>
                    get_class(
                        $e
                    ),

                'error_file' =>
                    $e->getFile(),

                'error_line' =>
                    $e->getLine(),
            ]);
        }
    }


    private static function handleGet(
        PDO $pdo
    ): void {
        $assets =
            new PdoPubAssetRepository(
                $pdo
            );


        $pubAssetId =
            (int)(
                $_GET['pub_asset_id']
                ?? 0
            );


        if ($pubAssetId > 0) {
            $asset =
                $assets
                    ->getPackageAdminById(
                        $pubAssetId
                    );


            if ($asset === null) {
                self::sendJson(404, [
                    'ok' =>
                        false,

                    'error' =>
                        'Package asset not found.',
                ]);

                return;
            }


            self::sendJson(200, [
                'ok' =>
                    true,

                'asset' =>
                    $asset,
            ]);

            return;
        }


        $channel =
            trim(
                (string)(
                    $_GET['channel']
                    ?? ''
                )
            );

        $assetType =
            trim(
                (string)(
                    $_GET['asset_type']
                    ?? ''
                )
            );


        self::sendJson(200, [
            'ok' =>
                true,

            'assets' =>
                $assets
                    ->listForPackageAdmin(
                        $channel !== ''
                            ? $channel
                            : null,

                        $assetType !== ''
                            ? $assetType
                            : null
                    ),

            'filters' => [
                'channels' =>
                    $assets
                        ->listPackageChannels(),

                'asset_types' =>
                    $assets
                        ->listPackageAssetTypes(),
            ],
        ]);
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
            new PackageManager(
                $pdo,
                $projectRoot
            );


        /*
         * REVIEW -> PACKAGE
         *
         * A supplied asset ID means hand off exactly that one reviewed
         * asset. Do not sweep sibling rows already waiting at Packing.
         *
         * An empty POST remains the department-wide retry/wake action.
         */
        $result =
            $pubAssetId > 0
                ? $manager
                    ->sendToPacking(
                        $pubAssetId
                    )
                : $manager
                    ->processPacking();


        $packed =
            is_array(
                $result['packed']
                ?? null
            )
                ? array_values(
                    $result['packed']
                )
                : [];

        $pending =
            is_array(
                $result['pending']
                ?? null
            )
                ? array_values(
                    $result['pending']
                )
                : [];

        $failed =
            is_array(
                $result['failed']
                ?? null
            )
                ? array_values(
                    $result['failed']
                )
                : [];


        self::sendJson(200, [
            'ok' =>
                true,

            'packed_count' =>
                count(
                    $packed
                ),

            'pending_count' =>
                count(
                    $pending
                ),

            'failed_count' =>
                count(
                    $failed
                ),

            'packed' =>
                $packed,

            'pending' =>
                $pending,

            'failed' =>
                $failed,
        ]);
    }


    /**
     * POST body is optional for the department-wide retry action.
     *
     * Review handoff sends:
     *   { "pub_asset_id": 123 }
     *
     * @return array<string, mixed>
     */
    private static function requestJson(): array
    {
        $raw =
            file_get_contents(
                'php://input'
            ) ?: '';


        if (trim($raw) === '') {
            return [];
        }


        $data =
            json_decode(
                $raw,
                true
            );


        if (!is_array($data)) {
            throw new \RuntimeException(
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
                | JSON_UNESCAPED_UNICODE
            );


        if ($json === false) {
            echo '{"ok":false,"error":"Could not encode PUB response as JSON."}';

            return;
        }


        echo $json;
    }
}
