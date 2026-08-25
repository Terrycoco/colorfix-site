<?php
declare(strict_types=1);

header(
    'Content-Type: application/json; charset=UTF-8'
);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';

use App\PUB\Create\CreateManager;
use App\PUB\Create\Pinterest\BeforeAfterVideoCreator;
use App\PUB\Create\Pinterest\CompositeCreator;
use App\PUB\Create\Pinterest\IdeaCreator;
use App\PUB\Create\Pinterest\PaletteCreator;
use App\PUB\Create\Pinterest\Support\PinterestCreatorTools;
use App\PUB\Create\Video\PdoVideoJobRepository;
use App\PUB\Create\Video\VideoWorkerHealthService;
use App\PUB\Repos\PdoPubAssetRepository;


/*
 * ========================================================
 * ASSET EDITOR INSIDE-INGREDIENT CONTRACT
 * ========================================================
 *
 * These are NOT pub_assets metadata fields.
 *
 * Only values explicitly exposed by an asset editor may cross
 * the admin endpoint as direct ingredient changes.
 *
 * YouTube can add its own editable production-copy fields here
 * when that Creator is migrated.
 */
function editableIngredientPaths(
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

        'youtube_video' => [],

        default => [],
    };
}


function editableIngredientValues(
    string $assetType,
    array $ingredients
): array {
    $values = [];


    foreach (
        editableIngredientPaths(
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


function sanitizeEditableIngredientChanges(
    string $assetType,
    array $requested
): array {
    $allowed =
        array_flip(
            editableIngredientPaths(
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



try {
    $repo =
        new PdoPubAssetRepository(
            $pdo
        );

    $method =
        strtoupper(
            $_SERVER[
                'REQUEST_METHOD'
            ]
            ?? 'GET'
        );


    /*
     * ========================================================
     * GET — ASSET GRID
     * ========================================================
     */
    if ($method === 'GET') {
        /*
         * SINGLE ASSET EDITOR LOAD.
         *
         * Grid rows intentionally stay lean. Video editors fetch
         * only the extra inside ingredients they actually expose.
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
                http_response_code(
                    404
                );

                echo json_encode([
                    'ok' =>
                        false,

                    'error' =>
                        'PUB asset not found.',
                ]);

                exit;
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


            echo json_encode([
                'ok' =>
                    true,

                'asset' =>
                    $asset,

                'ingredient_values' =>
                    editableIngredientValues(
                        (string)(
                            $asset[
                                'asset_type'
                            ]
                            ?? ''
                        ),
                        $ingredients
                    ),
            ]);

            exit;
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

        echo json_encode([
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
        ]);

        exit;
    }


    /*
     * ========================================================
     * PATCH — SAVE ASSET METADATA
     * ========================================================
     *
     * This does NOT recreate anything.
     *
     * search_title and description belong to pub_assets.
     *
     * Selected editor-only production copy may also be changed
     * inside pub_asset_orders.ingredients without becoming
     * pub_assets metadata.
     * ========================================================
     */
    if ($method === 'PATCH') {
        $body =
            json_decode(
                file_get_contents(
                    'php://input'
                ),
                true
            );

        if (!is_array($body)) {
            $body = [];
        }

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
                sanitizeEditableIngredientChanges(
                    (string)(
                        $asset[
                            'asset_type'
                        ]
                        ?? ''
                    ),
                    $requestedIngredientChanges
                );
        }


        if (!$changes) {
            throw new RuntimeException(
                'No editable order fields supplied.'
            );
        }


        /*
         * SAVE EDITABLE ASSET METADATA AND ANY AUTHORIZED
         * INSIDE-INGREDIENT CHANGES IN ONE TRANSACTION.
         */
        $updated =
            $repo->updateOrder(
                $pubAssetId,
                $changes
            );


        echo json_encode([
            'ok' =>
                true,

            'metadata' =>
                $updated,

            /*
             * Return fresh asset data so
             * the grid can update immediately.
             */
            'asset' =>
                $repo->getById(
                    $pubAssetId
                ),

            'ingredient_values' =>
                editableIngredientValues(
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
        ]);

        exit;
    }


    /*
     * ========================================================
     * POST — REDO ASSET
     * ========================================================
     *
     * Caller sends ONLY:
     *
     *   pub_asset_id
     *
     * Copy Editing has already saved any
     * changes into the filed order.
     *
     * Create Manager fetches that order itself.
     * ========================================================
     */
    if ($method === 'POST') {
        $body =
            json_decode(
                file_get_contents(
                    'php://input'
                ),
                true
            );

        if (!is_array($body)) {
            $body = [];
        }

        $action =
            strtolower(
                trim(
                    (string)(
                        $body[
                            'action'
                        ]
                        ?? ''
                    )
                )
            );

        if (
            $action !==
            'recreate'
        ) {
            throw new RuntimeException(
                'Unsupported POST action.'
            );
        }

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


        /*
         * SAME CREATE INFRASTRUCTURE USED
         * BY create-assets.php.
         */
        $projectRoot =
            dirname(
                __DIR__,
                4
            );

        $logoPath =
            $projectRoot
            . '/brand/'
            . 'colorfix-pin-logo-compact-right-aligned-transparent.png';

        $tools =
            new PinterestCreatorTools();


        /*
         * SPECIALIST CREATORS.
         *
         * Create Manager decides which one
         * receives the numbered order.
         */
        $compositeCreator =
            new CompositeCreator(
                $tools,
                $projectRoot,
                $logoPath
            );

        $forwardedProto =
            strtolower(
                trim(
                    (string)(
                        $_SERVER[
                            'HTTP_X_FORWARDED_PROTO'
                        ]
                        ?? ''
                    )
                )
            );

        if (
            !in_array(
                $forwardedProto,
                [
                    'http',
                    'https',
                ],
                true
            )
        ) {
            $forwardedProto =
                !empty(
                    $_SERVER[
                        'HTTPS'
                    ]
                )
                && strtolower(
                    (string)$_SERVER[
                        'HTTPS'
                    ]
                ) !== 'off'
                    ? 'https'
                    : 'http';
        }

        $host =
            trim(
                (string)(
                    $_SERVER[
                        'HTTP_HOST'
                    ]
                    ?? ''
                )
            );

        if ($host === '') {
            throw new RuntimeException(
                'CREATE could not determine the public host.'
            );
        }

        $publicBaseUrl =
            $forwardedProto
            . '://'
            . $host;

        $videoJobs =
            new PdoVideoJobRepository(
                $pdo
            );

        $videoWorkerHealth =
            new VideoWorkerHealthService(
                $projectRoot
            );

        $beforeAfterVideoCreator =
            new BeforeAfterVideoCreator(
                $repo,
                $videoJobs,
                $videoWorkerHealth,
                $projectRoot,
                $publicBaseUrl
            );

        $ideaCreator =
            new IdeaCreator(
                $tools,
                $projectRoot,
                $logoPath
            );

        $paletteCreator =
            new PaletteCreator(
                $tools,
                $projectRoot,
                $logoPath
            );


        /*
         * CREATE DEPARTMENT HEAD.
         */
        $manager =
            new CreateManager(
                $repo,
                $compositeCreator,
                $beforeAfterVideoCreator,
                $ideaCreator,
                $paletteCreator,
                videoJobs:
                    $videoJobs
            );


        /*
         * REDO #253.
         *
         * No box supplied here.
         * Create Manager fetches the current
         * filed order itself.
         */
        $result =
            $manager->recreate(
                $pubAssetId
            );


        /*
         * EXPECTED PUBCOM BLOCK.
         *
         * This is an operational stop, not a system exception.
         * Do not send it through the centralized error logger.
         *
         * Return a conflict response so the existing frontend
         * does not mistake a blocked REDO for a successful one.
         */
        if (
            ($result['status'] ?? '') ===
            'blocked'
        ) {
            http_response_code(
                409
            );

            echo json_encode([
                'ok' =>
                    false,

                'pub_asset_id' =>
                    $pubAssetId,

                'error' =>
                    (string)(
                        $result['error']
                        ?? 'CREATE redo was blocked.'
                    ),

                'result' =>
                    $result,

                'pubcom' =>
                    $result['pubcom']
                    ?? [],
            ]);

            exit;
        }


        echo json_encode([
            'ok' =>
                true,

            'pub_asset_id' =>
                $pubAssetId,

            'result' =>
                $result,

            /*
             * Return the refreshed durable
             * record to Copy Editing.
             */
            'asset' =>
                $repo->getById(
                    $pubAssetId
                ),

            /*
             * Frontend can use this to bust
             * the browser image cache.
             */
            'preview_version' =>
                time(),
        ]);

        exit;
    }


    /*
     * ========================================================
     * DELETE — PRE-DISPATCH ASSET
     * ========================================================
     */
    if ($method === 'DELETE') {
        $body =
            json_decode(
                file_get_contents(
                    'php://input'
                ),
                true
            );

        if (!is_array($body)) {
            $body = [];
        }

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


        /*
         * FETCH ASSET.
         */
        $asset =
            $repo->getById(
                $pubAssetId
            );

        if ($asset === null) {
            http_response_code(
                404
            );

            echo json_encode([
                'ok' =>
                    false,

                'error' =>
                    'PUB asset not found.',
            ]);

            exit;
        }


        /*
         * HARD LIFECYCLE RULE.
         */
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
            http_response_code(
                409
            );

            echo json_encode([
                'ok' =>
                    false,

                'error' =>
                    'Dispatched or published assets cannot be deleted.',
            ]);

            exit;
        }


        /*
         * If this asset is still waiting on asynchronous video work,
         * stop those jobs before deleting the asset row.
         */
        if (
            strtolower(
                trim(
                    (string)(
                        $asset[
                            'asset_type'
                        ]
                        ?? ''
                    )
                )
            ) === 'pin_before_after_video'
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


        /*
         * DELETE PHYSICAL FILE.
         *
         * file_path is exact.
         */
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


        /*
         * Repo removes:
         *
         *   pub_asset_orders
         *   pub_assets
         */
        $repo->deleteUnsent(
            $pubAssetId
        );


        echo json_encode([
            'ok' =>
                true,

            'deleted_pub_asset_id' =>
                $pubAssetId,
        ]);

        exit;
    }


    /*
     * ========================================================
     * UNSUPPORTED METHOD
     * ========================================================
     */
    http_response_code(
        405
    );

    echo json_encode([
        'ok' =>
            false,

        'error' =>
            'GET, PATCH, POST or DELETE only.',
    ]);


} catch (Throwable $e) {
    http_response_code(
        500
    );

    echo json_encode([
        'ok' =>
            false,

        'error' =>
            $e->getMessage(),
    ]);
}