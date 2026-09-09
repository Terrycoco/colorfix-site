<?php
declare(strict_types=1);

namespace App\PUB\Create;

use App\PUB\Create\Pinterest\BeforeAfterVideoCreator;
use App\PUB\Create\Pinterest\CompositeCreator;
use App\PUB\Create\Pinterest\IdeaCreator;
use App\PUB\Create\Pinterest\PaletteCreator;
use App\PUB\Create\Pinterest\TeaserCreator;
use App\PUB\Create\Pinterest\Support\PinterestCreatorTools;
use App\PUB\Create\Video\PdoVideoJobRepository;
use App\PUB\Create\Video\VideoWorkerHealthService;
use App\PUB\Create\YouTube\PlaylistVideoCreator;
use App\PUB\Errors\PubErrorReporter;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComDisposition;
use App\PUB\PubCom\PubComManagerContract;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use App\PUB\Repos\PdoPubAssetRepository;
use App\PUB\Repos\PdoPubRunRepository;
use App\PUB\Services\PubRunService;
use PDO;
use RuntimeException;
use Throwable;

/**
 * CREATE MANAGER
 *
 * Department head for every CREATE order.
 *
 * It accepts:
 *
 * NEW ORDER
 *   box present
 *   pub_asset_id absent
 *
 * REDO ORDER
 *   pub_asset_id present
 *   box absent
 *
 * For NEW work the Manager receives the complete
 * inter-department Box, retains its asset metadata,
 * files the Creator ingredients, and routes only
 * those ingredients to the specialist Creator.
 *
 * For REDO work the Manager reloads the filed
 * ingredients and routes them to the same Creator.
 *
 * The Manager owns:
 *
 *   - NEW vs REDO interpretation
 *   - duplicate-production gate for NEW work
 *   - asset reservation
 *   - filed CREATE orders
 *   - Creator assignment
 *   - departmental PubCom decisions
 *   - batch continuation
 *   - centralized CREATE failure reporting
 *
 * The Creator does NOT:
 *
 *   - reserve IDs
 *   - file orders
 *   - determine whether work is new or redo
 *   - fetch missing ingredients
 *   - package
 *   - schedule
 *   - publish
 */
final class CreateManager implements PubComManagerContract
{
    private PubErrorReporter $errors;
    private PdoPubAssetRepository $assets;

    /*
     * LAZY DEPARTMENT STAFF / EQUIPMENT.
     *
     * Nothing below is created merely because CreateManager woke up.
     * The Manager wakes only the station required by the current order,
     * and keeps that instance available for the rest of this request/batch.
     */
    private array $creators = [];
    private ?PinterestCreatorTools $pinterestTools = null;
    private ?PdoVideoJobRepository $videoJobs = null;
    private ?VideoWorkerHealthService $videoWorkerHealth = null;
    private ?PubRunService $runService = null;
    private ?string $publicBaseUrl = null;


    public function __construct(
        private PDO $pdo,
        private string $projectRoot,
    ) {
        $this->projectRoot =
            rtrim(
                trim(
                    $this->projectRoot
                ),
                DIRECTORY_SEPARATOR
            );


        if ($this->projectRoot === '') {
            throw new RuntimeException(
                'Create Manager requires project root.'
            );
        }


        /*
         * Every CREATE assignment needs the durable asset/order desk,
         * so this is Manager infrastructure rather than specialist staff.
         */
        $this->assets =
            new PdoPubAssetRepository(
                $this->pdo
            );


        $this->errors =
            new PubErrorReporter(
                $this->projectRoot
                . '/app/PUB/Errors/pub_errors.log'
            );
    }


    /**
     * PUBCOM MANAGER READINESS
     *
     * CREATE currently has no separate manager-level
     * dependency health probe.
     *
     * If this object has been successfully constructed,
     * the department Manager is available.
     */
    public function readiness(): PubComSignal
    {
        return PubComSignal::ready(
            'Create Manager is ready.',
            [
                'department' =>
                    'create',

                'manager' =>
                    self::class,
            ]
        );
    }


    /**
     * PUBCOM SIGNAL TRIAGE
     *
     * Workers report WHAT happened.
     *
     * The Create Manager decides WHAT TO DO.
     *
     * CREATE processes independent production units.
     * Therefore an individual ineligible assignment
     * may be skipped while sibling orders continue.
     *
     * A worker that is unavailable stops that production
     * line because additional work cannot be sent to it.
     */
    public function handleSignal(
        PubComSignal $signal
    ): PubComDisposition {
        if ($signal->isReady()) {
            return PubComDisposition::make(
                $signal,
                PubComDisposition::CONTINUE,
                PubComDisposition::DISPLAY_NONE,
                false
            );
        }


        if ($signal->isNotice()) {
            return PubComDisposition::make(
                $signal,
                PubComDisposition::CONTINUE,
                PubComDisposition::DISPLAY_TOAST,
                true
            );
        }


        if ($signal->isIneligible()) {
            return PubComDisposition::make(
                $signal,
                PubComDisposition::SKIP_UNIT,
                PubComDisposition::DISPLAY_TOAST,
                true
            );
        }


        if ($signal->isUnavailable()) {
            return PubComDisposition::make(
                $signal,
                PubComDisposition::STOP_LINE,
                PubComDisposition::DISPLAY_POPUP,
                true
            );
        }


        throw new RuntimeException(
            "CREATE received an unsupported PubCom signal type '{$signal->type()}'."
        );
    }


    /**
     * Create a batch of NEW assets from sealed
     * ANALYZE boxes.
     *
     * Each box becomes one CREATE order.
     *
     * @param array<int, array<string, mixed>> $boxes
     *
     * @return array{
     *   created: array<int, array<string, mixed>>,
     *   queued: array<int, array<string, mixed>>,
     *   failed: array<int, array<string, mixed>>
     * }
     *
     * Each created / failed entry may also carry
     * PubCom dispositions for that production unit.
     */
    public function createBatch(
        array $boxes,
        array|string $existingPolicy = 'check'
    ): array {
        $orders = [];


        foreach (
            $boxes
            as $box
        ) {
            $orders[] = [
                'box' =>
                    $box,
            ];
        }


        return $this->processBatch(
            $orders,
            $existingPolicy
        );
    }


