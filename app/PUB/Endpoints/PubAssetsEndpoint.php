<?php
declare(strict_types=1);

namespace App\PUB\Endpoints;

use App\PUB\Contracts\PubContract;
use App\PUB\Create\Video\PdoVideoJobRepository;
use App\PUB\Repos\PdoPubAssetRepository;
use PDO;
use RuntimeException;
use Throwable;

/**
 * PUB ASSETS ADMIN ENDPOINT
 *
 * Administrative access to durable PUB assets.
 *
 * Owns:
 *   - GET asset grid / editor detail
 *   - PATCH editable asset metadata / authorized ingredients
 *   - DELETE pre-dispatch assets
 *
 * Does NOT own production.
 *
 * REDO is a CREATE order and therefore goes through:
 *
 *   /api/v2/admin/pub/create.php
 *     -> CreateEndpoint
 *     -> CreateManager
 */
final class PubAssetsEndpoint
{
    public static function handle(
        PDO $pdo
    ): void {
        $method =
            strtoupper(
                $_SERVER[
                    'REQUEST_METHOD'
                ]
                ?? 'GET'
            );


        try {
            $repo =
                new PdoPubAssetRepository(
                    $pdo
                );


            if ($method === 'GET') {
                self::handleGet(
                    $repo
                );

                return;
            }


            if ($method === 'PATCH') {
                self::handlePatch(
                    $repo
                );

                return;
            }


            if ($method === 'DELETE') {
                self::handleDelete(
                    $pdo,
                    $repo
                );

                return;
            }


            self::sendJson(
                405,
                [
                    'ok' =>
                        false,

                    'error' =>
                        'GET, PATCH or DELETE only.',
                ]
            );

        } catch (Throwable $e) {
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


    private static function handleGet(
        PdoPubAssetRepository $repo
    ): void {
        /*
         * SINGLE ASSET EDITOR LOAD.
         *
         * Grid rows intentionally stay lean. Video editors fetch
         * only the extra inside ingredients they explicitly expose.
         */
        $requestedPubAssetId =
            (int)(
                $_GET[
                    'pub_asset_id'
                ]
                ?? 0
            );


        if ($requestedPubAssetId > 0) {
            $asset =
                $repo->getById(
                    $requestedPubAssetId
                );


            if ($asset === null) {
                self::sendJson(
                    404,
                    [
                        'ok' =>
                            false,

                        'error' =>
                            'PUB asset not found.',
                    ]
                );

                return;
            }


            $order =
                $repo->getOrder(
                    $requestedPubAssetId
                );


            $ingredients =
                is_array(
                    $order[
                        'ingredients'
                    ]
                    ?? null
                )
                    ? $order[
                        'ingredients'
                    ]
                    : [];


            self::sendJson(
                200,
                [
                    'ok' =>
                        true,

                    'asset' =>
                        $asset,

                    'ingredient_values' =>
                        self::editableIngredientValues(
                            (string)(
                                $asset[
                                    'asset_type'
                                ]
                                ?? ''
                            ),
                            $ingredients
                        ),

                    /*
                     * Metadata fields may also be baked into Creator
                     * ingredients for some asset types (for example a
                     * Pinterest search_title). The editor uses these
                     * bindings to decide whether Save requires REDO.
                     */
                    'ingredient_bindings' =>
                        PubContract::ingredientBindingsForCreatedAssetType(
                            (string)(
                                $asset[
                                    'asset_type'
                                ]
                                ?? ''
                            )
                        ),
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

                'assets' =>
                    $repo->listForAdmin(
                        $channel !== ''
                            ? $channel
                            : null,

                        $assetType !== ''
                            ? $assetType
                            : null
                    ),

                'filters' => [
                    'channels' =>
                        $repo->listChannels(),

                    'asset_types' =>
                        $repo->listAssetTypes(),
                ],
            ]
        );
    }


    private static function handlePatch(
        PdoPubAssetRepository $repo
    ): void {
        $body =
            self::requestJson();


        $pubAssetId =
            (int)(
                $body[
                    'pub_asset_id'
                ]
                ?? 0
            );


        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }


        $asset =
            $repo->getById(
                $pubAssetId
            );


        if ($asset === null) {
            throw new RuntimeException(
                'PUB asset not found.'
            );
        }


        $changes = [];


        if (
            array_key_exists(
                'search_title',
                $body
            )
        ) {
            $changes[
                'search_title'
            ] =
                (string)$body[
                    'search_title'
                ];
        }


        if (
            array_key_exists(
                'description',
                $body
            )
        ) {
            $changes[
                'description'
            ] =
                (string)$body[
                    'description'
                ];
        }


        if (
            array_key_exists(
                'ingredient_changes',
                $body
            )
        ) {
            $requestedIngredientChanges =
                is_array(
                    $body[
                        'ingredient_changes'
                    ]
                )
                    ? $body[
                        'ingredient_changes'
                    ]
                    : [];


            $changes[
                'ingredient_changes'
            ] =
                self::sanitizeEditableIngredientChanges(
                    (string)(
                        $asset[
                            'asset_type'
                        ]
                        ?? ''
                    ),
                    $requestedIngredientChanges
                );
        }


        if ($changes === []) {
            throw new RuntimeException(
                'No editable order fields supplied.'
            );
        }


        $updated =
            $repo->updateOrder(
                $pubAssetId,
                $changes
            );


        self::sendJson(
            200,
            [
                'ok' =>
                    true,

                'metadata' =>
                    $updated,

                'asset' =>
                    $repo->getById(
                        $pubAssetId
                    ),

                'redo_required' =>
                    !empty(
                        $updated[
                            'ingredients_changed'
                        ]
                    ),

                'ingredient_values' =>
                    self::editableIngredientValues(
                        (string)(
                            $asset[
                                'asset_type'
                            ]
                            ?? ''
                        ),

                        is_array(
                            $updated[
                                'ingredients'
                            ]
                            ?? null
                        )
                            ? $updated[
                                'ingredients'
                            ]
                            : []
                    ),
            ]
        );
    }


    private static function handleDelete(
        PDO $pdo,
        PdoPubAssetRepository $repo
    ): void {
        $body =
            self::requestJson();


        $pubAssetId =
            (int)(
                $body[
                    'pub_asset_id'
                ]
                ?? 0
            );


        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }


        $asset =
            $repo->getById(
                $pubAssetId
            );


        if ($asset === null) {
            self::sendJson(
                404,
                [
                    'ok' =>
                        false,

                    'error' =>
                        'PUB asset not found.',
                ]
            );

            return;
        }


        $stage =
            strtolower(
                trim(
                    (string)(
                        $asset[
                            'pipeline_stage'
                        ]
                        ?? ''
                    )
                )
            );


        if (
            in_array(
                $stage,
                [
                    'dispatched',
                    'published',
                ],
                true
            )
        ) {
            self::sendJson(
                409,
                [
                    'ok' =>
                        false,

                    'error' =>
                        'Dispatched or published assets cannot be deleted.',
                ]
            );

            return;
        }


        /*
         * Cancel any outstanding asynchronous production before
         * removing the durable asset row.
         */
        $assetType =
            strtolower(
                trim(
                    (string)(
                        $asset[
                            'asset_type'
                        ]
                        ?? ''
                    )
                )
            );


        if (
            in_array(
                $assetType,
                [
                    'pin_before_after_video',
                    'youtube_video',
                ],
                true
            )
        ) {
            $videoJobs =
                new PdoVideoJobRepository(
                    $pdo
                );


            $videoJobs
                ->failActiveJobsForAsset(
                    $pubAssetId,
                    'PUB asset was deleted before video rendering completed.'
                );
        }


        $filePath =
            trim(
                (string)(
                    $asset[
                        'file_path'
                    ]
                    ?? ''
                )
            );


        if (
            $filePath !== ''
            &&
            is_file(
                $filePath
            )
        ) {
            if (
                !unlink(
                    $filePath
                )
            ) {
                throw new RuntimeException(
                    'Could not delete physical PUB asset file.'
                );
            }
        }


        $repo->deleteUnsent(
            $pubAssetId
        );


        self::sendJson(
            200,
            [
                'ok' =>
                    true,

                'deleted_pub_asset_id' =>
                    $pubAssetId,
            ]
        );
    }


    /*
     * ========================================================
     * ASSET EDITOR INSIDE-INGREDIENT CONTRACT
     * ========================================================
     */

    private static function editableIngredientPaths(
        string $assetType
    ): array {
        $assetType =
            strtolower(
                trim(
                    $assetType
                )
            );


        return match (
            $assetType
        ) {
            'pin_before_after_video' => [
                'end_slide_text',
            ],

            'youtube_video' => [
                'music',
            ],

            default => [],
        };
    }


    private static function editableIngredientValues(
        string $assetType,
        array $ingredients
    ): array {
        $values = [];


        foreach (
            self::editableIngredientPaths(
                $assetType
            )
            as $path
        ) {
            if (
                array_key_exists(
                    $path,
                    $ingredients
                )
            ) {
                $values[
                    $path
                ] =
                    $ingredients[
                        $path
                    ];
            }
        }


        return $values;
    }


    private static function sanitizeEditableIngredientChanges(
        string $assetType,
        array $requested
    ): array {
        $allowed =
            array_flip(
                self::editableIngredientPaths(
                    $assetType
                )
            );


        $clean = [];


        foreach (
            $requested
            as $path => $value
        ) {
            $path =
                trim(
                    (string)$path
                );


            if (
                $path === ''
                ||
                !array_key_exists(
                    $path,
                    $allowed
                )
            ) {
                throw new RuntimeException(
                    "Ingredient '{$path}' is not editable for asset type '{$assetType}'."
                );
            }


            $clean[
                $path
            ] =
                $value;
        }


        return $clean;
    }


    /**
     * @return array<string, mixed>
     */
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


        $body =
            json_decode(
                $raw,
                true
            );


        return is_array(
            $body
        )
            ? $body
            : [];
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