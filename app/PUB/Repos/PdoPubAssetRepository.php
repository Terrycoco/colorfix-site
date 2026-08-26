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
 * Every created asset has exactly two locations:
 *
 *   file_path
 *     Full physical server path.
 *
 *   url
 *     Browser-facing URL.
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


        if ($assetUpdates) {
            $sql =
                'UPDATE pub_assets SET '
                . implode(
                    ', ',
                    $assetUpdates
                )
                . ' WHERE pub_asset_id = :pub_asset_id'
                . " AND pipeline_stage NOT IN ('dispatched', 'published')";


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
                : (string)(
                    $asset[
                        'pipeline_stage'
                    ]
                    ?? ''
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

                    error_stage = NULL,
                    error_code = NULL,
                    error_message = NULL,
                    errored_at = NULL

                WHERE pub_asset_id =
                    :pub_asset_id

                  AND pipeline_stage NOT IN (
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
                    mime_type = :mime_type,

                    width = :width,
                    height = :height,
                    duration_ms = :duration_ms,

                    file_size_bytes = :file_size_bytes,
                    checksum = :checksum,

                    pipeline_stage = 'created',
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
     * ASSET — ADMIN
     * ========================================================
     */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForAdmin(
        ?string $channel = null,
        ?string $assetType = null
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

        if ($channel !== '') {
            $where[] =
                'channel = :channel';

            $params['channel'] =
                $channel;
        }

        if ($assetType !== '') {
            $where[] =
                'asset_type = :asset_type';

            $params['asset_type'] =
                $assetType;
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

                pipeline_stage,

                search_title,
                description,

                file_path,
                url,
                mime_type,

                error_message,

                updated_at,
                dispatched_at,
                published_at

            FROM pub_assets
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
                    pub_asset_id,
                    pub_run_id,

                    channel,
                    asset_type,
                    creator_key,

                    source_type,
                    source_id,

                    pipeline_stage,

                    search_title,
                    description,
                    pingback,

                    file_path,
                    url,
                    mime_type,
                    duration_ms,

                    created_at,
                    updated_at,
                    dispatched_at,
                    published_at

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

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return $row ?: null;
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
        int $pubAssetId
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

        $this->assertEditableStage(
            $asset
        );

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

                      AND pipeline_stage NOT IN (
                          'dispatched',
                          'published'
                      )
                    SQL
                );

            $stmt->execute([
                'pub_asset_id' =>
                    $pubAssetId,
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
                    'dispatched',
                    'published',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Dispatched or published assets cannot be changed.'
            );
        }
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