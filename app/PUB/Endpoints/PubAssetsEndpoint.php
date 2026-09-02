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


        /*
         * Never delete an asset while Dispatch is actively working on it.
         * The historical override exists only after shipping has finished.
         */
        if ($stage === 'shipping') {
            self::sendJson(
                409,
                [
                    'ok' =>
                        false,

                    'error' =>
                        'This asset is currently shipping and cannot be deleted.',
                ]
            );

            return;
        }


        $historicalStage =
            in_array(
                $stage,
                [
                    'shipped',
                    'dispatched',
                    'published',
                ],
                true
            );


        $forceDeleteShipped =
            (
                $body[
                    'force_delete_shipped'
                ]
                ?? false
            ) === true;


        /*
         * Historical PUB records remain protected by default.
         *
         * The admin UI exposes the override only through the explicit
         * "Delete Forever" checkbox flow. Direct API callers must send
         * the same boolean deliberately.
         */
        if (
            $historicalStage
            && !$forceDeleteShipped
        ) {
            self::sendJson(
                409,
                [
                    'ok' =>
                        false,

                    'code' =>
                        'historical_delete_requires_authorization',

                    'error' =>
                        'This asset has already been shipped. Check the permanent-delete authorization before deleting it.',
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
                    $historicalStage
                        ? 'Historical PUB asset was permanently deleted by an authorized admin.'
                        : 'PUB asset was deleted before video rendering completed.'
                );
        }


        /*
         * Remove the physical creative outputs owned by this PUB asset.
         *
         * YouTube videos have both an MP4 and a companion thumbnail JPEG.
         * Do not leave the thumbnail orphaned when a test shipment is
         * intentionally erased from PUB.
         */
        $physicalFiles = [
            trim(
                (string)(
                    $asset[
                        'file_path'
                    ]
                    ?? ''
                )
            ),

            trim(
                (string)(
                    $asset[
                        'thumbnail_file_path'
                    ]
                    ?? ''
                )
            ),
        ];


        foreach (
            array_unique(
                array_filter(
                    $physicalFiles,
                    static fn(string $path): bool =>
                        $path !== ''
                )
            )
            as $physicalFile
        ) {
            if (
                is_file(
                    $physicalFile
                )
                && !unlink(
                    $physicalFile
                )
            ) {
                throw new RuntimeException(
                    'Could not delete physical PUB asset file.'
                );
            }
        }


        $repo->deleteUnsent(
            $pubAssetId,
            $historicalStage
                && $forceDeleteShipped
        );


        self::sendJson(
            200,
            [
                'ok' =>
                    true,

                'deleted_pub_asset_id' =>
                    $pubAssetId,

                'historical_delete' =>
                    $historicalStage,
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
                'cover.text_color',
            ],

            default => [],
        };
    }


    /**
     * Return only editor-authorized Creator ingredients.
     *
     * Dotted paths remain nested in the response so React receives:
     *
     *   ingredient_values.cover.text_color
     *
     * rather than a synthetic flat key.
     */
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
                !self::nestedPathExists(
                    $ingredients,
                    $path
                )
            ) {
                continue;
            }


            self::setNestedValue(
                $values,
                $path,
                self::getNestedValue(
                    $ingredients,
                    $path
                )
            );
        }


        return $values;
    }


    /**
     * Accept the editor's natural nested JSON while enforcing the exact
     * authorized ingredient paths.
     *
     * Example request:
     *
     *   ingredient_changes: {
     *     cover: {
     *       text_color: "#168B8A"
     *     }
     *   }
     *
     * becomes the repository patch:
     *
     *   [
     *     "cover.text_color" => "#168B8A"
     *   ]
     *
     * This is intentionally NOT the same as making "cover" editable:
     * allowing the whole cover object could overwrite its durable
     * file_path/image_url/title values.
     */
    private static function sanitizeEditableIngredientChanges(
        string $assetType,
        array $requested
    ): array {
        $allowedPaths =
            self::editableIngredientPaths(
                $assetType
            );

        $allowed =
            array_flip(
                $allowedPaths
            );


        $clean = [];


        self::collectEditableIngredientChanges(
            requested:
                $requested,

            prefix:
                '',

            allowed:
                $allowed,

            clean:
                $clean,

            assetType:
                strtolower(
                    trim(
                        $assetType
                    )
                )
        );


        return $clean;
    }


    /**
     * Walk nested editor JSON until an authorized path is reached.
     *
     * If a whole-object path itself is authorized (currently "music"),
     * preserve that object as one value. Otherwise recurse so a narrow
     * authorization such as "cover.text_color" cannot accidentally grant
     * edit access to the rest of cover.
     *
     * @param array<string,int> $allowed
     * @param array<string,mixed> $clean
     */
    private static function collectEditableIngredientChanges(
        array $requested,
        string $prefix,
        array $allowed,
        array &$clean,
        string $assetType
    ): void {
        foreach (
            $requested
            as $key => $value
        ) {
            $key =
                trim(
                    (string)$key
                );


            if ($key === '') {
                throw new RuntimeException(
                    "Ingredient '{$key}' is not editable for asset type '{$assetType}'."
                );
            }


            $path =
                $prefix === ''
                    ? $key
                    : $prefix . '.' . $key;


            /*
             * Exact authorization wins. This is what keeps the existing
             * "music" object behavior unchanged.
             */
            if (
                array_key_exists(
                    $path,
                    $allowed
                )
            ) {
                $clean[
                    $path
                ] =
                    $value;

                continue;
            }


            /*
             * Recurse only if this path is a parent of at least one
             * explicitly authorized nested ingredient.
             */
            $isAuthorizedParent =
                false;

            $childPrefix =
                $path . '.';


            foreach (
                $allowed
                as $allowedPath => $_
            ) {
                if (
                    str_starts_with(
                        $allowedPath,
                        $childPrefix
                    )
                ) {
                    $isAuthorizedParent =
                        true;

                    break;
                }
            }


            if (
                $isAuthorizedParent
                && is_array(
                    $value
                )
            ) {
                self::collectEditableIngredientChanges(
                    requested:
                        $value,

                    prefix:
                        $path,

                    allowed:
                        $allowed,

                    clean:
                        $clean,

                    assetType:
                        $assetType
                );

                continue;
            }


            throw new RuntimeException(
                "Ingredient '{$path}' is not editable for asset type '{$assetType}'."
            );
        }
    }


    private static function nestedPathExists(
        array $source,
        string $path
    ): bool {
        $parts =
            array_values(
                array_filter(
                    explode(
                        '.',
                        $path
                    ),
                    static fn(string $part): bool =>
                        $part !== ''
                )
            );


        if ($parts === []) {
            return false;
        }


        $cursor =
            $source;


        foreach (
            $parts
            as $part
        ) {
            if (
                !is_array(
                    $cursor
                )
                || !array_key_exists(
                    $part,
                    $cursor
                )
            ) {
                return false;
            }


            $cursor =
                $cursor[
                    $part
                ];
        }


        return true;
    }


    private static function getNestedValue(
        array $source,
        string $path
    ): mixed {
        $cursor =
            $source;


        foreach (
            explode(
                '.',
                $path
            )
            as $part
        ) {
            if (
                !is_array(
                    $cursor
                )
                || !array_key_exists(
                    $part,
                    $cursor
                )
            ) {
                return null;
            }


            $cursor =
                $cursor[
                    $part
                ];
        }


        return $cursor;
    }


    private static function setNestedValue(
        array &$target,
        string $path,
        mixed $value
    ): void {
        $parts =
            array_values(
                array_filter(
                    explode(
                        '.',
                        $path
                    ),
                    static fn(string $part): bool =>
                        $part !== ''
                )
            );


        if ($parts === []) {
            return;
        }


        $cursor =
            &$target;


        foreach (
            $parts
            as $index => $part
        ) {
            $isLast =
                $index ===
                count(
                    $parts
                ) - 1;


            if ($isLast) {
                $cursor[
                    $part
                ] =
                    $value;

                return;
            }


            if (
                !isset(
                    $cursor[
                        $part
                    ]
                )
                || !is_array(
                    $cursor[
                        $part
                    ]
                )
            ) {
                $cursor[
                    $part
                ] = [];
            }


            $cursor =
                &$cursor[
                    $part
                ];
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
