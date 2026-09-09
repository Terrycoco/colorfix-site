<?php
declare(strict_types=1);

namespace App\PUB\Repos;

use PDO;
use App\PUB\Contracts\PubContract;
use RuntimeException;
use Throwable;


/**
 * PUB ASSET REPOSITORY
 *
 * Owns persistence for:
 *
 *   pub_assets
 *   pub_asset_orders
 *
 * ASSET
 *   The physical thing PUB created.
 *
 * ORDER
 *   The current in-house instructions for making
 *   that specific asset.
 *
 * The order exists only while the asset remains
 * in-house. Once successfully dispatched, the
 * order may be discarded.
 *
 * Every created asset has a primary physical location:
 *
 *   file_path
 *     Full physical server path.
 *
 *   url
 *     Browser-facing URL.
 *
 * Video products may also have one durable companion thumbnail:
 *
 *   thumbnail_file_path
 *     Full physical server path for the companion JPEG.
 *
 *   thumbnail_url
 *     Browser-facing URL for that companion JPEG.
 */
final class PdoPubAssetRepository
{
    public function __construct(
        private PDO $pdo
    ) {}


    /*
     * ========================================================
     * ASSET — CREATE COMPLETED ROW
     * ========================================================
     */

    /**
     * Register an already-created physical asset.
     *
     * Retained for callers that create before inserting.
     *
     * Normal PUB CREATE flow now uses:
     *
     *   reserveWithOrder()
     *   markCreated()
     */
    public function create(
        array $asset
    ): int {
        $this->validateCreatePayload(
            $asset
        );

        $sql = <<<SQL
            INSERT INTO pub_assets (
                pub_run_id,

                channel,
                asset_type,
                creator_key,

                source_type,
                source_id,
                sort_order,

                file_path,
                url,
                thumbnail_file_path,
                thumbnail_url,
                mime_type,

                width,
                height,
                duration_ms,

                file_size_bytes,
                checksum,

                search_title,
                description,
                pingback,

                pipeline_stage,
                local_file_status
            ) VALUES (
                :pub_run_id,

                :channel,
                :asset_type,
                :creator_key,

                :source_type,
                :source_id,
                :sort_order,

                :file_path,
                :url,
                :thumbnail_file_path,
                :thumbnail_url,
                :mime_type,

                :width,
                :height,
                :duration_ms,

                :file_size_bytes,
                :checksum,

                :search_title,
                :description,
                :pingback,

                'created',
                'present'
            )
            SQL;

        $stmt =
            $this->pdo->prepare(
                $sql
            );

        $stmt->execute([
            'pub_run_id' =>
                (int)$asset['pub_run_id'],

            'channel' =>
                trim(
                    (string)$asset['channel']
                ),

            'asset_type' =>
                trim(
                    (string)$asset['asset_type']
                ),

            'creator_key' =>
                trim(
                    (string)$asset['creator_key']
                ),

            'source_type' =>
                trim(
                    (string)$asset['source_type']
                ),

            'source_id' =>
                (int)$asset['source_id'],

            'sort_order' =>
                isset(
                    $asset['sort_order']
                )
                && $asset['sort_order'] !== null
                    ? (int)$asset['sort_order']
                    : null,

            'file_path' =>
                trim(
                    (string)$asset['file_path']
                ),

            'url' =>
                $this->nullableString(
                    $asset['url']
                    ?? null
                ),

            'thumbnail_file_path' =>
                $this->nullableString(
                    $asset['thumbnail_file_path']
                    ?? null
                ),

            'thumbnail_url' =>
                $this->nullableString(
                    $asset['thumbnail_url']
                    ?? null
                ),

            'mime_type' =>
                trim(
                    (string)$asset['mime_type']
                ),

            'width' =>
                isset(
                    $asset['width']
                )
                && $asset['width'] !== null
                    ? (int)$asset['width']
                    : null,

            'height' =>
                isset(
                    $asset['height']
                )
                && $asset['height'] !== null
                    ? (int)$asset['height']
                    : null,

            'duration_ms' =>
                isset(
                    $asset['duration_ms']
                )
                && $asset['duration_ms'] !== null
                    ? (int)$asset['duration_ms']
                    : null,

            'file_size_bytes' =>
                isset(
                    $asset['file_size_bytes']
                )
                && $asset['file_size_bytes'] !== null
                    ? (int)$asset['file_size_bytes']
                    : null,

            'checksum' =>
                $this->nullableString(
                    $asset['checksum']
                    ?? null
                ),

            'search_title' =>
                $this->nullableString(
                    $asset['search_title']
                    ?? null
                ),

            'description' =>
                $this->nullableString(
                    $asset['description']
                    ?? null
                ),

            'pingback' =>
                $this->nullableString(
                    $asset['pingback']
                    ?? null
                ),
        ]);

        $id =
            (int)$this->pdo
                ->lastInsertId();

        if ($id <= 0) {
            throw new RuntimeException(
                'PUB asset was inserted but no asset ID was returned.'
            );
        }

        return $id;
    }


    private function validateCreatePayload(
        array $asset
    ): void {
        foreach (
            [
                'channel',
                'asset_type',
                'creator_key',
                'source_type',
                'file_path',
                'mime_type',
            ]
            as $field
        ) {
            if (
                trim(
                    (string)(
                        $asset[$field]
                        ?? ''
                    )
                ) === ''
            ) {
                throw new RuntimeException(
                    "PUB asset create requires {$field}."
                );
            }
        }

        if (
            (int)(
                $asset['pub_run_id']
                ?? 0
            ) <= 0
        ) {
            throw new RuntimeException(
                'PUB asset create requires a valid pub_run_id.'
            );
        }

        if (
            (int)(
                $asset['source_id']
                ?? 0
            ) <= 0
        ) {
            throw new RuntimeException(
                'PUB asset create requires a valid source_id.'
            );
        }
    }


    /*
     * ========================================================
     * ASSET — RESERVATION
     * ========================================================
     */

    /**
     * Reserve only the permanent PUB asset ID.
     *
     * CREATE Coordinator will normally use
     * reserveWithOrder() instead.
     */
    public function reserve(
        array $asset
    ): int {
        foreach (
            [
                'channel',
                'asset_type',
                'creator_key',
                'source_type',
            ]
            as $field
        ) {
            if (
                trim(
                    (string)(
                        $asset[$field]
                        ?? ''
                    )
                ) === ''
            ) {
                throw new RuntimeException(
                    "PUB asset reservation requires {$field}."
                );
            }
        }

        if (
            (int)(
                $asset['pub_run_id']
                ?? 0
            ) <= 0
        ) {
            throw new RuntimeException(
                'PUB asset reservation requires a valid pub_run_id.'
            );
        }

        if (
            (int)(
                $asset['source_id']
                ?? 0
            ) <= 0
        ) {
            throw new RuntimeException(
                'PUB asset reservation requires a valid source_id.'
            );
        }

        $stmt =
            $this->pdo->prepare(
                <<<SQL
                INSERT INTO pub_assets (
                    pub_run_id,

                    channel,
                    asset_type,
                    creator_key,

                    source_type,
                    source_id,
                    sort_order,

                    search_title,
                    description,
                    pingback,

                    file_path,
                    url,
                    mime_type,

                    pipeline_stage,
                    local_file_status
                ) VALUES (
                    :pub_run_id,

                    :channel,
                    :asset_type,
                    :creator_key,

                    :source_type,
                    :source_id,
                    :sort_order,

                    :search_title,
                    :description,
                    :pingback,

                    '',
                    NULL,
                    'application/octet-stream',

                    'creating',
                    'pending'
                )
                SQL
            );

        $stmt->execute([
            'pub_run_id' =>
                (int)$asset['pub_run_id'],

            'channel' =>
                trim(
                    (string)$asset['channel']
                ),

            'asset_type' =>
                trim(
                    (string)$asset['asset_type']
                ),

            'creator_key' =>
                trim(
                    (string)$asset['creator_key']
                ),

            'source_type' =>
                trim(
                    (string)$asset['source_type']
                ),

            'source_id' =>
                (int)$asset['source_id'],

            'sort_order' =>
                isset(
                    $asset['sort_order']
                )
                && $asset['sort_order'] !== null
                    ? (int)$asset['sort_order']
                    : null,

            'search_title' =>
                $this->nullableString(
                    $asset['search_title']
                    ?? null
                ),

            'description' =>
                $this->nullableString(
                    $asset['description']
                    ?? null
                ),

            'pingback' =>
                $this->nullableString(
                    $asset['pingback']
                    ?? null
                ),
        ]);

        $id =
            (int)$this->pdo
                ->lastInsertId();

        if ($id <= 0) {
            throw new RuntimeException(
                'PUB asset reservation failed.'
            );
        }

        return $id;
    }


    /**
     * NEW ORDER.
     *
     * Reserve the permanent asset ID and file
     * an exact snapshot of the CREATE ingredients.
     *
     * These operations are atomic.
     */
    public function reserveWithOrder(
        array $asset,
        array $ingredients
    ): int {
        $this->pdo
            ->beginTransaction();

        try {
            $pubAssetId =
                $this->reserve(
                    $asset
                );

            $this->saveOrder(
                $pubAssetId,

                trim(
                    (string)(
                        $asset[
                            'creator_key'
                        ]
                        ?? ''
                    )
                ),

                $ingredients
            );

            $this->pdo
                ->commit();

            return $pubAssetId;

        } catch (Throwable $e) {
            if (
                $this->pdo
                    ->inTransaction()
            ) {
                $this->pdo
                    ->rollBack();
            }

            throw $e;
        }
    }