    /**
     * Process arbitrary CREATE orders.
     *
     * Each order must contain EITHER:
     *
     *   box
     *
     * OR:
     *
     *   pub_asset_id
     *
     * but not both.
     *
     * Existing-policy shape:
     *
     *   [
     *     'unshipped' => 'check' | 'replace',
     *     'shipped'   => 'check' | 'new_version',
     *   ]
     *
     * @param array<int, array<string, mixed>> $orders
     */
    public function processBatch(
        array $orders,
        array|string $existingPolicy = 'check'
    ): array {
        $existingPolicy =
            $this->normalizeExistingPolicy(
                $existingPolicy
            );


        /*
         * ========================================================
         * LOGICAL EXISTING-ASSET GATE
         * ========================================================
         *
         * Runs before any Creator wakes, any ID is reserved/reused,
         * or any lifecycle state changes.
         *
         * This is intentionally NOT an exact production-signature
         * comparison. A changed source may still be a new version of
         * the same logical asset.
         */
        $existingCheck =
            $this->inspectExistingNewOrders(
                $orders
            );


        $existingMatches =
            $existingCheck[
                'matches'
            ];


        $existingByOrderIndex = [];


        foreach (
            $existingMatches
            as $match
        ) {
            $existingByOrderIndex[
                (int)$match[
                    'order_index'
                ]
            ] =
                $match;
        }


        $unresolvedMatches =
            array_values(
                array_filter(
                    $existingMatches,

                    static function (
                        array $match
                    ) use (
                        $existingPolicy
                    ): bool {
                        $state =
                            (string)(
                                $match[
                                    'existing_state'
                                ]
                                ?? ''
                            );


                        return (
                            $state === 'unshipped'
                            && (
                                $existingPolicy[
                                    'unshipped'
                                ]
                                ?? 'check'
                            ) === 'check'
                        )
                        || (
                            $state === 'shipped'
                            && (
                                $existingPolicy[
                                    'shipped'
                                ]
                                ?? 'check'
                            ) === 'check'
                        );
                    }
                )
            );


        if ($unresolvedMatches !== []) {
            $unshippedCount =
                count(
                    array_filter(
                        $unresolvedMatches,

                        static fn(
                            array $match
                        ): bool =>
                            (
                                $match[
                                    'existing_state'
                                ]
                                ?? ''
                            ) === 'unshipped'
                    )
                );

            $shippedCount =
                count(
                    array_filter(
                        $unresolvedMatches,

                        static fn(
                            array $match
                        ): bool =>
                            (
                                $match[
                                    'existing_state'
                                ]
                                ?? ''
                            ) === 'shipped'
                    )
                );


            return [
                'code' =>
                    'existing_asset_warning',

                'existing_asset_warning' =>
                    true,

                'existing_policy' =>
                    $existingPolicy,

                'match_count' =>
                    count(
                        $unresolvedMatches
                    ),

                'unshipped_count' =>
                    $unshippedCount,

                'shipped_count' =>
                    $shippedCount,

                'matches' =>
                    $unresolvedMatches,

                'created' =>
                    [],

                'queued' =>
                    [],

                'failed' =>
                    [],
            ];
        }


        $created = [];
        $queued = [];
        $failed = [];

        /*
         * A STOP_LINE disposition stops only that specialist
         * production line. Other Creator lines may continue.
         */
        $stoppedLines = [];


        foreach (
            $orders
            as $index => $order
        ) {
            if (
                !is_array(
                    $order
                )
            ) {
                $failed[] = [
                    'index' =>
                        $index,

                    'asset_type' =>
                        null,

                    'pub_asset_id' =>
                        null,

                    'error' =>
                        'CREATE received an invalid order.',
                ];


                continue;
            }


            $resolved = null;
            $pubComChannel = null;


            try {
                /*
                 * ====================================================
                 * NEW ORDER — PREFLIGHT BEFORE RESERVATION
                 * ====================================================
                 *
                 * If ANALYZE already handed us a sealed box, CREATE
                 * knows enough to choose the specialist before we
                 * reserve a permanent pub_asset_id.
                 *
                 * Expected worker conditions therefore do NOT create
                 * useless permanent asset rows.
                 */
                $hasNewBox =
                    is_array(
                        $order[
                            'box'
                        ]
                        ?? null
                    )
                    &&
                    (int)(
                        $order[
                            'pub_asset_id'
                        ]
                        ?? 0
                    ) <= 0;


                if ($hasNewBox) {
                    $box =
                        $order[
                            'box'
                        ];


                    $assetType =
                        $this->assetTypeFromBox(
                            $box
                        );


                    if (
                        isset(
                            $stoppedLines[
                                $assetType
                            ]
                        )
                    ) {
                        $failed[] = [
                            'index' =>
                                $index,

                            'asset_type' =>
                                $assetType,

                            /*
                             * No permanent asset was reserved.
                             */
                            'pub_asset_id' =>
                                null,

                            ...$stoppedLines[
                                $assetType
                            ],
                        ];


                        continue;
                    }


                    $creator =
                        $this->creatorForAssetType(
                            $assetType
                        );


                    $ingredients =
                        $this->ingredientsFromBox(
                            $box
                        );


                    $gate =
                        $this->authorizeCreator(
                            $creator,
                            $ingredients
                        );


                    $pubComChannel =
                        $gate[
                            'channel'
                        ];


                    if (
                        !$gate[
                            'disposition'
                        ]->shouldContinue()
                    ) {
                        $failed[] = [
                            'index' =>
                                $index,

                            'asset_type' =>
                                $assetType,

                            /*
                             * No permanent asset was reserved.
                             */
                            'pub_asset_id' =>
                                null,

                            'error' =>
                                $gate[
                                    'disposition'
                                ]
                                    ->signal()
                                    ->message(),

                            'pubcom' =>
                                $pubComChannel
                                    ->dispositionsAsArray(),
                        ];


                        if (
                            $gate[
                                'disposition'
                            ]->shouldStopLine()
                        ) {
                            $stoppedLines[
                                $assetType
                            ] = [
                                'error' =>
                                    $gate[
                                        'disposition'
                                    ]
                                        ->signal()
                                        ->message(),

                                'pubcom' =>
                                    $pubComChannel
                                        ->dispositionsAsArray(),
                            ];
                        }


                        if (
                            $gate[
                                'disposition'
                            ]->shouldStopJob()
                        ) {
                            break;
                        }


                        continue;
                    }
                }


                /*
                 * MANAGER INTAKE.
                 *
                 * NEW:
                 *   Reserve the asset number and file the order.
                 *
                 * REDO:
                 *   Fetch the existing asset and authoritative
                 *   filed order.
                 */
                $resolved =
                    $this->resolveOrder(
                        $order,

                        $existingByOrderIndex[
                            (int)$index
                        ]
                        ?? null,

                        $existingPolicy
                    );


                /*
                 * ====================================================
                 * REDO ORDER — PREFLIGHT AFTER RESOLUTION
                 * ====================================================
                 *
                 * REDO arrives with only pub_asset_id, so the Manager
                 * must first fetch the filed ingredients. resolveOrder() does
                 * not create a new row for REDO work.
                 */
                if (!$hasNewBox) {
                    $assetType =
                        $resolved[
                            'asset_type'
                        ];


                    if (
                        isset(
                            $stoppedLines[
                                $assetType
                            ]
                        )
                    ) {
                        $failed[] = [
                            'index' =>
                                $index,

                            'asset_type' =>
                                $assetType,

                            'pub_asset_id' =>
                                $resolved[
                                    'pub_asset_id'
                                ],

                            ...$stoppedLines[
                                $assetType
                            ],
                        ];


                        continue;
                    }


                    $creator =
                        $this->creatorForAssetType(
                            $assetType
                        );


                    $gate =
                        $this->authorizeCreator(
                            $creator,
                            $resolved[
                                'ingredients'
                            ]
                        );


                    $pubComChannel =
                        $gate[
                            'channel'
                        ];


                    if (
                        !$gate[
                            'disposition'
                        ]->shouldContinue()
                    ) {
                        $failed[] = [
                            'index' =>
                                $index,

                            'asset_type' =>
                                $assetType,

                            'pub_asset_id' =>
                                $resolved[
                                    'pub_asset_id'
                                ],

                            'error' =>
                                $gate[
                                    'disposition'
                                ]
                                    ->signal()
                                    ->message(),

                            'pubcom' =>
                                $pubComChannel
                                    ->dispositionsAsArray(),
                        ];


                        if (
                            $gate[
                                'disposition'
                            ]->shouldStopLine()
                        ) {
                            $stoppedLines[
                                $assetType
                            ] = [
                                'error' =>
                                    $gate[
                                        'disposition'
                                    ]
                                        ->signal()
                                        ->message(),

                                'pubcom' =>
                                    $pubComChannel
                                        ->dispositionsAsArray(),
                            ];
                        }


                        if (
                            $gate[
                                'disposition'
                            ]->shouldStopJob()
                        ) {
                            break;
                        }


                        continue;
                    }
                }


                /*
                 * IDEMPOTENT REPLACEMENT.
                 *
                 * This exact Box has already been prepared or completed
                 * for this same Analyze run. Treat it as accepted without
                 * waking the Creator a second time.
                 */
                if (
                    !empty(
                        $resolved[
                            'already_prepared'
                        ]
                    )
                ) {
                    $currentAsset =
                        $this->assets
                            ->getById(
                                $resolved[
                                    'pub_asset_id'
                                ]
                            );


                    if ($currentAsset === null) {
                        throw new RuntimeException(
                            "PUB asset #{$resolved['pub_asset_id']} disappeared during idempotent CREATE handling."
                        );
                    }


                    $currentStage =
                        strtolower(
                            trim(
                                (string)(
                                    $currentAsset[
                                        'pipeline_stage'
                                    ]
                                    ?? ''
                                )
                            )
                        );


                    $alreadyQueued =
                        $currentStage ===
                        'creating';


                    $productionEntry = [
                        'index' =>
                            $index,

                        'asset_type' =>
                            $resolved[
                                'asset_type'
                            ],

                        'pub_asset_id' =>
                            $resolved[
                                'pub_asset_id'
                            ],

                        'existing_action' =>
                            $resolved[
                                'existing_action'
                            ]
                            ?? null,

                        'predecessor_pub_asset_id' =>
                            $resolved[
                                'predecessor_pub_asset_id'
                            ]
                            ?? null,

                        'asset' => [
                            'create_status' =>
                                $alreadyQueued
                                    ? 'queued'
                                    : 'created',

                            'idempotent' =>
                                true,
                        ],

                        'persistence' => [
                            'pub_asset_id' =>
                                $resolved[
                                    'pub_asset_id'
                                ],

                            'pipeline_stage' =>
                                $currentStage,

                            'idempotent' =>
                                true,
                        ],

                        'pubcom' =>
                            $pubComChannel
                                ? $pubComChannel
                                    ->dispositionsAsArray()
                                : [],
                    ];


                    if ($alreadyQueued) {
                        $queued[] =
                            $productionEntry;

                    } else {
                        $created[] =
                            $productionEntry;
                    }


                    continue;
                }


                /*
                 * MANAGER AUTHORIZED PRODUCTION.
                 *
                 * Every numbered CREATE order enters the same durable
                 * lifecycle state immediately before production begins.
                 *
                 * NEW reservations already start as "creating"; calling
                 * this again is an intentional idempotent confirmation.
                 *
                 * REDO keeps the existing physical file/url in place while
                 * the replacement is being produced.
                 */
                $this->assets
                    ->markCreating(
                        $resolved[
                            'pub_asset_id'
                        ]
                    );


                /*
                 * The Creator returns either:
                 *
                 *   - one finished physical asset, or
                 *   - an accepted asynchronous order marked queued.
                 */
                $asset =
                    $this->createOne(
                        $resolved[
                            'pub_asset_id'
                        ],

                        $resolved[
                            'asset_type'
                        ],

                        $resolved[
                            'ingredients'
                        ]
                    );


                /*
                 * OFFICIAL ASSET PERSISTENCE.
                 *
                 * The Manager hands the entire finished Creator result
                 * to the repository untouched. That result may contain
                 * the primary physical asset plus product-owned companion
                 * output such as thumbnail_file_path / thumbnail_url.
                 *
                 * The repository owns the pub_assets schema mapping.
                 */
                $persistence =
                    $this->persistFinishedAsset(
                        $resolved[
                            'pub_asset_id'
                        ],

                        $resolved[
                            'asset_type'
                        ],

                        $asset
                    );


                $productionEntry = [
                    'index' =>
                        $index,

                    'asset_type' =>
                        $resolved[
                            'asset_type'
                        ],

                    'pub_asset_id' =>
                        $resolved[
                            'pub_asset_id'
                        ],

                    'existing_action' =>
                        $resolved[
                            'existing_action'
                        ]
                        ?? null,

                    'predecessor_pub_asset_id' =>
                        $resolved[
                            'predecessor_pub_asset_id'
                        ]
                        ?? null,

                    'asset' =>
                        $asset,

                    'persistence' =>
                        $persistence,

                    /*
                     * Includes readiness / preflight plus any
                     * expected signals the worker reported while
                     * performing the assignment.
                     */
                    'pubcom' =>
                        $pubComChannel
                            ? $pubComChannel
                                ->dispositionsAsArray()
                            : [],
                ];


                /*
                 * ASYNCHRONOUS CREATE.
                 *
                 * A queued video job has been accepted by CREATE,
                 * but no physical asset exists yet. It must not be
                 * counted as created until the video worker returns
                 * and the Manager promotes the completed file.
                 */
                if (
                    strtolower(
                        trim(
                            (string)(
                                $asset[
                                    'create_status'
                                ]
                                ?? ''
                            )
                        )
                    ) === 'queued'
                ) {
                    $queued[] =
                        $productionEntry;

                } else {
                    $created[] =
                        $productionEntry;
                }

            } catch (
                Throwable $e
            ) {
                /*
                 * CENTRAL PUB ERROR PATH.
                 *
                 * PubCom handles expected operating conditions.
                 *
                 * This catch is only for unexpected exceptions /
                 * invalid CREATE requests that actually reached
                 * the error path.
                 */
                $contextBox =
                    is_array(
                        $resolved[
                            'box'
                        ]
                        ?? null
                    )
                        ? $resolved[
                            'box'
                        ]
                        : (
                            is_array(
                                $order[
                                    'box'
                                ]
                                ?? null
                            )
                                ? $order[
                                    'box'
                                ]
                                : []
                        );


                $pubAssetId =
                    (int)(
                        $resolved[
                            'pub_asset_id'
                        ]
                        ?? $order[
                            'pub_asset_id'
                        ]
                        ?? 0
                    );


                $failedAssetType =
                    strtolower(
                        trim(
                            (string)(
                                $resolved[
                                    'asset_type'
                                ]
                                ?? $contextBox[
                                    'asset_type'
                                ]
                                ?? ''
                            )
                        )
                    );


                if (
                    $pubAssetId > 0
                    &&
                    in_array(
                        $failedAssetType,
                        [
                            'pin_composite',
                            'pin_idea',
                            'pin_idea_palette',
                            'pin_teaser',
                        ],
                        true
                    )
                ) {
                    try {
                        $this->assets
                            ->markError(
                                $pubAssetId,
                                'create',
                                match (
                                    $failedAssetType
                                ) {
                                    'pin_idea' =>
                                        'idea_create_failed',

                                    'pin_idea_palette' =>
                                        'palette_create_failed',

                                    'pin_teaser' =>
                                        'teaser_create_failed',

                                    default =>
                                        'composite_create_failed',
                                },
                                $e->getMessage()
                            );
                    } catch (Throwable) {
                        /*
                         * Preserve the original production/persistence
                         * exception. Central reporting below remains
                         * authoritative.
                         */
                    }
                }


                $failure =
                    $this->errors
                        ->report(
                            $e,
                            [
                                'stage' =>
                                    'create',

                                'pub_run_id' =>
                                    $contextBox[
                                        'pub_run_id'
                                    ]
                                    ?? null,

                                'pub_asset_id' =>
                                    $pubAssetId > 0
                                        ? $pubAssetId
                                        : null,

                                'proposal_key' =>
                                    $contextBox[
                                        'proposal_key'
                                    ]
                                    ?? null,

                                'asset_type' =>
                                    $resolved[
                                        'asset_type'
                                    ]
                                    ?? $contextBox[
                                        'asset_type'
                                    ]
                                    ?? null,

                                'creator_key' =>
                                    $resolved[
                                        'creator_key'
                                    ]
                                    ?? null,

                                'source_type' =>
                                    $contextBox[
                                        'source_type'
                                    ]
                                    ?? null,

                                'source_id' =>
                                    $contextBox[
                                        'source_id'
                                    ]
                                    ?? null,

                                'code' =>
                                    'create_failure',

                                'diagnostics' => [
                                    'batch_index' =>
                                        $index,
                                ],
                            ]
                        );


                $failed[] = [
                    'index' =>
                        $index,

                    ...$failure,

                    /*
                     * Preserve any PubCom history that occurred
                     * before the unexpected exception.
                     */
                    'pubcom' =>
                        $pubComChannel
                            ? $pubComChannel
                                ->dispositionsAsArray()
                            : [],
                ];
            }
        }


        $acceptedEntries = [
            ...$created,
            ...$queued,
        ];


        $replacedCount =
            count(
                array_filter(
                    $acceptedEntries,

                    static fn(
                        array $entry
                    ): bool =>
                        (
                            $entry[
                                'existing_action'
                            ]
                            ?? null
                        ) === 'replace'
                )
            );


        $newVersionCount =
            count(
                array_filter(
                    $acceptedEntries,

                    static fn(
                        array $entry
                    ): bool =>
                        (
                            $entry[
                                'existing_action'
                            ]
                            ?? null
                        ) === 'new_version'
                )
            );


        return [
            'code' =>
                'processed',

            'existing_asset_warning' =>
                false,

            'existing_policy' =>
                $existingPolicy,

            'existing_match_count' =>
                count(
                    $existingMatches
                ),

            'replaced_count' =>
                $replacedCount,

            'new_version_count' =>
                $newVersionCount,

            'created' =>
                $created,

            'queued' =>
                $queued,

            'failed' =>
                $failed,
        ];
    }