    /**
     * REPLACEMENT NEW ORDER.
     *
     * Reuse an existing in-house pub_asset_id for a newly analyzed
     * version of the same logical asset.
     *
     * The current physical output remains in place until CREATE
     * successfully produces its replacement.
     *
     * ANALYZE has already prefilled predecessor outside copy into the
     * workbench. The operator may edit it there, so the final incoming
     * search_title / description are authoritative and are saved here.
     *
     * Fresh Analyze values replace routing/order data such as:
     *
     *   pub_run_id
     *   sort_order
     *   pingback
     *   ingredients
     *
     * Historical shipped rows are never eligible for this operation.
     */
    public function replaceWithOrder(
        int $pubAssetId,
        array $asset,
        array $ingredients
    ): int {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Replacement requires a valid pub_asset_id.'
            );
        }


        foreach (
            [
                'channel',
                'asset_type',
                'creator_key',
                'source_type',
            ]
            as $field
        ) {
            if (
                trim(
                    (string)(
                        $asset[$field]
                        ?? ''
                    )
                ) === ''
            ) {
                throw new RuntimeException(
                    "PUB asset replacement requires {$field}."
                );
            }
        }


        if (
            (int)(
                $asset['pub_run_id']
                ?? 0
            ) <= 0
        ) {
            throw new RuntimeException(
                'PUB asset replacement requires a valid pub_run_id.'
            );
        }


        if (
            (int)(
                $asset['source_id']
                ?? 0
            ) <= 0
        ) {
            throw new RuntimeException(
                'PUB asset replacement requires a valid source_id.'
            );
        }


        $this->pdo
            ->beginTransaction();


        try {
            /*
             * The workbench Box is authoritative for outside copy.
             * ANALYZE may have inherited these values from a predecessor,
             * but the operator is free to edit them before CREATE.
             */
            $stmt =
                $this->pdo->prepare(
                    <<<SQL
                    UPDATE pub_assets
                    SET
                        pub_run_id =
                            :pub_run_id,

                        channel =
                            :channel,

                        asset_type =
                            :asset_type,

                        creator_key =
                            :creator_key,

                        source_type =
                            :source_type,

                        source_id =
                            :source_id,

                        sort_order =
                            :sort_order,

                        search_title =
                            :search_title,

                        description =
                            :description,

                        pingback =
                            :pingback,

                        pipeline_stage =
                            'creating',

                        approved =
                            0,

                        `package` =
                            NULL,

                        shipping_receipt =
                            NULL,

                        production_signature =
                            NULL,

                        stage_note =
                            NULL,

                        dispatched_at =
                            NULL,

                        error_stage =
                            NULL,

                        error_code =
                            NULL,

                        error_message =
                            NULL,

                        errored_at =
                            NULL

                    WHERE pub_asset_id =
                        :pub_asset_id

                      AND pipeline_stage NOT IN (
                          'shipping',
                          'shipped',
                          'dispatched',
                          'published'
                      )
                    SQL
                );


            $stmt->execute([
                'pub_run_id' =>
                    (int)$asset['pub_run_id'],

                'channel' =>
                    trim(
                        (string)$asset['channel']
                    ),

                'asset_type' =>
                    trim(
                        (string)$asset['asset_type']
                    ),

                'creator_key' =>
                    trim(
                        (string)$asset['creator_key']
                    ),

                'source_type' =>
                    trim(
                        (string)$asset['source_type']
                    ),

                'source_id' =>
                    (int)$asset['source_id'],

                'sort_order' =>
                    isset(
                        $asset['sort_order']
                    )
                    && $asset['sort_order'] !== null
                        ? (int)$asset['sort_order']
                        : null,

                'search_title' =>
                    $this->nullableString(
                        $asset['search_title']
                        ?? null
                    ),

                'description' =>
                    $this->nullableString(
                        $asset['description']
                        ?? null
                    ),

                'pingback' =>
                    $this->nullableString(
                        $asset['pingback']
                        ?? null
                    ),

                'pub_asset_id' =>
                    $pubAssetId,
            ]);


            if ($stmt->rowCount() !== 1) {
                /*
                 * MySQL reports changed rows for UPDATE by default.
                 * A legitimate idempotent retry can therefore report 0
                 * when this exact replacement metadata has already been
                 * prepared and the row is already at creating.
                 *
                 * Re-read the durable row instead of treating rowCount()
                 * as the authority. If the row already reflects the exact
                 * incoming replacement metadata, continue and refresh the
                 * filed order below. Any lifecycle/identity mismatch still
                 * fails hard.
                 */
                $current =
                    $this->getById(
                        $pubAssetId
                    );


                $currentStage =
                    strtolower(
                        trim(
                            (string)(
                                $current[
                                    'pipeline_stage'
                                ]
                                ?? ''
                            )
                        )
                    );


                if (
                    $current === null
                    || $currentStage !== 'creating'
                    || !$this->replacementMetadataMatches(
                        $current,
                        $asset
                    )
                ) {
                    throw new RuntimeException(
                        "PUB asset #{$pubAssetId} could not be prepared for replacement."
                    );
                }
            }


            $this->saveOrder(
                $pubAssetId,

                trim(
                    (string)$asset[
                        'creator_key'
                    ]
                ),

                $ingredients
            );


            $this->pdo
                ->commit();


            return $pubAssetId;

        } catch (Throwable $e) {
            if (
                $this->pdo
                    ->inTransaction()
            ) {
                $this->pdo
                    ->rollBack();
            }


            throw $e;
        }
    }



    /**
     * Determine whether this exact replacement order has already been
     * prepared or completed for the same Analyze run.
     *
     * CREATE uses this as an idempotency guard before waking the Creator.
     * It prevents a duplicate/retried Box from rendering or queueing the
     * same durable asset twice while still allowing genuinely changed
     * ingredients to proceed through replaceWithOrder().
     */
    public function replacementAlreadyPrepared(
        int $pubAssetId,
        array $asset,
        array $ingredients
    ): bool {
        if ($pubAssetId <= 0) {
            return false;
        }


        $current =
            $this->getById(
                $pubAssetId
            );


        if ($current === null) {
            return false;
        }


        $stage =
            strtolower(
                trim(
                    (string)(
                        $current[
                            'pipeline_stage'
                        ]
                        ?? ''
                    )
                )
            );


        if (
            !in_array(
                $stage,
                [
                    'creating',
                    'created',
                ],
                true
            )
        ) {
            return false;
        }


        if (
            !$this->replacementMetadataMatches(
                $current,
                $asset
            )
        ) {
            return false;
        }


        $order =
            $this->getOrder(
                $pubAssetId
            );


        if ($order === null) {
            return false;
        }


        $incomingCreatorKey =
            trim(
                (string)(
                    $asset[
                        'creator_key'
                    ]
                    ?? ''
                )
            );


        if (
            trim(
                (string)(
                    $order[
                        'creator_key'
                    ]
                    ?? ''
                )
            ) !== $incomingCreatorKey
        ) {
            return false;
        }


        return (
            $order[
                'ingredients'
            ]
            ?? []
        ) == $ingredients;
    }


    /*
     * ========================================================
     * ORDER
     * ========================================================
     */

    /**
     * Save the complete current ingredient set.
     *
     * The ingredients are stored exactly as JSON.
     *
     * Upsert is intentional:
     * this may file the initial ingredients or replace
     * the complete filed ingredients later.
     */
    public function saveOrder(
        int $pubAssetId,
        string $creatorKey,
        array $ingredients
    ): void {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }

        $creatorKey =
            trim(
                $creatorKey
            );

        if ($creatorKey === '') {
            throw new RuntimeException(
                'PUB asset order requires creator_key.'
            );
        }

        $ingredientsJson =
            json_encode(
                $ingredients,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            );

        $stmt =
            $this->pdo->prepare(
                <<<SQL
                INSERT INTO pub_asset_orders (
                    pub_asset_id,
                    creator_key,
                    ingredients
                ) VALUES (
                    :pub_asset_id,
                    :creator_key,
                    :ingredients
                )

                ON DUPLICATE KEY UPDATE
                    creator_key =
                        VALUES(creator_key),

                    ingredients =
                        VALUES(ingredients)
                SQL
            );

        $stmt->execute([
            'pub_asset_id' =>
                $pubAssetId,

            'creator_key' =>
                $creatorKey,

            'ingredients' =>
                $ingredientsJson,
        ]);
    }


    /**
     * Fetch the current filed order.
     *
     * Returns decoded ingredients JSON.
     */
    public function getOrder(
        int $pubAssetId
    ): ?array {
        if ($pubAssetId <= 0) {
            return null;
        }

        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT
                    pub_asset_order_id,
                    pub_asset_id,
                    creator_key,
                    ingredients,
                    created_at,
                    updated_at

                FROM pub_asset_orders

                WHERE pub_asset_id =
                    :pub_asset_id

                LIMIT 1
                SQL
            );

        $stmt->execute([
            'pub_asset_id' =>
                $pubAssetId,
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$row) {
            return null;
        }

        $ingredients =
            json_decode(
                (string)$row['ingredients'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );

        if (!is_array($ingredients)) {
            throw new RuntimeException(
                "PUB asset order #{$pubAssetId} contains invalid ingredients JSON."
            );
        }

        return [
            'pub_asset_order_id' =>
                (int)$row[
                    'pub_asset_order_id'
                ],

            'pub_asset_id' =>
                (int)$row[
                    'pub_asset_id'
                ],

            'creator_key' =>
                (string)$row[
                    'creator_key'
                ],

            'ingredients' =>
                $ingredients,

            'created_at' =>
                $row[
                    'created_at'
                ],

            'updated_at' =>
                $row[
                    'updated_at'
                ],
        ];
    }


    /**
     * Update editable asset metadata and/or explicitly
     * requested Creator ingredient values in the current
     * filed order.
     *
     * If search_title or description changes,
     * pub_assets is updated in the SAME transaction
     * so the asset row and order can never drift.
     *
     * This does NOT recreate the asset.
     *
     * Recreate is a separate CREATE Coordinator action.
     */
public function updateOrder(
    int $pubAssetId,
    array $changes
): array {
    if ($pubAssetId <= 0) {
        throw new RuntimeException(
            'Valid pub_asset_id required.'
        );
    }


    $asset =
        $this->getById(
            $pubAssetId
        );


    if ($asset === null) {
        throw new RuntimeException(
            'PUB asset not found.'
        );
    }


    $this->assertEditableStage(
        $asset
    );


    $order =
        $this->getOrder(
            $pubAssetId
        );


    if ($order === null) {
        throw new RuntimeException(
            'PUB asset has no filed order.'
        );
    }


    $ingredients =
        $order[
            'ingredients'
        ];


    /*
     * Keep the exact filed ingredient box so we can tell whether this
     * edit actually changes anything baked into the physical asset.
     *
     * Metadata-only edits must NOT trigger REDO.
     */
    $originalIngredients =
        $ingredients;


    /*
     * CONTRACT-DECLARED INGREDIENT REPAIRS.
     *
     * Asset Editor changes Box metadata.
     *
     * Only fields explicitly declared by the product's
     * ingredientBindings are also repaired inside the
     * filed Creator ingredients used by REDO.
     */
    $bindings =
        PubContract::ingredientBindingsForCreatedAssetType(
            (string)(
                $asset[
                    'asset_type'
                ]
                ?? ''
            )
        );


    foreach (
        $bindings
        as $binding
    ) {
        $boxField =
            trim(
                (string)(
                    $binding[
                        'boxField'
                    ]
                    ?? ''
                )
            );

        $ingredientPath =
            trim(
                (string)(
                    $binding[
                        'ingredientPath'
                    ]
                    ?? ''
                )
            );


        if (
            $boxField === ''
            ||
            $ingredientPath === ''
            ||
            !array_key_exists(
                $boxField,
                $changes
            )
        ) {
            continue;
        }


        $ingredients =
            $this->setNestedValue(
                $ingredients,
                $ingredientPath,
                $changes[
                    $boxField
                ]
            );
    }




    /*
     * DIRECT INSIDE-INGREDIENT EDITS.
     *
     * These values have no pub_assets metadata equivalent.
     * The admin endpoint decides which ingredient paths are
     * editable for each asset type and passes only those paths.
     *
     * Example:
     *
     *   ingredient_changes.end_slide_text
     *
     * becomes:
     *
     *   ingredients.end_slide_text
     */
    $ingredientChanges =
        is_array(
            $changes[
                'ingredient_changes'
            ]
            ?? null
        )
            ? $changes[
                'ingredient_changes'
            ]
            : [];


    foreach (
        $ingredientChanges
        as $ingredientPath => $value
    ) {
        $ingredientPath =
            trim(
                (string)$ingredientPath
            );


        if ($ingredientPath === '') {
            continue;
        }


        $ingredients =
            $this->setNestedValue(
                $ingredients,
                $ingredientPath,
                $value
            );
    }


    /*
     * One invariant:
     *
     *   created
     *     = physical asset matches the current filed ingredients
     *
     *   redo_required
     *     = filed ingredients changed after the current physical asset
     *
     * PHP array equality intentionally ignores associative key order,
     * so harmless JSON/object ordering differences do not trigger REDO.
     */
    $ingredientsChanged =
        $ingredients !=
        $originalIngredients;


    /*
     * Do not let an already-running render finish later and falsely
     * mark a newly edited order as created.
     *
     * Ingredient edits can be retried as soon as the active CREATE
     * settles. Metadata-only edits remain allowed while creating.
     */
    $currentStage =
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
     * A metadata edit does not require CREATE again, but once an asset
     * has entered PACKING it can make the existing outbound package stale.
     * Compare normalized values so a no-op Save does not trigger repacking.
     */
    $metadataChanged =
        (
            array_key_exists(
                'search_title',
                $changes
            )
            && $this->nullableString(
                $changes['search_title']
            ) !== $this->nullableString(
                $asset['search_title']
                ?? null
            )
        )
        ||
        (
            array_key_exists(
                'description',
                $changes
            )
            && $this->nullableString(
                $changes['description']
            ) !== $this->nullableString(
                $asset['description']
                ?? null
            )
        );


    if (
        $ingredientsChanged
        && $currentStage === 'creating'
    ) {
        throw new RuntimeException(
            'Production ingredients cannot be changed while this asset is creating. Wait for the current render to finish, then edit and redo.'
        );
    }


    $this->pdo
        ->beginTransaction();


    try {
        /*
         * DURABLE ASSET METADATA.
         */
        $assetUpdates =
            [];

        $params = [
            'pub_asset_id' =>
                $pubAssetId,
        ];


        if (
            array_key_exists(
                'search_title',
                $changes
            )
        ) {
            $assetUpdates[] =
                'search_title = :search_title';

            $params[
                'search_title'
            ] =
                $this->nullableString(
                    $changes[
                        'search_title'
                    ]
                );
        }


        if (
            array_key_exists(
                'description',
                $changes
            )
        ) {
            $assetUpdates[] =
                'description = :description';

            $params[
                'description'
            ] =
                $this->nullableString(
                    $changes[
                        'description'
                    ]
                );
        }


        /*
         * Any actual ingredient change makes the existing physical
         * asset stale immediately.
         *
         * This state change is committed in the SAME transaction as
         * the new ingredient box. If the subsequent REDO request never
         * happens, the asset remains visibly recoverable as
         * redo_required instead of pretending to be current.
         */
        if ($ingredientsChanged) {
            $assetUpdates[] =
                'approved = 0';

            $assetUpdates[] =
                '`package` = NULL';

            $assetUpdates[] =
                'stage_note = NULL';

            $assetUpdates[] =
                "pipeline_stage = 'redo_required'";

            $assetUpdates[] =
                'error_stage = NULL';

            $assetUpdates[] =
                'error_code = NULL';

            $assetUpdates[] =
                'error_message = NULL';

            $assetUpdates[] =
                'errored_at = NULL';
        }


        /*
         * Outside-the-dish metadata does not require REDO.
         * If Packing has already started, however, the old package
         * must be discarded and rebuilt from the updated asset row.
         */
        if (
            !$ingredientsChanged
            && $metadataChanged
            && in_array(
                $currentStage,
                [
                    'packing',
                    'pending',
                    'packed',
                    'queued',
                ],
                true
            )
        ) {
            $assetUpdates[] =
                '`package` = NULL';

            $assetUpdates[] =
                'stage_note = NULL';

            $assetUpdates[] =
                "pipeline_stage = 'packing'";
        }


        if ($assetUpdates) {
            $sql =
                'UPDATE pub_assets SET '
                . implode(
                    ', ',
                    $assetUpdates
                )
                . ' WHERE pub_asset_id = :pub_asset_id'
                . " AND pipeline_stage NOT IN ('shipped', 'dispatched', 'published')";


            $stmt =
                $this->pdo->prepare(
                    $sql
                );


            $stmt->execute(
                $params
            );
        }


        /*
         * REDO ORDER.
         *
         * Save the complete ingredient set after applying
         * any contract-declared mirrored metadata changes.
         */
        $this->saveOrder(
            $pubAssetId,

            (string)(
                $order[
                    'creator_key'
                ]
            ),

            $ingredients
        );


        $this->pdo
            ->commit();

    } catch (Throwable $e) {
        if (
            $this->pdo
                ->inTransaction()
        ) {
            $this->pdo
                ->rollBack();
        }


        throw $e;
    }


    return [
        'pub_asset_id' =>
            $pubAssetId,

        'creator_key' =>
            (string)(
                $order[
                    'creator_key'
                ]
            ),

        'ingredients' =>
            $ingredients,

        'search_title' =>
            array_key_exists(
                'search_title',
                $changes
            )
                ? $changes[
                    'search_title'
                ]
                : $asset[
                    'search_title'
                ],

        'description' =>
            array_key_exists(
                'description',
                $changes
            )
                ? $changes[
                    'description'
                ]
                : $asset[
                    'description'
                ],

        'pipeline_stage' =>
            $ingredientsChanged
                ? 'redo_required'
                : (
                    $metadataChanged
                    && in_array(
                        $currentStage,
                        [
                            'packing',
                            'pending',
                            'packed',
                            'queued',
                        ],
                        true
                    )
                        ? 'packing'
                        : (string)(
                            $asset[
                                'pipeline_stage'
                            ]
                            ?? ''
                        )
                ),

        'ingredients_changed' =>
            $ingredientsChanged,
    ];
}


    /**
     * Remove the temporary in-house order.
     *
     * Eventually called after successful dispatch.
     */
    public function deleteOrder(
        int $pubAssetId
    ): void {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }

        $stmt =
            $this->pdo->prepare(
                <<<SQL
                DELETE FROM pub_asset_orders

                WHERE pub_asset_id =
                    :pub_asset_id
                SQL
            );

        $stmt->execute([
            'pub_asset_id' =>
                $pubAssetId,
        ]);
    }


    /*
     * ========================================================
     * ASSET — CREATION RESULT
     * ========================================================
     */

    /**
     * Mark one durable PUB asset as actively being created.
     *
     * CreateManager calls this immediately before handing
     * production to a Creator.
     *
     * For REDO, the existing physical asset fields are deliberately
     * preserved until the replacement has successfully finished.
     */
    public function markCreating(
        int $pubAssetId
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }


        $asset =
            $this->getById(
                $pubAssetId
            );


        if ($asset === null) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} was not found."
            );
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
                    'shipped',
                    'dispatched',
                    'published',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} has already left CREATE and cannot be marked creating."
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_assets
                SET
                    pipeline_stage = 'creating',
                    approved = 0,
                    `package` = NULL,
                    stage_note = NULL,

                    error_stage = NULL,
                    error_code = NULL,
                    error_message = NULL,
                    errored_at = NULL

                WHERE pub_asset_id =
                    :pub_asset_id

                  AND pipeline_stage NOT IN (
                      'shipped',
                      'dispatched',
                      'published'
                  )
                SQL
            );


        $stmt->execute([
            'pub_asset_id' =>
                $pubAssetId,
        ]);


        /*
         * MySQL may report zero affected rows when the asset was
         * already in exactly this state. Re-read the row so a valid
         * no-op is accepted while a concurrent lifecycle change is not.
         */
        $current =
            $this->getById(
                $pubAssetId
            );


        if ($current === null) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} disappeared while being marked creating."
            );
        }


        $currentStage =
            strtolower(
                trim(
                    (string)(
                        $current[
                            'pipeline_stage'
                        ]
                        ?? ''
                    )
                )
            );


        if ($currentStage !== 'creating') {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} could not be marked creating."
            );
        }


        return [
            'pub_asset_id' =>
                $pubAssetId,

            'pipeline_stage' =>
                'creating',
        ];
    }


    /**
     * Accept one complete finished asset from CreateManager,
     * map the asset fields to pub_assets, and confirm that
     * the durable row was updated.
     *
     * Creator knows the product.
     * Manager knows when persistence is authorized.
     * Repository knows the pub_assets row.
     *
     * Unknown asset fields are rejected rather than silently
     * discarded. When a Creator adds a new output field, the
     * Creator/Repository contract must be updated deliberately.
     *
     * @return array{
     *   pub_asset_id: int,
     *   pipeline_stage: string,
     *   local_file_status: string
     * }
     */
    public function markCreated(
        int $pubAssetId,
        array $asset
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }


        $acceptedFields = [
            'file_path',
            'url',
            'thumbnail_file_path',
            'thumbnail_url',
            'mime_type',
            'width',
            'height',
            'duration_ms',
            'file_size_bytes',
            'checksum',
        ];


        $unknownFields =
            array_values(
                array_diff(
                    array_keys($asset),
                    $acceptedFields
                )
            );


        if ($unknownFields !== []) {
            throw new RuntimeException(
                'PUB asset contains fields the repository does not know how to persist: '
                . implode(
                    ', ',
                    $unknownFields
                )
                . '.'
            );
        }


        $filePath =
            trim(
                (string)(
                    $asset['file_path']
                    ?? ''
                )
            );

        $url =
            trim(
                (string)(
                    $asset['url']
                    ?? ''
                )
            );

        $mimeType =
            trim(
                (string)(
                    $asset['mime_type']
                    ?? ''
                )
            );


        if ($filePath === '') {
            throw new RuntimeException(
                'Created PUB asset requires file_path.'
            );
        }

        if ($url === '') {
            throw new RuntimeException(
                'Created PUB asset requires url.'
            );
        }

        if ($mimeType === '') {
            throw new RuntimeException(
                'Created PUB asset requires mime_type.'
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_assets
                SET
                    file_path = :file_path,
                    url = :url,
                    thumbnail_file_path = :thumbnail_file_path,
                    thumbnail_url = :thumbnail_url,
                    mime_type = :mime_type,

                    width = :width,
                    height = :height,
                    duration_ms = :duration_ms,

                    file_size_bytes = :file_size_bytes,
                    checksum = :checksum,

                    `package` = NULL,
                    stage_note = NULL,

                    pipeline_stage = 'created',
                    approved = 0,
                    local_file_status = 'present',

                    error_stage = NULL,
                    error_code = NULL,
                    error_message = NULL,
                    errored_at = NULL

                WHERE pub_asset_id =
                    :pub_asset_id
                SQL
            );

        $stmt->execute([
            'file_path' =>
                $filePath,

            'url' =>
                $url,

            'thumbnail_file_path' =>
                $this->nullableString(
                    $asset['thumbnail_file_path']
                    ?? null
                ),

            'thumbnail_url' =>
                $this->nullableString(
                    $asset['thumbnail_url']
                    ?? null
                ),

            'mime_type' =>
                $mimeType,

            'width' =>
                isset(
                    $asset['width']
                )
                    ? (int)$asset['width']
                    : null,

            'height' =>
                isset(
                    $asset['height']
                )
                    ? (int)$asset['height']
                    : null,

            'duration_ms' =>
                isset(
                    $asset['duration_ms']
                )
                    ? (int)$asset['duration_ms']
                    : null,

            'file_size_bytes' =>
                isset(
                    $asset[
                        'file_size_bytes'
                    ]
                )
                    ? (int)$asset[
                        'file_size_bytes'
                    ]
                    : null,

            'checksum' =>
                $this->nullableString(
                    $asset['checksum']
                    ?? null
                ),

            'pub_asset_id' =>
                $pubAssetId,
        ]);


        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} could not be marked created."
            );
        }


        return [
            'pub_asset_id' =>
                $pubAssetId,

            'pipeline_stage' =>
                'created',

            'local_file_status' =>
                'present',
        ];
    }


    /*
     * ========================================================
     * ASSET — PACKAGE
     * ========================================================
     */

    /**
     * Update the outside-of-box destination used by Package.
     *
     * PinterestImagePackager uses this to deliberately clear an inherited
     * source REX from pin_teaser assets before returning PENDING.
     *
     * The Package admin also uses it when the operator supplies or changes
     * a destination.
     *
     * Lifecycle:
     *
     *   packing
     *     destination may change without changing stage
     *
     *   pending
     *     destination may change; remains pending until an explicit retry
     *
     *   packed
     *     changing destination invalidates the sealed package and returns
     *     the asset to packing
     *
     *   error/package
     *     destination may be corrected while the Package error remains;
     *     an explicit retry then re-enters packing
     *
     * @return array{
     *   pub_asset_id: int,
     *   pipeline_stage: string,
     *   pingback: string|null
     * }
     */
    public function updatePackagePingback(
        int $pubAssetId,
        ?string $pingback
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }


        $asset =
            $this->getById(
                $pubAssetId
            );


        if ($asset === null) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} was not found."
            );
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

        $errorStage =
            strtolower(
                trim(
                    (string)(
                        $asset[
                            'error_stage'
                        ]
                        ?? ''
                    )
                )
            );


        $isPackageError =
            $stage === 'error'
            && $errorStage === 'package';


        if (
            !in_array(
                $stage,
                [
                    'packing',
                    'pending',
                    'packed',
                ],
                true
            )
            && !$isPackageError
        ) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} is not under Package control."
            );
        }


        $normalizedPingback =
            $this->nullableString(
                $pingback
            );

        $currentPingback =
            $this->nullableString(
                $asset[
                    'pingback'
                ]
                ?? null
            );


        /*
         * A no-op must not disturb Package state or updated_at.
         */
        if ($normalizedPingback === $currentPingback) {
            return [
                'pub_asset_id' =>
                    $pubAssetId,

                'pipeline_stage' =>
                    $stage,

                'pingback' =>
                    $normalizedPingback,
            ];
        }


        /*
         * A PACKED package physically contains the old destination.
         * Changing it makes that sealed box stale, so return it to
         * PACKING and discard the old package.
         *
         * PENDING stays PENDING until the operator explicitly retries.
         * PACKING stays PACKING so the current Packager pass can continue.
         * PACKAGE errors remain errors until an explicit retry.
         */
        $nextStage =
            $stage === 'packed'
                ? 'packing'
                : $stage;


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_assets
                SET
                    pingback =
                        :pingback,

                    pipeline_stage =
                        :pipeline_stage,

                    `package` =
                        NULL,

                    stage_note =
                        CASE
                            WHEN :clear_stage_note = 1
                                THEN NULL
                            ELSE stage_note
                        END

                WHERE pub_asset_id =
                    :pub_asset_id

                  AND (
                      pipeline_stage IN (
                          'packing',
                          'pending',
                          'packed'
                      )

                      OR (
                          pipeline_stage = 'error'
                          AND error_stage = 'package'
                      )
                  )
                SQL
            );


        $stmt->execute([
            'pingback' =>
                $normalizedPingback,

            'pipeline_stage' =>
                $nextStage,

            'clear_stage_note' =>
                $stage === 'packed'
                    ? 1
                    : 0,

            'pub_asset_id' =>
                $pubAssetId,
        ]);


        $current =
            $this->getById(
                $pubAssetId
            );


        if ($current === null) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} disappeared while updating Package pingback."
            );
        }


        $currentStage =
            strtolower(
                trim(
                    (string)(
                        $current[
                            'pipeline_stage'
                        ]
                        ?? ''
                    )
                )
            );


        if (
            $currentStage !== $nextStage
            || $this->nullableString(
                $current[
                    'pingback'
                ]
                ?? null
            ) !== $normalizedPingback
        ) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} Package pingback could not be updated."
            );
        }


        return [
            'pub_asset_id' =>
                $pubAssetId,

            'pipeline_stage' =>
                $currentStage,

            'pingback' =>
                $normalizedPingback,
        ];
    }


    /**
     * Move one reviewed in-house asset onto the Packing station.
     *
     * Any previous package is deliberately discarded. A package is
     * only trustworthy while it matches the current asset row.
     *
     * @return array{
     *   pub_asset_id: int,
     *   pipeline_stage: string
     * }
     */
    public function markPacking(
        int $pubAssetId
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }


        $asset =
            $this->getById(
                $pubAssetId
            );


        if ($asset === null) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} was not found."
            );
        }


        $this->assertEditableStage(
            $asset
        );


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


        $errorStage =
            strtolower(
                trim(
                    (string)(
                        $asset[
                            'error_stage'
                        ]
                        ?? ''
                    )
                )
            );


        $isRetryablePackageError =
            $stage === 'error'
            && $errorStage === 'package';


        if (
            !in_array(
                $stage,
                [
                    'created',
                    'packing',
                    'pending',
                    'packed',
                    'queued',
                ],
                true
            )
            && !$isRetryablePackageError
        ) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} is not ready for Packing."
            );
        }


        if (
            $stage === 'created'
            && (int)(
                $asset[
                    'approved'
                ]
                ?? 0
            ) !== 1
        ) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} must be approved before Packing."
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_assets
                SET
                    pipeline_stage = 'packing',
                    `package` = NULL,
                    stage_note = NULL,

                    error_stage = NULL,
                    error_code = NULL,
                    error_message = NULL,
                    errored_at = NULL

                WHERE pub_asset_id =
                    :pub_asset_id

                  AND (
                      (
                          pipeline_stage = 'created'
                          AND approved = 1
                      )

                      OR pipeline_stage IN (
                          'packing',
                          'pending',
                          'packed',
                          'queued'
                      )

                      OR (
                          pipeline_stage = 'error'
                          AND error_stage = 'package'
                      )
                  )
                SQL
            );


        $stmt->execute([
            'pub_asset_id' =>
                $pubAssetId,
        ]);


        $current =
            $this->getById(
                $pubAssetId
            );


        if (
            $current === null
            || strtolower(
                trim(
                    (string)(
                        $current[
                            'pipeline_stage'
                        ]
                        ?? ''
                    )
                )
            ) !== 'packing'
        ) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} could not be marked packing."
            );
        }


        return [
            'pub_asset_id' =>
                $pubAssetId,

            'pipeline_stage' =>
                'packing',
        ];
    }


    /**
     * Persist the complete outbound package prepared by a Packager.
     *
     * The JSON shape is intentionally channel/type specific. The
     * repository stores it without interpreting its contents.
     *
     * @return array{
     *   pub_asset_id: int,
     *   pipeline_stage: string
     * }
     */
    public function markPacked(
        int $pubAssetId,
        array $package
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }


        if ($package === []) {
            throw new RuntimeException(
                'PUB package cannot be empty.'
            );
        }


        $packageJson =
            json_encode(
                $package,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            );


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_assets
                SET
                    `package` = :package,
                    pipeline_stage = 'packed',
                    stage_note = NULL,

                    error_stage = NULL,
                    error_code = NULL,
                    error_message = NULL,
                    errored_at = NULL

                WHERE pub_asset_id =
                    :pub_asset_id

                  AND pipeline_stage = 'packing'
                SQL
            );


        $stmt->execute([
            'package' =>
                $packageJson,

            'pub_asset_id' =>
                $pubAssetId,
        ]);


        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} could not be marked packed."
            );
        }


        return [
            'pub_asset_id' =>
                $pubAssetId,

            'pipeline_stage' =>
                'packed',
        ];
    }


    /**
     * Hold one valid Package assignment because a required dependency
     * does not exist yet.
     *
     * PENDING is a normal durable pipeline stage, not an error.
     *
     *   packing -> pending
     *
     * An explicit retry later returns:
     *
     *   pending -> packing
     */
    public function markPackingPending(
        int $pubAssetId,
        string $note
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }


        $note =
            trim(
                $note
            );


        if ($note === '') {
            throw new RuntimeException(
                'Packing pending note cannot be empty.'
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_assets
                SET
                    pipeline_stage = 'pending',
                    stage_note = :stage_note,
                    `package` = NULL,

                    error_stage = NULL,
                    error_code = NULL,
                    error_message = NULL,
                    errored_at = NULL

                WHERE pub_asset_id =
                    :pub_asset_id

                  AND pipeline_stage = 'packing'
                SQL
            );


        $stmt->execute([
            'stage_note' =>
                $note,

            'pub_asset_id' =>
                $pubAssetId,
        ]);


        $current =
            $this->getById(
                $pubAssetId
            );


        if (
            $current === null
            || strtolower(
                trim(
                    (string)(
                        $current[
                            'pipeline_stage'
                        ]
                        ?? ''
                    )
                )
            ) !== 'pending'
        ) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} could not be marked pending."
            );
        }


        return [
            'pub_asset_id' =>
                $pubAssetId,

            'pipeline_stage' =>
                'pending',

            'stage_note' =>
                $note,
        ];
    }


    /**
     * Fetch exactly one asset currently assigned to Package.
     *
     * This is the single-item equivalent of listPacking().
     * It deliberately omits package/shipping_receipt JSON.
     */
    public function getPackingById(
        int $pubAssetId
    ): ?array {
        if ($pubAssetId <= 0) {
            return null;
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT
                    pub_asset_id,
                    pub_run_id,

                    channel,
                    asset_type,
                    creator_key,

                    source_type,
                    source_id,
                    sort_order,

                    pipeline_stage,

                    search_title,
                    description,
                    pingback,

                    file_path,
                    url,
                    thumbnail_file_path,
                    thumbnail_url,
                    mime_type,

                    width,
                    height,
                    duration_ms,

                    file_size_bytes,
                    checksum,

                    created_at,
                    updated_at

                FROM pub_assets

                WHERE pub_asset_id =
                    :pub_asset_id

                  AND pipeline_stage = 'packing'

                LIMIT 1
                SQL
            );


        $stmt->execute([
            'pub_asset_id' =>
                $pubAssetId,
        ]);


        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        return $row ?: null;
    }


    /**
     * PackageManager's workbench.
     *
     * Deliberately does NOT select package or shipping_receipt JSON.
     * Rows at this stage are inspected from their durable asset fields;
     * the Packager creates package JSON only when the box is complete.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPacking(): array
    {
        $stmt =
            $this->pdo->query(
                <<<SQL
                SELECT
                    pub_asset_id,
                    pub_run_id,

                    channel,
                    asset_type,
                    creator_key,

                    source_type,
                    source_id,
                    sort_order,

                    pipeline_stage,

                    search_title,
                    description,
                    pingback,

                    file_path,
                    url,
                    thumbnail_file_path,
                    thumbnail_url,
                    mime_type,

                    width,
                    height,
                    duration_ms,

                    file_size_bytes,
                    checksum,

                    created_at,
                    updated_at

                FROM pub_assets

                WHERE pipeline_stage = 'packing'

                ORDER BY pub_asset_id ASC
                SQL
            );


        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        ) ?: [];
    }


    /*
     * ========================================================
     * ASSET — SCHEDULE QUEUE
     * ========================================================
     */

    /**
     * Release one sealed PACKED asset into Schedule's active queue.
     *
     * PACKED means:
     *   complete and sealed, but held outside automatic scheduling.
     *
     * QUEUED means:
     *   explicitly released into the active Schedule candidate pool.
     *
     * The sealed package is preserved unchanged.
     *
     * @return array{
     *   pub_asset_id: int,
     *   pipeline_stage: string,
     *   updated_at: string|null
     * }
     */
    public function enqueue(
        int $pubAssetId
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_assets
                SET
                    pipeline_stage = 'queued',
                    stage_note = NULL

                WHERE pub_asset_id =
                    :pub_asset_id

                  AND pipeline_stage =
                    'packed'

                  AND `package` IS NOT NULL
                SQL
            );


        $stmt->execute([
            'pub_asset_id' =>
                $pubAssetId,
        ]);


        if ($stmt->rowCount() !== 1) {
            $current =
                $this->getById(
                    $pubAssetId
                );


            if ($current === null) {
                throw new RuntimeException(
                    "PUB asset #{$pubAssetId} was not found."
                );
            }


            $stage =
                strtolower(
                    trim(
                        (string)(
                            $current[
                                'pipeline_stage'
                            ]
                            ?? ''
                        )
                    )
                );


            if ($stage !== 'packed') {
                throw new RuntimeException(
                    "PUB asset #{$pubAssetId} cannot be queued; expected packed."
                );
            }


            throw new RuntimeException(
                "PUB asset #{$pubAssetId} has no sealed package to queue."
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT
                    updated_at

                FROM pub_assets

                WHERE pub_asset_id =
                    :pub_asset_id

                LIMIT 1
                SQL
            );


        $stmt->execute([
            'pub_asset_id' =>
                $pubAssetId,
        ]);


        $updatedAt =
            $stmt->fetchColumn();


        return [
            'pub_asset_id' =>
                $pubAssetId,

            'pipeline_stage' =>
                'queued',

            'updated_at' =>
                $updatedAt !== false
                    ? (string)$updatedAt
                    : null,
        ];
    }


    /**
     * Pull one QUEUED asset back out of automatic Schedule selection.
     *
     * This is a control move only:
     *
     *   queued -> packed
     *
     * The sealed package is preserved unchanged, so the asset may be
     * re-enqueued later without returning to Package.
     *
     * @return array{
     *   pub_asset_id: int,
     *   pipeline_stage: string
     * }
     */
    public function dequeue(
        int $pubAssetId
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_assets
                SET
                    pipeline_stage = 'packed',
                    stage_note = NULL

                WHERE pub_asset_id =
                    :pub_asset_id

                  AND pipeline_stage =
                    'queued'

                  AND `package` IS NOT NULL
                SQL
            );


        $stmt->execute([
            'pub_asset_id' =>
                $pubAssetId,
        ]);


        if ($stmt->rowCount() !== 1) {
            $current =
                $this->getById(
                    $pubAssetId
                );


            if ($current === null) {
                throw new RuntimeException(
                    "PUB asset #{$pubAssetId} was not found."
                );
            }


            $stage =
                strtolower(
                    trim(
                        (string)(
                            $current[
                                'pipeline_stage'
                            ]
                            ?? ''
                        )
                    )
                );


            if ($stage !== 'queued') {
                throw new RuntimeException(
                    "PUB asset #{$pubAssetId} cannot be removed from the Schedule queue; expected queued."
                );
            }


            throw new RuntimeException(
                "PUB asset #{$pubAssetId} has no sealed package."
            );
        }


        return [
            'pub_asset_id' =>
                $pubAssetId,

            'pipeline_stage' =>
                'packed',
        ];
    }


    /**
     * ScheduleManager's active loading dock.
     *
     * Only QUEUED rows are returned. PACKED rows are deliberately
     * invisible to automatic scheduling.
     *
     * Package JSON is not decoded because Schedule never opens it.
     * It needs only to know that a sealed package exists.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listQueued(
        ?string $channel = null
    ): array {
        $channel =
            strtolower(
                trim(
                    (string)$channel
                )
            );


        $where = [
            "pipeline_stage = 'queued'",
            "`package` IS NOT NULL",
        ];

        $params = [];


        if ($channel !== '') {
            $where[] =
                'channel = :channel';

            $params[
                'channel'
            ] =
                $channel;
        }


        $sql = <<<SQL
            SELECT
                pub_asset_id,
                pub_run_id,

                channel,
                asset_type,
                creator_key,

                source_type,
                source_id,
                sort_order,

                pipeline_stage,

                search_title,
                description,
                pingback,

                mime_type,

                updated_at

            FROM pub_assets

            WHERE
            SQL;

        $sql .=
            "\n    "
            . implode(
                "\n    AND ",
                $where
            );

        $sql .=
            "\nORDER BY updated_at ASC, pub_asset_id ASC";


        $stmt =
            $this->pdo->prepare(
                $sql
            );


        $stmt->execute(
            $params
        );


        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        ) ?: [];
    }


    /**
     * Durable successful shipment history consumed by ScheduleManager.
     *
     * This is history, not Scheduler memory. Every Schedule run may
     * reconstruct cadence/diversity decisions fresh from these rows.
     *
     * shipping_receipt is not exposed wholesale. Schedule only needs the
     * durable external_url for dependency checks such as Pinterest teasers
     * waiting on an already-published YouTube destination.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listShippedForSchedule(
        ?string $channel = null
    ): array {
        $channel =
            strtolower(
                trim(
                    (string)$channel
                )
            );


        $where = [
            "pipeline_stage = 'shipped'",
            "dispatched_at IS NOT NULL",
        ];

        $params = [];


        if ($channel !== '') {
            $where[] =
                'channel = :channel';

            $params[
                'channel'
            ] =
                $channel;
        }


        $sql = <<<SQL
            SELECT
                pub_asset_id,
                pub_run_id,

                channel,
                asset_type,

                source_type,
                source_id,
                sort_order,

                description,
                pingback,

                JSON_UNQUOTE(
                    JSON_EXTRACT(
                        shipping_receipt,
                        '$.external_url'
                    )
                ) AS external_url,

                dispatched_at

            FROM pub_assets

            WHERE
            SQL;

        $sql .=
            "\n    "
            . implode(
                "\n    AND ",
                $where
            );

        $sql .=
            "\nORDER BY dispatched_at DESC, pub_asset_id DESC";


        $stmt =
            $this->pdo->prepare(
                $sql
            );


        $stmt->execute(
            $params
        );


        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        ) ?: [];
    }


    public function markError(
        int $pubAssetId,
        string $stage,
        string $code,
        string $message
    ): void {
        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_assets
                SET
                    pipeline_stage = 'error',
                    stage_note = NULL,
                    error_stage = :error_stage,
                    error_code = :error_code,
                    error_message = :error_message,
                    errored_at = NOW()

                WHERE pub_asset_id =
                    :pub_asset_id
                SQL
            );

        $stmt->execute([
            'error_stage' =>
                trim(
                    $stage
                ),

            'error_code' =>
                trim(
                    $code
                ),

            'error_message' =>
                trim(
                    $message
                ),

            'pub_asset_id' =>
                $pubAssetId,
        ]);
    }


    /*
     * ========================================================
     * SCHEDULE — ADMIN
     * ========================================================
     */

    /**
     * Schedule workbench summary rows.
     *
     * Includes only the two durable Schedule-controlled stages:
     *
     *   packed
     *   queued
     *
     * PACKED means sealed/on deck but held outside automatic Schedule.
     * QUEUED means released into ScheduleManager's active candidate pool.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForScheduleAdmin(
        ?string $channel = null,
        ?string $assetType = null,
        ?string $stage = null
    ): array {
        $where = [
            "pipeline_stage IN ('packed', 'queued')",
            "`package` IS NOT NULL",
        ];

        $params = [];

        $channel =
            strtolower(
                trim(
                    (string)$channel
                )
            );

        $assetType =
            strtolower(
                trim(
                    (string)$assetType
                )
            );

        $stage =
            strtolower(
                trim(
                    (string)$stage
                )
            );


        if ($channel !== '') {
            $where[] =
                'channel = :channel';

            $params[
                'channel'
            ] =
                $channel;
        }


        if ($assetType !== '') {
            $where[] =
                'asset_type = :asset_type';

            $params[
                'asset_type'
            ] =
                $assetType;
        }


        if ($stage !== '') {
            if (
                !in_array(
                    $stage,
                    [
                        'packed',
                        'queued',
                    ],
                    true
                )
            ) {
                throw new RuntimeException(
                    "Unsupported Schedule stage filter '{$stage}'."
                );
            }


            $where[] =
                'pipeline_stage = :pipeline_stage';

            $params[
                'pipeline_stage'
            ] =
                $stage;
        }


        $sql = <<<SQL
            SELECT
                pub_asset_id,
                pub_run_id,

                channel,
                asset_type,

                source_type,
                source_id,
                sort_order,

                pipeline_stage,
                stage_note,

                search_title,
                description,
                pingback,

                updated_at

            FROM pub_assets

            WHERE
            SQL;

        $sql .=
            "\n    "
            . implode(
                "\n    AND ",
                $where
            );

        $sql .=
            "\nORDER BY updated_at ASC, pub_asset_id ASC";


        $stmt =
            $this->pdo->prepare(
                $sql
            );


        $stmt->execute(
            $params
        );


        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        ) ?: [];
    }


    /**
     * One Schedule drawer detail row.
     *
     * The package remains sealed and is deliberately not returned.
     * Schedule only needs the asset metadata and package presence.
     */
    public function getScheduleAdminById(
        int $pubAssetId
    ): ?array {
        if ($pubAssetId <= 0) {
            return null;
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT
                    pub_asset_id,
                    pub_run_id,

                    channel,
                    asset_type,

                    source_type,
                    source_id,
                    sort_order,

                    pipeline_stage,
                    stage_note,

                    search_title,
                    description,
                    pingback,

                    file_path,
                    url,
                    thumbnail_file_path,
                    thumbnail_url,
                    mime_type,
                    duration_ms,

                    CASE
                        WHEN `package` IS NULL THEN 0
                        ELSE 1
                    END AS has_package,

                    created_at,
                    updated_at

                FROM pub_assets

                WHERE pub_asset_id =
                    :pub_asset_id

                  AND pipeline_stage IN (
                      'packed',
                      'queued'
                  )

                  AND `package` IS NOT NULL

                LIMIT 1
                SQL
            );


        $stmt->execute([
            'pub_asset_id' =>
                $pubAssetId,
        ]);


        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        return $row ?: null;
    }


    public function listScheduleChannels(): array
    {
        $stmt =
            $this->pdo->query(
                <<<SQL
                SELECT DISTINCT channel

                FROM pub_assets

                WHERE channel IS NOT NULL
                  AND TRIM(channel) <> ''
                  AND pipeline_stage IN (
                      'packed',
                      'queued'
                  )
                  AND `package` IS NOT NULL

                ORDER BY channel ASC
                SQL
            );


        return array_values(
            array_map(
                'strval',

                $stmt->fetchAll(
                    PDO::FETCH_COLUMN
                ) ?: []
            )
        );
    }


    public function listScheduleAssetTypes(): array
    {
        $stmt =
            $this->pdo->query(
                <<<SQL
                SELECT DISTINCT asset_type

                FROM pub_assets

                WHERE asset_type IS NOT NULL
                  AND TRIM(asset_type) <> ''
                  AND pipeline_stage IN (
                      'packed',
                      'queued'
                  )
                  AND `package` IS NOT NULL

                ORDER BY asset_type ASC
                SQL
            );


        return array_values(
            array_map(
                'strval',

                $stmt->fetchAll(
                    PDO::FETCH_COLUMN
                ) ?: []
            )
        );
    }


    /*
     * ========================================================
     * PACKAGE — ADMIN
     * ========================================================
     */

    /**
     * Package workbench summary rows.
     *
     * Includes:
     *   packing
     *   pending
     *   packed
     *   PACKAGE-stage errors
     *
     * The package JSON itself is intentionally omitted.
     * has_package is enough for the workbench checkmark.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForPackageAdmin(
        ?string $channel = null,
        ?string $assetType = null,
        ?string $stage = null
    ): array {
        $where = [
            "("
            . "(pipeline_stage = 'created' AND approved = 1)"
            . " OR pipeline_stage IN ('packing', 'pending', 'packed')"
            . " OR (pipeline_stage = 'error' AND error_stage = 'package')"
            . ")",
        ];

        $params = [];


        $channel =
            trim(
                (string)$channel
            );

        $assetType =
            trim(
                (string)$assetType
            );

        $stage =
            strtolower(
                trim(
                    (string)$stage
                )
            );


        if ($channel !== '') {
            $where[] =
                'channel = :channel';

            $params[
                'channel'
            ] =
                $channel;
        }


        if ($assetType !== '') {
            $where[] =
                'asset_type = :asset_type';

            $params[
                'asset_type'
            ] =
                $assetType;
        }


        if ($stage !== '') {
            if ($stage === 'approved') {
                $where[] =
                    "(pipeline_stage = 'created' AND approved = 1)";
            } elseif ($stage === 'error') {
                $where[] =
                    "(pipeline_stage = 'error' AND error_stage = 'package')";
            } else {
                $where[] =
                    'pipeline_stage = :pipeline_stage';

                $params[
                    'pipeline_stage'
                ] =
                    $stage;
            }
        }


        $sql = <<<SQL
            SELECT
                pub_asset_id,
                pub_run_id,

                channel,
                asset_type,

                source_type,
                source_id,

                pipeline_stage,
                approved,

                CASE
                    WHEN pipeline_stage = 'created'
                     AND approved = 1
                        THEN 'approved'
                    ELSE pipeline_stage
                END AS display_stage,

                stage_note,

                search_title,

                error_message,

                CASE
                    WHEN `package` IS NULL THEN 0
                    ELSE 1
                END AS has_package,

                updated_at

            FROM pub_assets

            WHERE
            SQL;

        $sql .=
            "\n    "
            . implode(
                "\n    AND ",
                $where
            );

        $sql .=
            "\nORDER BY pub_asset_id DESC";


        $stmt =
            $this->pdo->prepare(
                $sql
            );

        $stmt->execute(
            $params
        );


        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        ) ?: [];
    }


    /**
     * One Package drawer detail row.
     *
     * Unlike the workbench list, this intentionally includes
     * the actual package JSON.
     */
    public function getPackageAdminById(
        int $pubAssetId
    ): ?array {
        if ($pubAssetId <= 0) {
            return null;
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT
                    pub_asset_id,
                    pub_run_id,

                    channel,
                    asset_type,

                    source_type,
                    source_id,

                    pipeline_stage,
                    approved,

                    CASE
                        WHEN pipeline_stage = 'created'
                         AND approved = 1
                            THEN 'approved'
                        ELSE pipeline_stage
                    END AS display_stage,

                    stage_note,

                    search_title,
                    description,
                    pingback,

                    file_path,
                    url,
                    thumbnail_file_path,
                    thumbnail_url,
                    mime_type,

                    width,
                    height,
                    duration_ms,

                    error_stage,
                    error_code,
                    error_message,
                    errored_at,

                    `package`,

                    created_at,
                    updated_at

                FROM pub_assets

                WHERE pub_asset_id =
                    :pub_asset_id

                  AND (
                      (
                          pipeline_stage = 'created'
                          AND approved = 1
                      )

                      OR pipeline_stage IN (
                          'packing',
                          'pending',
                          'packed'
                      )

                      OR (
                          pipeline_stage = 'error'
                          AND error_stage = 'package'
                      )
                  )

                LIMIT 1
                SQL
            );


        $stmt->execute([
            'pub_asset_id' =>
                $pubAssetId,
        ]);


        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$row) {
            return null;
        }


        $rawPackage =
            $row[
                'package'
            ]
            ?? null;


        if (
            is_string(
                $rawPackage
            )
            && trim(
                $rawPackage
            ) !== ''
        ) {
            $decoded =
                json_decode(
                    $rawPackage,
                    true
                );


            if (
                json_last_error() ===
                JSON_ERROR_NONE
            ) {
                $row[
                    'package'
                ] =
                    $decoded;
            }
        }


        return $row;
    }


    public function listPackageChannels(): array
    {
        $stmt =
            $this->pdo->query(
                <<<SQL
                SELECT DISTINCT channel

                FROM pub_assets

                WHERE channel IS NOT NULL
                  AND TRIM(channel) <> ''

                  AND (
                      (
                          pipeline_stage = 'created'
                          AND approved = 1
                      )

                      OR pipeline_stage IN (
                          'packing',
                          'pending',
                          'packed'
                      )

                      OR (
                          pipeline_stage = 'error'
                          AND error_stage = 'package'
                      )
                  )

                ORDER BY channel ASC
                SQL
            );


        return array_values(
            array_map(
                'strval',

                $stmt->fetchAll(
                    PDO::FETCH_COLUMN
                ) ?: []
            )
        );
    }


    public function listPackageAssetTypes(): array
    {
        $stmt =
            $this->pdo->query(
                <<<SQL
                SELECT DISTINCT asset_type

                FROM pub_assets

                WHERE asset_type IS NOT NULL
                  AND TRIM(asset_type) <> ''

                  AND (
                      (
                          pipeline_stage = 'created'
                          AND approved = 1
                      )

                      OR pipeline_stage IN (
                          'packing',
                          'pending',
                          'packed'
                      )

                      OR (
                          pipeline_stage = 'error'
                          AND error_stage = 'package'
                      )
                  )

                ORDER BY asset_type ASC
                SQL
            );


        return array_values(
            array_map(
                'strval',

                $stmt->fetchAll(
                    PDO::FETCH_COLUMN
                ) ?: []
            )
        );
    }


    /**
     * Current in-house candidates for one source/type combination.
     *
     * ANALYZE owns logical-subject matching. The repository only returns
     * durable candidate rows plus their currently filed Creator ingredients.
     *
     * IMPORTANT:
     *   - sort_order is returned as current metadata only; it is NOT identity.
     *   - shipping/shipped/dispatched/published rows are excluded. Once an
     *     asset has left the in-house workflow, re-analysis starts fresh.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listInHouseLogicalAssetCandidates(
        string $sourceType,
        int $sourceId,
        string $assetType
    ): array {
        $sourceType =
            strtolower(
                trim(
                    $sourceType
                )
            );

        $assetType =
            strtolower(
                trim(
                    $assetType
                )
            );


        if ($sourceType === '') {
            throw new RuntimeException(
                'Logical asset candidate lookup requires source_type.'
            );
        }


        if ($sourceId <= 0) {
            throw new RuntimeException(
                'Logical asset candidate lookup requires a valid source_id.'
            );
        }


        if ($assetType === '') {
            throw new RuntimeException(
                'Logical asset candidate lookup requires asset_type.'
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT
                    a.pub_asset_id,
                    a.pub_run_id,

                    a.channel,
                    a.asset_type,
                    a.creator_key,

                    a.source_type,
                    a.source_id,
                    a.sort_order,

                    a.pipeline_stage,
                    a.approved,

                    a.search_title,
                    a.description,
                    a.pingback,

                    a.created_at,
                    a.updated_at,

                    o.ingredients AS order_ingredients

                FROM pub_assets a

                LEFT JOIN pub_asset_orders o
                  ON o.pub_asset_id = a.pub_asset_id

                WHERE a.source_type =
                    :source_type

                  AND a.source_id =
                    :source_id

                  AND a.asset_type =
                    :asset_type

                  AND a.pipeline_stage NOT IN (
                      'shipping',
                      'shipped',
                      'dispatched',
                      'published'
                  )

                ORDER BY a.pub_asset_id DESC
                SQL
            );


        $stmt->execute([
            'source_type' =>
                $sourceType,

            'source_id' =>
                $sourceId,

            'asset_type' =>
                $assetType,
        ]);


        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];


        $result = [];


        foreach (
            $rows
            as $row
        ) {
            $rawIngredients =
                $row[
                    'order_ingredients'
                ]
                ?? null;

            $ingredients = [];


            if (
                is_string(
                    $rawIngredients
                )
                && trim(
                    $rawIngredients
                ) !== ''
            ) {
                $decoded =
                    json_decode(
                        $rawIngredients,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );


                if (!is_array($decoded)) {
                    throw new RuntimeException(
                        'PUB asset #'
                        . (int)(
                            $row[
                                'pub_asset_id'
                            ]
                            ?? 0
                        )
                        . ' contains invalid filed ingredients.'
                    );
                }


                $ingredients =
                    $decoded;
            }


            unset(
                $row[
                    'order_ingredients'
                ]
            );


            $row[
                'ingredients'
            ] =
                $ingredients;


            $result[] =
                $row;
        }


        return $result;
    }


    /**
     * Prior rows for one logical asset combination.
     *
     * Logical identity is:
     *
     *   source_type
     *   source_id
     *   asset_type
     *   sort_order
     *
     * sort_order prevents sibling outputs of the same type from being
     * mistaken for one another.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listLogicalAssetHistory(
        string $sourceType,
        int $sourceId,
        string $assetType,
        ?int $sortOrder
    ): array {
        $sourceType =
            strtolower(
                trim(
                    $sourceType
                )
            );

        $assetType =
            strtolower(
                trim(
                    $assetType
                )
            );


        if ($sourceType === '') {
            throw new RuntimeException(
                'Logical asset lookup requires source_type.'
            );
        }


        if ($sourceId <= 0) {
            throw new RuntimeException(
                'Logical asset lookup requires a valid source_id.'
            );
        }


        if ($assetType === '') {
            throw new RuntimeException(
                'Logical asset lookup requires asset_type.'
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT
                    pub_asset_id,
                    pub_run_id,

                    channel,
                    asset_type,
                    creator_key,

                    source_type,
                    source_id,
                    sort_order,

                    pipeline_stage,
                    approved,

                    search_title,
                    description,
                    pingback,

                    created_at,
                    updated_at,
                    dispatched_at

                FROM pub_assets

                WHERE source_type =
                    :source_type

                  AND source_id =
                    :source_id

                  AND asset_type =
                    :asset_type

                  AND (
                      sort_order <=> :sort_order
                  )

                ORDER BY pub_asset_id DESC
                SQL
            );


        $stmt->bindValue(
            ':source_type',
            $sourceType,
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ':source_id',
            $sourceId,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ':asset_type',
            $assetType,
            PDO::PARAM_STR
        );


        if ($sortOrder === null) {
            $stmt->bindValue(
                ':sort_order',
                null,
                PDO::PARAM_NULL
            );

        } else {
            $stmt->bindValue(
                ':sort_order',
                $sortOrder,
                PDO::PARAM_INT
            );
        }


        $stmt->execute();


        return array_values(
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: []
        );
    }



    /**
     * Permanent production history for duplicate protection.
     *
     * CREATE uses this before NEW production begins.
     *
     * Only successfully SHIPPED assets participate. The temporary
     * pub_asset_orders row may already be gone; production_signature
     * is the permanent fingerprint of the final ingredients that
     * produced what actually left PUB.
     *
     * @return array<int, array{
     *   pub_asset_id: int,
     *   production_signature: string
     * }>
     */
    public function listShippedProductionSignatures(
        string $sourceType,
        int $sourceId,
        string $assetType
    ): array {
        $sourceType =
            strtolower(
                trim(
                    $sourceType
                )
            );

        $assetType =
            strtolower(
                trim(
                    $assetType
                )
            );


        if ($sourceType === '') {
            throw new RuntimeException(
                'Shipped production-signature lookup requires source_type.'
            );
        }

        if ($sourceId <= 0) {
            throw new RuntimeException(
                'Shipped production-signature lookup requires a valid source_id.'
            );
        }

        if ($assetType === '') {
            throw new RuntimeException(
                'Shipped production-signature lookup requires asset_type.'
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT
                    pub_asset_id,
                    production_signature

                FROM pub_assets

                WHERE source_type =
                    :source_type

                  AND source_id =
                    :source_id

                  AND asset_type =
                    :asset_type

                  AND pipeline_stage =
                    'shipped'

                  AND production_signature IS NOT NULL

                  AND TRIM(
                      production_signature
                  ) <> ''

                ORDER BY pub_asset_id ASC
                SQL
            );


        $stmt->execute([
            'source_type' =>
                $sourceType,

            'source_id' =>
                $sourceId,

            'asset_type' =>
                $assetType,
        ]);


        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];


        $result = [];


        foreach (
            $rows
            as $row
        ) {
            $signature =
                strtolower(
                    trim(
                        (string)(
                            $row[
                                'production_signature'
                            ]
                            ?? ''
                        )
                    )
                );


            /*
             * Ignore malformed historical values rather than treating
             * them as valid duplicate evidence.
             */
            if (
                !preg_match(
                    '/^[a-f0-9]{64}$/',
                    $signature
                )
            ) {
                continue;
            }


            $result[] = [
                'pub_asset_id' =>
                    (int)$row[
                        'pub_asset_id'
                    ],

                'production_signature' =>
                    $signature,
            ];
        }


        return $result;
    }


    /*
     * ========================================================
     * ASSET — DISPATCH
     * ========================================================
     */

    /**
     * Accept one sealed QUEUED asset into Dispatch custody.
     *
     * DispatchManager owns this lifecycle transition:
     *
     *   queued -> shipping
     *
     * Scheduler never marks an asset shipping directly. Scheduler makes
     * the release decision, then DispatchManager::shipOne(pub_asset_id)
     * accepts custody and asks this repository to persist it atomically.
     *
     * The package is deliberately preserved unchanged.
     *
     * @return array{
     *   pub_asset_id: int,
     *   pipeline_stage: string
     * }
     */
    public function markShipping(
        int $pubAssetId
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }


        /*
         * Dispatch owns both:
         *
         *   queued -> shipping
         *
         * and the explicit recovery transition:
         *
         *   error / dispatch -> shipping
         *
         * The sealed package is preserved unchanged.
         */
        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_assets
                SET
                    pipeline_stage = 'shipping',
                    stage_note = NULL,

                    error_stage = NULL,
                    error_code = NULL,
                    error_message = NULL,
                    errored_at = NULL

                WHERE pub_asset_id =
                    :pub_asset_id

                  AND `package` IS NOT NULL

                  AND (
                      pipeline_stage = 'queued'

                      OR (
                          pipeline_stage = 'error'
                          AND error_stage = 'dispatch'
                      )
                  )
                SQL
            );


        $stmt->execute([
            'pub_asset_id' =>
                $pubAssetId,
        ]);


        if ($stmt->rowCount() !== 1) {
            $current =
                $this->getById(
                    $pubAssetId
                );


            if ($current === null) {
                throw new RuntimeException(
                    "PUB asset #{$pubAssetId} was not found."
                );
            }


            $stage =
                strtolower(
                    trim(
                        (string)(
                            $current[
                                'pipeline_stage'
                            ]
                            ?? ''
                        )
                    )
                );

            $errorStage =
                strtolower(
                    trim(
                        (string)(
                            $current[
                                'error_stage'
                            ]
                            ?? ''
                        )
                    )
                );

            $isDispatchRetry =
                $stage === 'error'
                && $errorStage === 'dispatch';


            if (
                $stage !== 'queued'
                && !$isDispatchRetry
            ) {
                throw new RuntimeException(
                    "PUB asset #{$pubAssetId} is not ready for Dispatch; expected queued or a Dispatch-stage error."
                );
            }


            throw new RuntimeException(
                "PUB asset #{$pubAssetId} has no sealed package to ship."
            );
        }


        return [
            'pub_asset_id' =>
                $pubAssetId,

            'pipeline_stage' =>
                'shipping',
        ];
    }

    /**
     * DispatchManager's shipping dock.
     *
     * Returns assets already accepted into Dispatch custody at
     * pipeline_stage = shipping. Normal one-box entry is shipOne();
     * this list primarily supports recovery/administrative sweeps.
     *
     * The sealed package is decoded here because DispatchManager
     * hands that exact package to the selected Shipper.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listShipping(): array
    {
        $stmt =
            $this->pdo->query(
                <<<SQL
                SELECT
                    pub_asset_id,
                    pub_run_id,

                    channel,
                    asset_type,

                    source_type,
                    source_id,

                    pipeline_stage,
                    mime_type,

                    `package`

                FROM pub_assets

                WHERE pipeline_stage = 'shipping'

                ORDER BY pub_asset_id ASC
                SQL
            );


        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];


        return array_map(
            fn (
                array $row
            ): array =>
                $this->decodePackageRow(
                    $row
                ),

            $rows
        );
    }


    /**
     * Fetch exactly one asset waiting at the Shipping dock.
     */
    public function getShippingById(
        int $pubAssetId
    ): ?array {
        if ($pubAssetId <= 0) {
            return null;
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT
                    pub_asset_id,
                    pub_run_id,

                    channel,
                    asset_type,

                    source_type,
                    source_id,

                    pipeline_stage,
                    mime_type,

                    `package`

                FROM pub_assets

                WHERE pub_asset_id =
                    :pub_asset_id

                  AND pipeline_stage = 'shipping'

                LIMIT 1
                SQL
            );


        $stmt->execute([
            'pub_asset_id' =>
                $pubAssetId,
        ]);


        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$row) {
            return null;
        }


        return $this->decodePackageRow(
            $row
        );
    }


    /**
     * Persist the receipt returned by a Shipping specialist and
     * close the PUB lifecycle for this asset.
     *
     * The repository does not interpret receipt contents.
     *
     * @return array{
     *   pub_asset_id: int,
     *   pipeline_stage: string,
     *   dispatched_at: string|null
     * }
     */
    public function markShipped(
        int $pubAssetId,
        array $receipt
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }


        if ($receipt === []) {
            throw new RuntimeException(
                'Shipping receipt cannot be empty.'
            );
        }


        $receiptJson =
            json_encode(
                $receipt,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            );


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_assets
                SET
                    shipping_receipt =
                        :shipping_receipt,

                    pipeline_stage =
                        'shipped',

                    stage_note =
                        NULL,

                    dispatched_at =
                        NOW(),

                    error_stage =
                        NULL,

                    error_code =
                        NULL,

                    error_message =
                        NULL,

                    errored_at =
                        NULL

                WHERE pub_asset_id =
                    :pub_asset_id

                  AND pipeline_stage =
                    'shipping'
                SQL
            );


        $stmt->execute([
            'shipping_receipt' =>
                $receiptJson,

            'pub_asset_id' =>
                $pubAssetId,
        ]);


        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} could not be marked shipped."
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT
                    dispatched_at

                FROM pub_assets

                WHERE pub_asset_id =
                    :pub_asset_id

                LIMIT 1
                SQL
            );


        $stmt->execute([
            'pub_asset_id' =>
                $pubAssetId,
        ]);


        $dispatchedAt =
            $stmt->fetchColumn();


        return [
            'pub_asset_id' =>
                $pubAssetId,

            'pipeline_stage' =>
                'shipped',

            'dispatched_at' =>
                $dispatchedAt !== false
                    ? (string)$dispatchedAt
                    : null,
        ];
    }


    /**
     * Finalize one successful shipment with its permanent production
     * signature.
     *
     * DispatchManager owns creation of the signature from the FINAL
     * filed Creator ingredients. The repository only persists the
     * supplied SHA-256 value.
     *
     * This closes the in-house order atomically:
     *
     *   shipping
     *     -> shipped
     *     -> save shipping receipt
     *     -> save production_signature
     *     -> delete pub_asset_orders row
     *
     * The temporary ingredient box is therefore discarded only after
     * the permanent shipped record and signature have been written.
     *
     * Existing markShipped() is deliberately retained for the moment so
     * this repository can be installed before DispatchManager is changed
     * to use this new finalization path.
     *
     * @return array{
     *   pub_asset_id: int,
     *   pipeline_stage: string,
     *   production_signature: string,
     *   dispatched_at: string|null
     * }
     */
    public function finalizeShipment(
        int $pubAssetId,
        array $receipt,
        string $productionSignature
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }


        if ($receipt === []) {
            throw new RuntimeException(
                'Shipping receipt cannot be empty.'
            );
        }


        $productionSignature =
            strtolower(
                trim(
                    $productionSignature
                )
            );


        if (
            !preg_match(
                '/^[a-f0-9]{64}$/',
                $productionSignature
            )
        ) {
            throw new RuntimeException(
                'Production signature must be a SHA-256 hex value.'
            );
        }


        $receiptJson =
            json_encode(
                $receipt,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            );


        $this->pdo
            ->beginTransaction();


        try {
            $stmt =
                $this->pdo->prepare(
                    <<<SQL
                    UPDATE pub_assets
                    SET
                        shipping_receipt =
                            :shipping_receipt,

                        production_signature =
                            :production_signature,

                        pipeline_stage =
                            'shipped',

                        stage_note =
                            NULL,

                        dispatched_at =
                            NOW(),

                        error_stage =
                            NULL,

                        error_code =
                            NULL,

                        error_message =
                            NULL,

                        errored_at =
                            NULL

                    WHERE pub_asset_id =
                        :pub_asset_id

                      AND pipeline_stage =
                        'shipping'
                    SQL
                );


            $stmt->execute([
                'shipping_receipt' =>
                    $receiptJson,

                'production_signature' =>
                    $productionSignature,

                'pub_asset_id' =>
                    $pubAssetId,
            ]);


            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    "PUB asset #{$pubAssetId} could not be finalized as shipped."
                );
            }


            /*
             * The permanent signature now represents the exact Creator
             * ingredients that produced what left the building. The
             * temporary kitchen ticket is no longer needed.
             */
            $this->deleteOrder(
                $pubAssetId
            );


            $stmt =
                $this->pdo->prepare(
                    <<<SQL
                    SELECT
                        dispatched_at

                    FROM pub_assets

                    WHERE pub_asset_id =
                        :pub_asset_id

                    LIMIT 1
                    SQL
                );


            $stmt->execute([
                'pub_asset_id' =>
                    $pubAssetId,
            ]);


            $dispatchedAt =
                $stmt->fetchColumn();


            $this->pdo
                ->commit();


            return [
                'pub_asset_id' =>
                    $pubAssetId,

                'pipeline_stage' =>
                    'shipped',

                'production_signature' =>
                    $productionSignature,

                'dispatched_at' =>
                    $dispatchedAt !== false
                        ? (string)$dispatchedAt
                        : null,
            ];

        } catch (Throwable $e) {
            if (
                $this->pdo
                    ->inTransaction()
            ) {
                $this->pdo
                    ->rollBack();
            }


            throw $e;
        }
    }


    /*
     * ========================================================
     * DISPATCH — ADMIN
     * ========================================================
     */

    /**
     * Dispatch workbench summary rows.
     *
     * Includes:
     *   shipping
     *   shipped
     *   DISPATCH-stage errors
     *
     * Large package/receipt JSON is deliberately omitted here.
     * The grid only needs checkmarks and receipt result fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForDispatchAdmin(
        ?string $channel = null,
        ?string $assetType = null
    ): array {
        $where = [
            "("
            . "pipeline_stage IN ('shipping', 'shipped')"
            . " OR (pipeline_stage = 'error' AND error_stage = 'dispatch')"
            . ")",
        ];

        $params = [];


        $channel =
            trim(
                (string)$channel
            );

        $assetType =
            trim(
                (string)$assetType
            );


        if ($channel !== '') {
            $where[] =
                'channel = :channel';

            $params[
                'channel'
            ] =
                $channel;
        }


        if ($assetType !== '') {
            $where[] =
                'asset_type = :asset_type';

            $params[
                'asset_type'
            ] =
                $assetType;
        }


        $sql = <<<SQL
            SELECT
                pub_asset_id,
                pub_run_id,

                channel,
                asset_type,

                source_type,
                source_id,

                pipeline_stage,
                stage_note,

                search_title,

                error_stage,
                error_code,
                error_message,

                CASE
                    WHEN `package` IS NULL THEN 0
                    ELSE 1
                END AS has_package,

                CASE
                    WHEN shipping_receipt IS NULL THEN 0
                    ELSE 1
                END AS has_receipt,

                JSON_UNQUOTE(
                    JSON_EXTRACT(
                        shipping_receipt,
                        '$.external_id'
                    )
                ) AS external_id,

                JSON_UNQUOTE(
                    JSON_EXTRACT(
                        shipping_receipt,
                        '$.external_url'
                    )
                ) AS external_url,

                dispatched_at,
                updated_at

            FROM pub_assets

            WHERE
            SQL;

        $sql .=
            "\n    "
            . implode(
                "\n    AND ",
                $where
            );

        $sql .=
            "\nORDER BY pub_asset_id DESC";


        $stmt =
            $this->pdo->prepare(
                $sql
            );

        $stmt->execute(
            $params
        );


        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        ) ?: [];
    }


    /**
     * One Dispatch drawer detail row.
     *
     * This intentionally includes both sides of the dock:
     *
     *   package
     *     exactly what PUB sent
     *
     *   shipping_receipt
     *     exactly what the external channel returned
     */
    public function getDispatchAdminById(
        int $pubAssetId
    ): ?array {
        if ($pubAssetId <= 0) {
            return null;
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT
                    pub_asset_id,
                    pub_run_id,

                    channel,
                    asset_type,

                    source_type,
                    source_id,

                    pipeline_stage,
                    stage_note,

                    search_title,
                    description,
                    pingback,

                    file_path,
                    url,
                    mime_type,

                    error_stage,
                    error_code,
                    error_message,
                    errored_at,

                    `package`,
                    shipping_receipt,

                    dispatched_at,
                    created_at,
                    updated_at

                FROM pub_assets

                WHERE pub_asset_id =
                    :pub_asset_id

                  AND (
                      pipeline_stage IN (
                          'shipping',
                          'shipped'
                      )

                      OR (
                          pipeline_stage = 'error'
                          AND error_stage = 'dispatch'
                      )
                  )

                LIMIT 1
                SQL
            );


        $stmt->execute([
            'pub_asset_id' =>
                $pubAssetId,
        ]);


        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$row) {
            return null;
        }


        foreach (
            [
                'package',
                'shipping_receipt',
            ]
            as $jsonField
        ) {
            $raw =
                $row[
                    $jsonField
                ]
                ?? null;


            if (
                is_string(
                    $raw
                )
                && trim(
                    $raw
                ) !== ''
            ) {
                $decoded =
                    json_decode(
                        $raw,
                        true
                    );


                if (
                    json_last_error() ===
                    JSON_ERROR_NONE
                    && is_array(
                        $decoded
                    )
                ) {
                    $row[
                        $jsonField
                    ] =
                        $decoded;
                }
            }
        }


        return $row;
    }


    public function listDispatchChannels(): array
    {
        $stmt =
            $this->pdo->query(
                <<<SQL
                SELECT DISTINCT channel

                FROM pub_assets

                WHERE channel IS NOT NULL
                  AND TRIM(channel) <> ''

                  AND (
                      pipeline_stage IN (
                          'shipping',
                          'shipped'
                      )

                      OR (
                          pipeline_stage = 'error'
                          AND error_stage = 'dispatch'
                      )
                  )

                ORDER BY channel ASC
                SQL
            );


        return array_values(
            array_map(
                'strval',

                $stmt->fetchAll(
                    PDO::FETCH_COLUMN
                ) ?: []
            )
        );
    }


    public function listDispatchAssetTypes(): array
    {
        $stmt =
            $this->pdo->query(
                <<<SQL
                SELECT DISTINCT asset_type

                FROM pub_assets

                WHERE asset_type IS NOT NULL
                  AND TRIM(asset_type) <> ''

                  AND (
                      pipeline_stage IN (
                          'shipping',
                          'shipped'
                      )

                      OR (
                          pipeline_stage = 'error'
                          AND error_stage = 'dispatch'
                      )
                  )

                ORDER BY asset_type ASC
                SQL
            );


        return array_values(
            array_map(
                'strval',

                $stmt->fetchAll(
                    PDO::FETCH_COLUMN
                ) ?: []
            )
        );
    }


    /*
     * ========================================================
     * ASSET — ADMIN
     * ========================================================
     */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForAdmin(
        ?string $channel = null,
        ?string $assetType = null,
        ?string $stage = null,
        ?string $sourceType = null,
        ?int $sourceId = null
    ): array {
        $where = [];
        $params = [];

        $channel =
            trim(
                (string)$channel
            );

        $assetType =
            trim(
                (string)$assetType
            );

        $stage =
            strtolower(
                trim(
                    (string)$stage
                )
            );

        $sourceType =
            strtolower(
                trim(
                    (string)$sourceType
                )
            );

        $sourceId =
            $sourceId !== null
                ? (int)$sourceId
                : null;

        if ($channel !== '') {
            $where[] =
                'a.channel = :channel';

            $params['channel'] =
                $channel;
        }

        if ($assetType !== '') {
            $where[] =
                'a.asset_type = :asset_type';

            $params['asset_type'] =
                $assetType;
        }

        if ($stage !== '') {
            if ($stage === 'approved') {
                $where[] =
                    "(a.pipeline_stage = 'created' AND a.approved = 1)";
            } elseif ($stage === 'created') {
                $where[] =
                    "(a.pipeline_stage = 'created' AND a.approved = 0)";
            } else {
                $where[] =
                    'a.pipeline_stage = :pipeline_stage';

                $params['pipeline_stage'] =
                    $stage;
            }
        }

        if ($sourceType !== '') {
            $where[] =
                'a.source_type = :source_type';

            $params['source_type'] =
                $sourceType;
        }

        if (
            $sourceId !== null
            && $sourceId > 0
        ) {
            $where[] =
                'a.source_id = :source_id';

            $params['source_id'] =
                $sourceId;
        }

        $sql = <<<SQL
            SELECT
                a.pub_asset_id,
                a.pub_run_id,

                a.channel,
                a.asset_type,
                a.creator_key,

                a.source_type,
                a.source_id,
                p.title AS source_title,

                a.pipeline_stage,
                a.approved,

                CASE
                    WHEN a.pipeline_stage = 'created'
                     AND a.approved = 1
                        THEN 'approved'
                    ELSE a.pipeline_stage
                END AS display_stage,

                a.search_title,
                a.description,

                a.file_path,
                a.url,
                a.mime_type,

                a.error_message,

                a.updated_at,
                a.dispatched_at

            FROM pub_assets a

            LEFT JOIN playlists p
              ON a.source_type = 'playlist'
             AND p.playlist_id = a.source_id
            SQL;

        if ($where) {
            $sql .=
                "\nWHERE "
                . implode(
                    ' AND ',
                    $where
                );
        }

        $sql .=
            "\nORDER BY a.pub_asset_id DESC";

        $stmt =
            $this->pdo->prepare(
                $sql
            );

        $stmt->execute(
            $params
        );

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        ) ?: [];
    }


    /**
     * Playlist sources currently represented by durable PUB assets.
     *
     * Used by the Assets workbench so one source Playlist can be
     * reviewed as a mixed cross-channel batch.
     *
     * @return array<int, array{playlist_id: int, title: string}>
     */
    public function listSourcePlaylists(): array
    {
        $stmt =
            $this->pdo->query(
                <<<SQL
                SELECT DISTINCT
                    a.source_id AS playlist_id,
                    p.title

                FROM pub_assets a

                INNER JOIN playlists p
                  ON p.playlist_id = a.source_id

                WHERE a.source_type = 'playlist'
                  AND a.source_id > 0

                ORDER BY p.title ASC, a.source_id ASC
                SQL
            );

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        return array_values(
            array_map(
                static fn (
                    array $row
                ): array => [
                    'playlist_id' =>
                        (int)(
                            $row['playlist_id']
                            ?? 0
                        ),

                    'title' =>
                        (string)(
                            $row['title']
                            ?? ''
                        ),
                ],

                $rows
            )
        );
    }


    public function listChannels(): array
    {
        $stmt =
            $this->pdo->query(
                <<<SQL
                SELECT DISTINCT channel

                FROM pub_assets

                WHERE channel IS NOT NULL
                  AND TRIM(channel) <> ''

                ORDER BY channel ASC
                SQL
            );

        return array_values(
            array_map(
                'strval',

                $stmt->fetchAll(
                    PDO::FETCH_COLUMN
                ) ?: []
            )
        );
    }


    public function listAssetTypes(): array
    {
        $stmt =
            $this->pdo->query(
                <<<SQL
                SELECT DISTINCT asset_type

                FROM pub_assets

                WHERE asset_type IS NOT NULL
                  AND TRIM(asset_type) <> ''

                ORDER BY asset_type ASC
                SQL
            );

        return array_values(
            array_map(
                'strval',

                $stmt->fetchAll(
                    PDO::FETCH_COLUMN
                ) ?: []
            )
        );
    }



    public function listStages(): array
    {
        $stmt =
            $this->pdo->query(
                <<<SQL
                SELECT DISTINCT
                    CASE
                        WHEN pipeline_stage = 'created'
                         AND approved = 1
                            THEN 'approved'
                        ELSE pipeline_stage
                    END AS display_stage

                FROM pub_assets

                WHERE pipeline_stage IS NOT NULL
                  AND TRIM(pipeline_stage) <> ''

                ORDER BY display_stage ASC
                SQL
            );


        return array_values(
            array_map(
                'strval',

                $stmt->fetchAll(
                    PDO::FETCH_COLUMN
                ) ?: []
            )
        );
    }


    public function getById(
        int $pubAssetId
    ): ?array {
        if ($pubAssetId <= 0) {
            return null;
        }

        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT
                    a.pub_asset_id,
                    a.pub_run_id,

                    a.channel,
                    a.asset_type,
                    a.creator_key,

                    a.source_type,
                    a.source_id,
                    a.sort_order,
                    p.title AS source_title,

                    a.pipeline_stage,
                    a.approved,

                    CASE
                        WHEN a.pipeline_stage = 'created'
                         AND a.approved = 1
                            THEN 'approved'
                        ELSE a.pipeline_stage
                    END AS display_stage,

                    a.error_stage,
                    a.error_code,
                    a.error_message,
                    a.errored_at,

                    a.search_title,
                    a.description,
                    a.pingback,

                    a.file_path,
                    a.url,
                    a.thumbnail_file_path,
                    a.thumbnail_url,
                    a.mime_type,
                    a.duration_ms,

                    a.created_at,
                    a.updated_at,
                    a.dispatched_at

                FROM pub_assets a

                LEFT JOIN playlists p
                  ON a.source_type = 'playlist'
                 AND p.playlist_id = a.source_id

                WHERE a.pub_asset_id =
                    :pub_asset_id

                LIMIT 1
                SQL
            );

        $stmt->execute([
            'pub_asset_id' =>
                $pubAssetId,
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return $row ?: null;
    }


    /**
     * Explicit human approval checkpoint.
     *
     * Approval is deliberately separate from pipeline_stage:
     *
     *   created + approved = 0  -> CREATED
     *   created + approved = 1  -> APPROVED
     *
     * The checkbox can be changed only while the current physical
     * asset is fully created and has not yet entered Package.
     *
     * @return array{
     *   pub_asset_id: int,
     *   approved: bool,
     *   pipeline_stage: string,
     *   display_stage: string
     * }
     */
    public function setApproved(
        int $pubAssetId,
        bool $approved
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }


        $asset =
            $this->getById(
                $pubAssetId
            );


        if ($asset === null) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} was not found."
            );
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


        if ($stage !== 'created') {
            throw new RuntimeException(
                'Approval can only be changed while an asset is at CREATED.'
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE pub_assets
                SET
                    approved = :approved

                WHERE pub_asset_id =
                    :pub_asset_id

                  AND pipeline_stage =
                    'created'
                SQL
            );


        $stmt->execute([
            'approved' =>
                $approved
                    ? 1
                    : 0,

            'pub_asset_id' =>
                $pubAssetId,
        ]);


        $current =
            $this->getById(
                $pubAssetId
            );


        if (
            $current === null
            || strtolower(
                trim(
                    (string)(
                        $current[
                            'pipeline_stage'
                        ]
                        ?? ''
                    )
                )
            ) !== 'created'
        ) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} approval could not be changed."
            );
        }


        $currentApproved =
            (int)(
                $current[
                    'approved'
                ]
                ?? 0
            ) === 1;


        if ($currentApproved !== $approved) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} approval could not be changed."
            );
        }


        return [
            'pub_asset_id' =>
                $pubAssetId,

            'approved' =>
                $currentApproved,

            'pipeline_stage' =>
                'created',

            'display_stage' =>
                $currentApproved
                    ? 'approved'
                    : 'created',
        ];
    }


    /*
     * ========================================================
     * ASSET — DELETE
     * ========================================================
     */

    /**
     * Permanently delete an in-house asset.
     *
     * This removes:
     *
     *   pub_asset_orders
     *   pub_assets
     *
     * Physical file deletion remains the API's job.
     */
    public function deleteUnsent(
        int $pubAssetId,
        bool $allowHistoricalDelete = false
    ): void {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Valid pub_asset_id required.'
            );
        }

        $asset =
            $this->getById(
                $pubAssetId
            );

        if ($asset === null) {
            throw new RuntimeException(
                'PUB asset not found.'
            );
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


        if (
            $historicalStage
            && !$allowHistoricalDelete
        ) {
            throw new RuntimeException(
                'Shipped, dispatched, or published assets require explicit permanent-delete authorization.'
            );
        }


        if (
            !$historicalStage
        ) {
            $this->assertEditableStage(
                $asset
            );
        }


        $this->pdo
            ->beginTransaction();

        try {
            /*
             * Throw away the kitchen ticket first.
             */
            $this->deleteOrder(
                $pubAssetId
            );


            /*
             * Then delete the asset record.
             */
            $stmt =
                $this->pdo->prepare(
                    <<<SQL
                    DELETE FROM pub_assets

                    WHERE pub_asset_id =
                        :pub_asset_id

                      AND (
                          :allow_historical_delete = 1

                          OR pipeline_stage NOT IN (
                              'shipped',
                              'dispatched',
                              'published'
                          )
                      )
                    SQL
                );

            $stmt->execute([
                'pub_asset_id' =>
                    $pubAssetId,

                'allow_historical_delete' =>
                    $allowHistoricalDelete
                        ? 1
                        : 0,
            ]);

            if (
                $stmt->rowCount() !== 1
            ) {
                throw new RuntimeException(
                    'PUB asset could not be deleted.'
                );
            }

            $this->pdo
                ->commit();

        } catch (Throwable $e) {
            if (
                $this->pdo
                    ->inTransaction()
            ) {
                $this->pdo
                    ->rollBack();
            }

            throw $e;
        }
    }


    /*
     * ========================================================
     * INTERNAL HELPERS
     * ========================================================
     */

    /**
     * Compare the durable outside-of-box replacement metadata that must
     * agree before an UPDATE no-op can be treated as idempotent.
     */
    private function replacementMetadataMatches(
        array $current,
        array $incoming
    ): bool {
        $currentSortOrder =
            isset(
                $current[
                    'sort_order'
                ]
            )
            && $current[
                'sort_order'
            ] !== null
                ? (int)$current[
                    'sort_order'
                ]
                : null;

        $incomingSortOrder =
            isset(
                $incoming[
                    'sort_order'
                ]
            )
            && $incoming[
                'sort_order'
            ] !== null
                ? (int)$incoming[
                    'sort_order'
                ]
                : null;


        return
            (int)(
                $current[
                    'pub_run_id'
                ]
                ?? 0
            ) ===
            (int)(
                $incoming[
                    'pub_run_id'
                ]
                ?? 0
            )

            && strtolower(
                trim(
                    (string)(
                        $current[
                            'channel'
                        ]
                        ?? ''
                    )
                )
            ) ===
            strtolower(
                trim(
                    (string)(
                        $incoming[
                            'channel'
                        ]
                        ?? ''
                    )
                )
            )

            && strtolower(
                trim(
                    (string)(
                        $current[
                            'asset_type'
                        ]
                        ?? ''
                    )
                )
            ) ===
            strtolower(
                trim(
                    (string)(
                        $incoming[
                            'asset_type'
                        ]
                        ?? ''
                    )
                )
            )

            && trim(
                (string)(
                    $current[
                        'creator_key'
                    ]
                    ?? ''
                )
            ) ===
            trim(
                (string)(
                    $incoming[
                        'creator_key'
                    ]
                    ?? ''
                )
            )

            && strtolower(
                trim(
                    (string)(
                        $current[
                            'source_type'
                        ]
                        ?? ''
                    )
                )
            ) ===
            strtolower(
                trim(
                    (string)(
                        $incoming[
                            'source_type'
                        ]
                        ?? ''
                    )
                )
            )

            && (int)(
                $current[
                    'source_id'
                ]
                ?? 0
            ) ===
            (int)(
                $incoming[
                    'source_id'
                ]
                ?? 0
            )

            && $currentSortOrder ===
                $incomingSortOrder

            && $this->nullableString(
                $current[
                    'search_title'
                ]
                ?? null
            ) ===
            $this->nullableString(
                $incoming[
                    'search_title'
                ]
                ?? null
            )

            && $this->nullableString(
                $current[
                    'description'
                ]
                ?? null
            ) ===
            $this->nullableString(
                $incoming[
                    'description'
                ]
                ?? null
            )

            && $this->nullableString(
                $current[
                    'pingback'
                ]
                ?? null
            ) ===
            $this->nullableString(
                $incoming[
                    'pingback'
                ]
                ?? null
            );
    }


    private function assertEditableStage(
        array $asset
    ): void {
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
                    'shipped',
                    'dispatched',
                    'published',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Shipped, dispatched, or published assets cannot be changed.'
            );
        }
    }


    private function decodePackageRow(
        array $row
    ): array {
        $raw =
            $row[
                'package'
            ]
            ?? null;


        if (is_array($raw)) {
            return $row;
        }


        if (
            !is_string(
                $raw
            )
            || trim(
                $raw
            ) === ''
        ) {
            $row[
                'package'
            ] =
                null;

            return $row;
        }


        $decoded =
            json_decode(
                $raw,
                true
            );


        if (
            json_last_error() !==
                JSON_ERROR_NONE
            || !is_array(
                $decoded
            )
        ) {
            throw new RuntimeException(
                'PUB asset contains invalid package JSON.'
            );
        }


        $row[
            'package'
        ] =
            $decoded;


        return $row;
    }


    private function nullableString(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $text =
            trim(
                (string)$value
            );

        return $text !== ''
            ? $text
            : null;
    }


private function setNestedValue(
    array $source,
    string $path,
    mixed $value
): array {
    $parts =
        array_values(
            array_filter(
                array_map(
                    'trim',
                    explode(
                        '.',
                        $path
                    )
                ),

                static fn (
                    string $part
                ): bool =>
                    $part !== ''
            )
        );


    if ($parts === []) {
        return $source;
    }


    $cursor =
        &$source;


    foreach (
        $parts
        as $index => $part
    ) {
        $isLast =
            $index ===
            count($parts) - 1;


        if ($isLast) {
            $cursor[
                $part
            ] =
                $value;

            break;
        }


        if (
            !isset(
                $cursor[
                    $part
                ]
            )
            ||
            !is_array(
                $cursor[
                    $part
                ]
            )
        ) {
            $cursor[
                $part
            ] =
                [];
        }


        $cursor =
            &$cursor[
                $part
            ];
    }


    unset(
        $cursor
    );


    return $source;
}

}