    /**
     * Normalize the boss decision for logical existing assets.
     *
     * Legacy string support:
     *
     *   check   -> ask for both decisions
     *   include -> replace in-house + create new version of shipped
     *
     * @return array{
     *   unshipped: string,
     *   shipped: string
     * }
     */
    private function normalizeExistingPolicy(
        array|string $policy
    ): array {
        $normalized = [
            'unshipped' =>
                'check',

            'shipped' =>
                'check',
        ];


        if (is_string($policy)) {
            $value =
                strtolower(
                    trim(
                        $policy
                    )
                );


            if (
                $value === ''
                || $value === 'check'
            ) {
                return $normalized;
            }


            if ($value === 'include') {
                return [
                    'unshipped' =>
                        'replace',

                    'shipped' =>
                        'new_version',
                ];
            }


            throw new RuntimeException(
                "CREATE does not support existing-asset policy '{$value}'."
            );
        }


        $unshipped =
            strtolower(
                trim(
                    (string)(
                        $policy[
                            'unshipped'
                        ]
                        ?? 'check'
                    )
                )
            );

        $shipped =
            strtolower(
                trim(
                    (string)(
                        $policy[
                            'shipped'
                        ]
                        ?? 'check'
                    )
                )
            );


        if (
            !in_array(
                $unshipped,
                [
                    'check',
                    'replace',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                "CREATE does not support unshipped existing-asset policy '{$unshipped}'."
            );
        }


        if (
            !in_array(
                $shipped,
                [
                    'check',
                    'new_version',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                "CREATE does not support shipped existing-asset policy '{$shipped}'."
            );
        }


        return [
            'unshipped' =>
                $unshipped,

            'shipped' =>
                $shipped,
        ];
    }


    /**
     * Inspect NEW orders for prior rows representing the same logical
     * asset combination.
     *
     * The newest in-house row wins over shipped history:
     *
     *   in-house exists -> replace that SAME pub_asset_id
     *   shipped only    -> create a NEW pub_asset_id using shipped copy
     *
     * @param array<int, array<string, mixed>> $orders
     *
     * @return array{
     *   matches: array<int, array<string, mixed>>
     * }
     */
    private function inspectExistingNewOrders(
        array $orders
    ): array {
        $matches = [];

        /** @var array<string, array<int, array<string, mixed>>> $candidateCache */
        $candidateCache = [];


        foreach (
            $orders
            as $index => $order
        ) {
            if (!is_array($order)) {
                continue;
            }


            $box =
                is_array(
                    $order[
                        'box'
                    ]
                    ?? null
                )
                    ? $order[
                        'box'
                    ]
                    : null;


            if (
                $box === null
                ||
                (int)(
                    $order[
                        'pub_asset_id'
                    ]
                    ?? 0
                ) > 0
            ) {
                continue;
            }


            $assetType =
                strtolower(
                    trim(
                        (string)(
                            $box[
                                'asset_type'
                            ]
                            ?? ''
                        )
                    )
                );

            $sourceType =
                strtolower(
                    trim(
                        (string)(
                            $box[
                                'source_type'
                            ]
                            ?? ''
                        )
                    )
                );

            $sourceId =
                (int)(
                    $box[
                        'source_id'
                    ]
                    ?? 0
                );

            $sortOrder =
                isset(
                    $box[
                        'sort_order'
                    ]
                )
                && $box[
                    'sort_order'
                ] !== null
                    ? (int)$box[
                        'sort_order'
                    ]
                    : null;

            $ingredients =
                is_array(
                    $box[
                        'ingredients'
                    ]
                    ?? null
                )
                    ? $box[
                        'ingredients'
                    ]
                    : [];


            if (
                $assetType === ''
                ||
                $sourceType === ''
                ||
                $sourceId <= 0
            ) {
                continue;
            }


            /*
             * Logical identity is NOT sort_order.
             *
             * The repository gives us current in-house candidates for the
             * same source + asset type. We then match the actual subject
             * carried by the ingredient box.
             */
            $candidateKey =
                $sourceType
                . ':'
                . $sourceId
                . ':'
                . $assetType;


            if (
                !array_key_exists(
                    $candidateKey,
                    $candidateCache
                )
            ) {
                $candidateCache[
                    $candidateKey
                ] =
                    $this->assets
                        ->listInHouseLogicalAssetCandidates(
                            $sourceType,
                            $sourceId,
                            $assetType
                        );
            }


            $newSubject =
                $this->subjectIdentity(
                    $ingredients
                );

            $existingAsset =
                null;


            foreach (
                $candidateCache[
                    $candidateKey
                ]
                as $candidate
            ) {
                if (!is_array($candidate)) {
                    continue;
                }


                $candidateIngredients =
                    is_array(
                        $candidate[
                            'ingredients'
                        ]
                        ?? null
                    )
                        ? $candidate[
                            'ingredients'
                        ]
                        : [];


                $candidateSubject =
                    $this->subjectIdentity(
                        $candidateIngredients
                    );


                if (
                    !$this->sameLogicalSubject(
                        $newSubject,
                        $candidateSubject
                    )
                ) {
                    continue;
                }


                $existingAsset =
                    $candidate;

                break;
            }


            if (!is_array($existingAsset)) {
                continue;
            }


            $matches[] = [
                'order_index' =>
                    (int)$index,

                'existing_state' =>
                    'unshipped',

                'asset_type' =>
                    $assetType,

                'source_type' =>
                    $sourceType,

                'source_id' =>
                    $sourceId,

                /*
                 * Current order metadata only. Never identity.
                 */
                'sort_order' =>
                    $sortOrder,

                'incoming_search_title' =>
                    trim(
                        (string)(
                            $box[
                                'search_title'
                            ]
                            ?? ''
                        )
                    ),

                'existing_asset' => [
                    'pub_asset_id' =>
                        (int)(
                            $existingAsset[
                                'pub_asset_id'
                            ]
                            ?? 0
                        ),

                    'pipeline_stage' =>
                        (string)(
                            $existingAsset[
                                'pipeline_stage'
                            ]
                            ?? ''
                        ),

                    'search_title' =>
                        (string)(
                            $existingAsset[
                                'search_title'
                            ]
                            ?? ''
                        ),

                    'description' =>
                        (string)(
                            $existingAsset[
                                'description'
                            ]
                            ?? ''
                        ),
                ],
            ];
        }


        return [
            'matches' =>
                $matches,
        ];
    }


    /**
     * Extract the stable subject carried by one Creator ingredient box.
     *
     * Single-photo products use source.
     * Before/After products use after because the After photo owns the
     * publishing copy for those products.
     *
     * Products with no single-photo subject return an empty subject and
     * therefore match by source + asset type only.
     *
     * @return array{photo_library_id:int|null,file_path:string|null}
     */
    private function subjectIdentity(
        array $ingredients
    ): array {
        $subject = [];


        if (
            isset(
                $ingredients[
                    'source'
                ]
            )
            && is_array(
                $ingredients[
                    'source'
                ]
            )
        ) {
            $subject =
                $ingredients[
                    'source'
                ];

        } elseif (
            isset(
                $ingredients[
                    'after'
                ]
            )
            && is_array(
                $ingredients[
                    'after'
                ]
            )
        ) {
            $subject =
                $ingredients[
                    'after'
                ];
        }


        $photoLibraryId =
            isset(
                $subject[
                    'photo_library_id'
                ]
            )
            && (int)$subject[
                'photo_library_id'
            ] > 0
                ? (int)$subject[
                    'photo_library_id'
                ]
                : null;


        $filePath =
            trim(
                (string)(
                    $subject[
                        'file_path'
                    ]
                    ?? ''
                )
            );


        return [
            'photo_library_id' =>
                $photoLibraryId,

            'file_path' =>
                $filePath !== ''
                    ? $filePath
                    : null,
        ];
    }


    /**
     * Match the actual logical subject, never sibling order.
     *
     * photo_library_id is authoritative when both boxes have it.
     * file_path is only a legacy bridge for already-created in-house orders
     * from before photo IDs were preserved in Creator ingredients.
     */
    private function sameLogicalSubject(
        array $newSubject,
        array $candidateSubject
    ): bool {
        $newPhotoId =
            isset(
                $newSubject[
                    'photo_library_id'
                ]
            )
            && $newSubject[
                'photo_library_id'
            ] !== null
                ? (int)$newSubject[
                    'photo_library_id'
                ]
                : null;


        $candidatePhotoId =
            isset(
                $candidateSubject[
                    'photo_library_id'
                ]
            )
            && $candidateSubject[
                'photo_library_id'
            ] !== null
                ? (int)$candidateSubject[
                    'photo_library_id'
                ]
                : null;


        if ($newPhotoId !== null) {
            if ($candidatePhotoId !== null) {
                return
                    $newPhotoId ===
                    $candidatePhotoId;
            }


            $newFilePath =
                trim(
                    (string)(
                        $newSubject[
                            'file_path'
                        ]
                        ?? ''
                    )
                );

            $candidateFilePath =
                trim(
                    (string)(
                        $candidateSubject[
                            'file_path'
                        ]
                        ?? ''
                    )
                );


            return
                $newFilePath !== ''
                && $candidateFilePath !== ''
                && $newFilePath ===
                    $candidateFilePath;
        }


        return true;
    }


    /**
     * Settle one asynchronous video job returned by the video worker.
     *
     * The worker reports facts. CREATE remains the authority that
     * decides whether a returned file becomes a permanent PUB asset.
     *
     * Preview-only video jobs have no pub_asset_id. They are allowed
     * to complete in the video-job table without creating a PUB asset.
     */
    public function settleVideoJob(
        int $jobId,
        string $status,
        ?string $outputRelPath = null,
        ?int $outputFileSizeBytes = null,
        ?string $errorMessage = null
    ): array {
        /*
         * The callback itself is the reason this equipment wakes.
         */
        $videoJobs =
            $this->videoJobs();


        $status =
            strtolower(
                trim(
                    $status
                )
            );


        if (
            !in_array(
                $status,
                [
                    'complete',
                    'failed',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Video job settlement status must be complete or failed.'
            );
        }


        $job =
            $videoJobs
                ->findById(
                    $jobId
                );


        if ($job === null) {
            throw new RuntimeException(
                "PUB video job #{$jobId} was not found."
            );
        }


        $currentStatus =
            strtolower(
                trim(
                    (string)(
                        $job[
                            'status'
                        ]
                        ?? ''
                    )
                )
            );


        /*
         * Idempotent worker callback.
         *
         * A worker retry after a lost HTTP response must not promote
         * the same file twice or turn a completed job into an error.
         */
        if (
            in_array(
                $currentStatus,
                [
                    'complete',
                    'failed',
                ],
                true
            )
        ) {
            return [
                'status' =>
                    'already_settled',

                'job' =>
                    $job,

                'asset' =>
                    isset(
                        $job[
                            'pub_asset_id'
                        ]
                    )
                    && $job[
                        'pub_asset_id'
                    ] !== null
                        ? $this->assets
                            ->getById(
                                (int)$job[
                                    'pub_asset_id'
                                ]
                            )
                        : null,

                'run_progress' =>
                    null,
            ];
        }


        $pubAssetId =
            isset(
                $job[
                    'pub_asset_id'
                ]
            )
            && $job[
                'pub_asset_id'
            ] !== null
                ? (int)$job[
                    'pub_asset_id'
                ]
                : 0;


        /*
         * Preview/manual jobs are not durable PUB assets.
         */
        if ($pubAssetId <= 0) {
            if ($status === 'complete') {
                $path =
                    trim(
                        (string)$outputRelPath
                    );

                if ($path === '') {
                    throw new RuntimeException(
                        'Completed preview video job requires output_rel_path.'
                    );
                }

                $videoJobs
                    ->completeJob(
                        $jobId,
                        $path,
                        $outputFileSizeBytes
                    );

            } else {
                $message =
                    trim(
                        (string)$errorMessage
                    );

                $videoJobs
                    ->failJob(
                        $jobId,
                        $message !== ''
                            ? $message
                            : 'Video rendering failed.'
                    );
            }


            return [
                'status' =>
                    $status,

                'job' =>
                    $videoJobs
                        ->findById(
                            $jobId
                        ),

                'asset' =>
                    null,

                'run_progress' =>
                    null,
            ];
        }


        $creatorKey =
            trim(
                (string)(
                    $job[
                        'creator_key'
                    ]
                    ?? ''
                )
            );


        if (
            !in_array(
                $creatorKey,
                [
                    'pinterest.before_after_video',
                    'youtube.playlist_video',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                "CREATE has no asynchronous completion route for creator_key '{$creatorKey}'."
            );
        }


        if ($status === 'complete') {
            $path =
                trim(
                    (string)$outputRelPath
                );


            if ($path === '') {
                throw new RuntimeException(
                    'Completed video job requires output_rel_path.'
                );
            }


            /*
             * Wake only the Chef who owns the returned job.
             *
             * The job's saved props are the exact render plan that
             * actually went to the oven. YouTube uses that durable plan
             * to report its dynamic duration accurately.
             */
            $createdAsset =
                match (
                    $creatorKey
                ) {
                    'pinterest.before_after_video' =>
                        $this->beforeAfterVideoCreator()
                            ->promoteCompletedVideo(
                                $pubAssetId,
                                $path,
                                $outputFileSizeBytes
                            ),

                    'youtube.playlist_video' =>
                        $this->playlistVideoCreator()
                            ->promoteCompletedVideo(
                                $pubAssetId,
                                $path,
                                is_array(
                                    $job[
                                        'props'
                                    ]
                                    ?? null
                                )
                                    ? $job[
                                        'props'
                                    ]
                                    : [],
                                $this->coverIngredientForAsset(
                                    $pubAssetId
                                ),
                                $outputFileSizeBytes
                            ),
                };


            $this->assets
                ->markCreated(
                    $pubAssetId,
                    $createdAsset
                );


            $videoJobs
                ->completeJob(
                    $jobId,
                    $path,
                    $outputFileSizeBytes
                );


            /*
             * Delete the private working copy only after BOTH durable
             * records have been successfully updated.
             */
            match (
                $creatorKey
            ) {
                'pinterest.before_after_video' =>
                    $this->beforeAfterVideoCreator()
                        ->discardWorkingFile(
                            $path
                        ),

                'youtube.playlist_video' =>
                    $this->playlistVideoCreator()
                        ->discardWorkingFile(
                            $path
                        ),
            };

        } else {
            $message =
                trim(
                    (string)$errorMessage
                );


            if ($message === '') {
                $message =
                    $creatorKey ===
                    'youtube.playlist_video'
                        ? 'YouTube Playlist Video rendering failed.'
                        : 'Before/After Video rendering failed.';
            }


            match (
                $creatorKey
            ) {
                'pinterest.before_after_video' =>
                    $this->beforeAfterVideoCreator()
                        ->recordVideoFailure(
                            $pubAssetId,
                            $message
                        ),

                'youtube.playlist_video' =>
                    $this->playlistVideoCreator()
                        ->recordVideoFailure(
                            $pubAssetId,
                            $message
                        ),
            };


            $videoJobs
                ->failJob(
                    $jobId,
                    $message
                );
        }

        $asset =
            $this->assets
                ->getById(
                    $pubAssetId
                );


        $runProgress = null;


        if (
            is_array(
                $asset
            )
        ) {
            $pubRunId =
                (int)(
                    $asset[
                        'pub_run_id'
                    ]
                    ?? 0
                );

            if ($pubRunId > 0) {
                $runProgress =
                    $this->runService()
                        ->refreshProgressFromAssets(
                            $pubRunId
                        );
            }
        }


        return [
            'status' =>
                $status,

            'job' =>
                $videoJobs
                    ->findById(
                        $jobId
                    ),

            'asset' =>
                $asset,

            'run_progress' =>
                $runProgress,
        ];
    }


    /**
     * Convenience entry point for:
     *
     *   REDO #256
     */
    public function recreate(
        int $pubAssetId
    ): array {
        $resolved =
            $this->resolveOrder([
                'pub_asset_id' =>
                    $pubAssetId,
            ]);


        $creator =
            $this->creatorForAssetType(
                $resolved[
                    'asset_type'
                ]
            );


        $gate =
            $this->authorizeCreator(
                $creator,
                $resolved[
                    'ingredients'
                ]
            );


        $channel =
            $gate[
                'channel'
            ];


        if (
            !$gate[
                'disposition'
            ]->shouldContinue()
        ) {
            /*
             * Expected PubCom block.
             *
             * Do not send this through PubErrorReporter.
             *
             * The endpoint can surface this result as the
             * Manager's operational response.
             */
            return [
                'status' =>
                    'blocked',

                'pub_asset_id' =>
                    $resolved[
                        'pub_asset_id'
                    ],

                'asset_type' =>
                    $resolved[
                        'asset_type'
                    ],

                'error' =>
                    $gate[
                        'disposition'
                    ]
                        ->signal()
                        ->message(),

                'pubcom' =>
                    $channel
                        ->dispositionsAsArray(),
            ];
        }


        try {
            /*
             * REDO enters the same CREATE lifecycle as NEW work.
             * Existing physical output remains available until a
             * replacement successfully reaches "created".
             */
            $this->assets
                ->markCreating(
                    $resolved[
                        'pub_asset_id'
                    ]
                );


            $asset =
                $this->createOne(
                    $resolved[
                        'pub_asset_id'
                    ],

                    $resolved[
                        'asset_type'
                    ],

                    $resolved[
                        'ingredients'
                    ]
                );


            $persistence =
                $this->persistFinishedAsset(
                    $resolved[
                        'pub_asset_id'
                    ],

                    $resolved[
                        'asset_type'
                    ],

                    $asset
                );

        } catch (Throwable $e) {
            if (
                in_array(
                    $resolved[
                        'asset_type'
                    ],
                    [
                        'pin_composite',
                        'pin_idea',
                        'pin_idea_palette',
                        'pin_teaser',
                    ],
                    true
                )
            ) {
                try {
                    $this->assets
                        ->markError(
                            $resolved[
                                'pub_asset_id'
                            ],
                            'create',
                            match (
                                $resolved[
                                    'asset_type'
                                ]
                            ) {
                                'pin_idea' =>
                                    'idea_create_failed',

                                'pin_idea_palette' =>
                                    'palette_create_failed',

                                'pin_teaser' =>
                                    'teaser_create_failed',

                                default =>
                                    'composite_create_failed',
                            },
                            $e->getMessage()
                        );
                } catch (Throwable) {
                    /*
                     * Preserve the original production/persistence
                     * exception if even error-state persistence fails.
                     */
                }
            }

            throw $e;
        }


        return [
            'asset' =>
                $asset,

            'persistence' =>
                $persistence,

            'pubcom' =>
                $channel
                    ->dispositionsAsArray(),
        ];
    }


    /**
     * Normalize either a NEW or REDO order.
     *
     * NEW:
     *   Box supplied
     *   asset id missing
     *
     * REDO:
     *   asset id supplied
     *   Box missing
     */
    private function resolveOrder(
        array $order,
        ?array $existingMatch = null,
        array $existingPolicy = [
            'unshipped' => 'check',
            'shipped' => 'check',
        ]
    ): array {
        $hasBox =
            is_array(
                $order[
                    'box'
                ]
                ?? null
            );


        $pubAssetId =
            (int)(
                $order[
                    'pub_asset_id'
                ]
                ?? 0
            );


        $hasAssetId =
            $pubAssetId > 0;


        /*
         * Ambiguous order.
         *
         * Callers should never send both.
         * The filed order is authoritative
         * for REDO work.
         */
        if (
            $hasBox
            && $hasAssetId
        ) {
            throw new RuntimeException(
                'CREATE order must contain either box or pub_asset_id, not both.'
            );
        }


        /*
         * Empty order.
         */
        if (
            !$hasBox
            && !$hasAssetId
        ) {
            throw new RuntimeException(
                'CREATE order requires box or pub_asset_id.'
            );
        }


        /*
         * ====================================================
         * NEW ORDER
         * ====================================================
         */
        if ($hasBox) {
            $box =
                $order[
                    'box'
                ];


            $assetType =
                $this->assetTypeFromBox(
                    $box
                );


            $creatorKey =
                $this->creatorKeyForAssetType(
                    $assetType
                );


            $ingredients =
                $this->ingredientsFromBox(
                    $box
                );


            /*
             * Common durable identity/routing for either:
             *
             *   brand-new asset
             *   in-house replacement
             *   new version of a shipped predecessor
             */
            $assetReservation = [
                'pub_run_id' =>
                    (int)(
                        $box[
                            'pub_run_id'
                        ]
                        ?? 0
                    ),

                'channel' =>
                    $this->channelForAssetType(
                        $assetType
                    ),

                'asset_type' =>
                    $assetType,

                'creator_key' =>
                    $creatorKey,

                'source_type' =>
                    trim(
                        (string)(
                            $box[
                                'source_type'
                            ]
                            ?? ''
                        )
                    ),

                'source_id' =>
                    (int)(
                        $box[
                            'source_id'
                        ]
                        ?? 0
                    ),

                'sort_order' =>
                    isset(
                        $box[
                            'sort_order'
                        ]
                    )
                        ? (int)$box[
                            'sort_order'
                        ]
                        : null,

                'search_title' =>
                    (string)(
                        $box[
                            'search_title'
                        ]
                        ?? ''
                    ),

                'description' =>
                    (string)(
                        $box[
                            'description'
                        ]
                        ?? ''
                    ),

                'pingback' =>
                    (string)(
                        $box[
                            'pingback'
                        ]
                        ?? ''
                    ),
            ];


            $existingAction = null;
            $predecessorPubAssetId = null;
            $alreadyPrepared = false;


            if (is_array($existingMatch)) {
                $existingState =
                    strtolower(
                        trim(
                            (string)(
                                $existingMatch[
                                    'existing_state'
                                ]
                                ?? ''
                            )
                        )
                    );

                $existingAsset =
                    is_array(
                        $existingMatch[
                            'existing_asset'
                        ]
                        ?? null
                    )
                        ? $existingMatch[
                            'existing_asset'
                        ]
                        : [];

                $predecessorPubAssetId =
                    (int)(
                        $existingAsset[
                            'pub_asset_id'
                        ]
                        ?? 0
                    );


                if ($predecessorPubAssetId <= 0) {
                    throw new RuntimeException(
                        'CREATE existing-asset match has no valid predecessor pub_asset_id.'
                    );
                }


                if ($existingState === 'unshipped') {
                    if (
                        (
                            $existingPolicy[
                                'unshipped'
                            ]
                            ?? 'check'
                        ) !== 'replace'
                    ) {
                        throw new RuntimeException(
                            'CREATE requires explicit replace authorization for an existing in-house asset.'
                        );
                    }


                    /*
                     * Idempotent retry / duplicate guard.
                     *
                     * If this exact replacement Box has already been
                     * prepared or completed for this same Analyze run,
                     * do not wake the Creator again. This matters most
                     * for asynchronous video assets, where a duplicate
                     * CREATE attempt would otherwise queue a second job.
                     */
                    if (
                        $this->assets
                            ->replacementAlreadyPrepared(
                                $predecessorPubAssetId,
                                $assetReservation,
                                $ingredients
                            )
                    ) {
                        $pubAssetId =
                            $predecessorPubAssetId;

                        $alreadyPrepared =
                            true;

                    } else {
                        $pubAssetId =
                            $this->assets
                                ->replaceWithOrder(
                                    $predecessorPubAssetId,
                                    $assetReservation,
                                    $ingredients
                                );
                    }

                    $existingAction =
                        'replace';

                } elseif ($existingState === 'shipped') {
                    if (
                        (
                            $existingPolicy[
                                'shipped'
                            ]
                            ?? 'check'
                        ) !== 'new_version'
                    ) {
                        throw new RuntimeException(
                            'CREATE requires explicit new-version authorization for a shipped predecessor.'
                        );
                    }


                    /*
                     * NEW durable ID.
                     *
                     * ANALYZE already prefilled predecessor outside copy
                     * into the workbench. The final incoming Box may contain
                     * operator edits, so search_title / description are used
                     * exactly as supplied here.
                     */
                    $pubAssetId =
                        $this->assets
                            ->reserveWithOrder(
                                $assetReservation,
                                $ingredients
                            );

                    $existingAction =
                        'new_version';

                } else {
                    throw new RuntimeException(
                        "CREATE received unsupported existing asset state '{$existingState}'."
                    );
                }

            } else {
                $pubAssetId =
                    $this->assets
                        ->reserveWithOrder(
                            $assetReservation,
                            $ingredients
                        );
            }


            return [
                'pub_asset_id' =>
                    $pubAssetId,

                'asset_type' =>
                    $assetType,

                'creator_key' =>
                    $creatorKey,

                'ingredients' =>
                    $ingredients,

                'existing_action' =>
                    $existingAction,

                'predecessor_pub_asset_id' =>
                    $predecessorPubAssetId,

                'already_prepared' =>
                    $alreadyPrepared,

                'box' =>
                    $box,
            ];
        }


        /*
         * ====================================================
         * REDO ORDER
         * ====================================================
         */

        $asset =
            $this->assets
                ->getById(
                    $pubAssetId
                );


        if (
            $asset === null
        ) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} not found."
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
                "PUB asset #{$pubAssetId} has already left the kitchen and cannot be recreated."
            );
        }


        /*
         * Fetch the current authoritative
         * filed Creator ingredients.
         */
        $filedOrder =
            $this->assets
                ->getOrder(
                    $pubAssetId
                );


        if (
            $filedOrder === null
        ) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} has no filed CREATE order."
            );
        }


        $ingredients =
            $filedOrder[
                'ingredients'
            ];


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
            $assetType === ''
        ) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} has no asset_type."
            );
        }


        $creatorKey =
            trim(
                (string)(
                    $asset[
                        'creator_key'
                    ]
                    ?? ''
                )
            );


        if (
            $creatorKey === ''
        ) {
            $creatorKey =
                $this->creatorKeyForAssetType(
                    $assetType
                );
        }


        /*
         * Sanity check:
         *
         * Filed order and durable asset identity
         * should agree.
         */
        $filedCreatorKey =
            trim(
                (string)(
                    $filedOrder[
                        'creator_key'
                    ]
                    ?? ''
                )
            );


        if (
            $filedCreatorKey !== ''
            &&
            $filedCreatorKey !==
                $creatorKey
        ) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} creator identity does not match its filed order."
            );
        }


        return [
            'pub_asset_id' =>
                $pubAssetId,

            'asset_type' =>
                $assetType,

            'creator_key' =>
                $creatorKey,

            'ingredients' =>
                $ingredients,

            'existing_action' =>
                null,

            'predecessor_pub_asset_id' =>
                null,

            'already_prepared' =>
                false,
        ];
    }


    /**
     * Open one NEW Analyze -> Create Box and remove only
     * the Creator ingredient compartment.
     *
     * Every migrated Analyze Manager Box has the same outer
     * contract:
     *
     *   metadata / routing fields
     *   ingredients {}
     *
     * CreateManager does not know which product is inside and
     * does not inspect the ingredient fields. The selected
     * Creator owns that contract.
     */
    private function ingredientsFromBox(
        array $box
    ): array {
        $ingredients =
            $box[
                'ingredients'
            ]
            ?? null;


        if (!is_array($ingredients)) {
            throw new RuntimeException(
                'CREATE received a new order without an ingredients object.'
            );
        }


        return $ingredients;
    }


    /**
     * Read the Creator identity from a NEW sealed box.
     */
    private function assetTypeFromBox(
        array $box
    ): string {
        $assetType =
            strtolower(
                trim(
                    (string)(
                        $box[
                            'asset_type'
                        ]
                        ?? ''
                    )
                )
            );


        if (
            $assetType === ''
        ) {
            throw new RuntimeException(
                'CREATE received a new order without asset_type.'
            );
        }


        return $assetType;
    }


    /**
     * Resolve the specialist worker for one CREATE line.
     *
     * Only Creators already registered in this Manager belong
     * here. Additional Pinterest / YouTube formats are added
     * when their asset-type routing is formally registered.
     */
    private function creatorForAssetType(
        string $assetType
    ): PubComWorkerContract {
        $assetType =
            strtolower(
                trim(
                    $assetType
                )
            );


        if (
            isset(
                $this->creators[
                    $assetType
                ]
            )
        ) {
            return $this->creators[
                $assetType
            ];
        }


        /*
         * WAKE ONLY THE CHEF REQUIRED FOR THIS PRODUCTION LINE.
         *
         * The created worker is cached for this Manager/request so a
         * batch of 35 orders for the same product uses one Chef.
         */
        $creator =
            match (
                $assetType
            ) {
                'pin_composite' =>
                    new CompositeCreator(
                        $this->pinterestTools(),
                        $this->projectRoot,
                        $this->logoPath()
                    ),

                'pin_before_after_video' =>
                    new BeforeAfterVideoCreator(
                        $this->assets,
                        $this->videoJobs(),
                        $this->videoWorkerHealth(),
                        $this->projectRoot,
                        $this->publicBaseUrl()
                    ),

                'pin_idea' =>
                    new IdeaCreator(
                        $this->pinterestTools(),
                        $this->projectRoot,
                        $this->logoPath()
                    ),

                'pin_idea_palette' =>
                    new PaletteCreator(
                        $this->pinterestTools(),
                        $this->projectRoot,
                        $this->logoPath()
                    ),

                'pin_teaser' =>
                    new TeaserCreator(
                        $this->pinterestTools(),
                        $this->projectRoot,
                        $this->logoPath()
                    ),

                'youtube_video' =>
                    new PlaylistVideoCreator(
                        $this->assets,
                        $this->videoJobs(),
                        $this->videoWorkerHealth(),
                        $this->projectRoot,
                        $this->publicBaseUrl()
                    ),

                default =>
                    throw new RuntimeException(
                        "CREATE has no Creator registered for asset_type '{$assetType}'."
                    ),
            };


        $this->creators[
            $assetType
        ] =
            $creator;


        return $creator;
    }


    /**
     * Give the selected Creator its PubCom channel,
     * ask whether the station is operational, then
     * ask whether it can process these exact ingredients.
     *
     * No permanent CREATE work happens here.
     *
     * @return array{
     *   channel: PubComChannel,
     *   disposition: PubComDisposition
     * }
     */
    private function authorizeCreator(
        PubComWorkerContract $creator,
        array $ingredients
    ): array {
        $channel =
            new PubComChannel(
                $this
            );


        $creator->connectPubCom(
            $channel
        );


        /*
         * CLOCK IN.
         */
        $readiness =
            $channel->report(
                $creator->readiness()
            );


        if (
            !$readiness
                ->shouldContinue()
        ) {
            return [
                'channel' =>
                    $channel,

                'disposition' =>
                    $readiness,
            ];
        }


        /*
         * INSPECT THIS ASSIGNMENT.
         */
        $preflight =
            $channel->report(
                $creator->preflight(
                    $ingredients
                )
            );


        return [
            'channel' =>
                $channel,

            'disposition' =>
                $preflight,
        ];
    }


    /**
     * Send one fully resolved numbered order
     * to its specialist Creator.
     *
     * The Creator does not know or care whether
     * this is an original or a redo.
     */
    private function createOne(
        int $pubAssetId,
        string $assetType,
        array $ingredients
    ): array {
        /*
         * Use the SAME awakened Chef that passed readiness/preflight.
         * creatorForAssetType() returns the cached worker for this line.
         */
        $creator =
            $this->creatorForAssetType(
                $assetType
            );


        return $creator->create(
            $pubAssetId,
            $ingredients
        );
    }


    /**
     * Persist one finished Creator asset.
     *
     * The Manager does not open or map the asset object.
     * PdoPubAssetRepository owns the pub_assets schema
     * and decides how the supplied asset fields are stored.
     *
     * Companion physical output such as a video thumbnail travels
     * in the same Creator result and is persisted on the same row.
     *
     * @return array<string, mixed>|null
     */
    private function persistFinishedAsset(
        int $pubAssetId,
        string $assetType,
        array $asset
    ): ?array {
        $createStatus =
            strtolower(
                trim(
                    (string)(
                        $asset[
                            'create_status'
                        ]
                        ?? ''
                    )
                )
            );


        /*
         * Asynchronous work has been accepted but is not finished.
         * The asset remains "creating" until settleVideoJob() receives
         * and authorizes the completed physical asset.
         */
        if ($createStatus === 'queued') {
            return null;
        }


        /*
         * Every synchronous Creator returns a finished physical asset.
         * CreateManager owns the common lifecycle transition to created.
         */
        return $this->assets
            ->markCreated(
                $pubAssetId,
                $asset
            );
    }


    /**
     * Asset type → publishing channel.
     */
    private function channelForAssetType(
        string $assetType
    ): string {
        return match (
            $assetType
        ) {
            'pin_composite',
            'pin_before_after_video',
            'pin_idea',
            'pin_idea_palette',
            'pin_teaser' =>
                'pinterest',

            'youtube_video' =>
                'youtube',

            default =>
                throw new RuntimeException(
                    "CREATE cannot determine channel for asset_type '{$assetType}'."
                ),
        };
    }


    /**
     * Asset type → specialist Creator.
     */
    private function creatorKeyForAssetType(
        string $assetType
    ): string {
        return match (
            $assetType
        ) {
            'pin_composite' =>
                'pinterest.composite',

            'pin_before_after_video' =>
                'pinterest.before_after_video',

            'pin_idea' =>
                'pinterest.idea',

            'pin_idea_palette' =>
                'pinterest.idea_palette',

            'pin_teaser' =>
                'pinterest.teaser',

            'youtube_video' =>
                'youtube.playlist_video',

            default =>
                throw new RuntimeException(
                    "CREATE has no Creator registered for asset_type '{$assetType}'."
                ),
        };
    }

    /**
     * Shared Pinterest workstation equipment.
     *
     * Created only if a Pinterest still-image Chef needs it.
     */
    private function pinterestTools(): PinterestCreatorTools
    {
        if ($this->pinterestTools === null) {
            $this->pinterestTools =
                new PinterestCreatorTools();
        }


        return $this->pinterestTools;
    }


    /**
     * Shared asynchronous video-job desk.
     *
     * Created only if a video Chef or worker callback needs it.
     */
    private function videoJobs(): PdoVideoJobRepository
    {
        if ($this->videoJobs === null) {
            $this->videoJobs =
                new PdoVideoJobRepository(
                    $this->pdo
                );
        }


        return $this->videoJobs;
    }


    /**
     * Shared video-worker health station.
     *
     * Created only if a video Chef wakes.
     */
    private function videoWorkerHealth(): VideoWorkerHealthService
    {
        if ($this->videoWorkerHealth === null) {
            $this->videoWorkerHealth =
                new VideoWorkerHealthService(
                    $this->projectRoot
                );
        }


        return $this->videoWorkerHealth;
    }


    /**
     * PUB run administration is needed only when CREATE must
     * refresh durable run progress (for example after async settlement).
     */
    private function runService(): PubRunService
    {
        if ($this->runService === null) {
            $this->runService =
                new PubRunService(
                    new PdoPubRunRepository(
                        $this->pdo
                    ),
                    $this->assets
                );
        }


        return $this->runService;
    }


    /**
     * Product-independent ColorFix Pinterest logo location.
     */
    private function logoPath(): string
    {
        return
            $this->projectRoot
            . '/brand/'
            . 'colorfix-pin-logo-compact-right-aligned-transparent.png';
    }


    /**
     * Determine the public base URL only when a video Chef needs it.
     */
    private function publicBaseUrl(): string
    {
        if ($this->publicBaseUrl !== null) {
            return $this->publicBaseUrl;
        }


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
                &&
                strtolower(
                    (string)(
                        $_SERVER[
                            'HTTPS'
                        ]
                        ?? ''
                    )
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


        $this->publicBaseUrl =
            $forwardedProto
            . '://'
            . $host;


        return $this->publicBaseUrl;
    }


    /**
     * Typed access to the lazily awakened Before/After Video Chef
     * for async completion work.
     */
    private function beforeAfterVideoCreator(): BeforeAfterVideoCreator
    {
        $creator =
            $this->creatorForAssetType(
                'pin_before_after_video'
            );


        if (!$creator instanceof BeforeAfterVideoCreator) {
            throw new RuntimeException(
                'CREATE Before/After Video Creator could not be resolved.'
            );
        }


        return $creator;
    }


    /**
     * YouTube async settlement still belongs to the original filed CREATE
     * order. The Manager retrieves the exact YouTube cover ingredient and
     * hands it back to the YouTube Chef.
     *
     * Pinterest Before/After video has no cover/thumbnail ingredient.
     */
    private function coverIngredientForAsset(
        int $pubAssetId
    ): array {
        $order =
            $this->assets
                ->getOrder(
                    $pubAssetId
                );


        $cover =
            is_array(
                $order[
                    'ingredients'
                ][
                    'cover'
                ]
                ?? null
            )
                ? $order[
                    'ingredients'
                ][
                    'cover'
                ]
                : null;


        if ($cover === null) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} has no filed cover ingredient."
            );
        }


        return $cover;
    }


    /**
     * Typed access to the lazily awakened YouTube Playlist Video Chef
     * for asynchronous completion work.
     */
    private function playlistVideoCreator(): PlaylistVideoCreator
    {
        $creator =
            $this->creatorForAssetType(
                'youtube_video'
            );


        if (!$creator instanceof PlaylistVideoCreator) {
            throw new RuntimeException(
                'CREATE YouTube Playlist Video Creator could not be resolved.'
            );
        }


        return $creator;
    }

}