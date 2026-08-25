<?php
declare(strict_types=1);

namespace App\PUB\Create;

use App\PUB\Create\Pinterest\BeforeAfterVideoCreator;
use App\PUB\Create\Pinterest\CompositeCreator;
use App\PUB\Create\Pinterest\IdeaCreator;
use App\PUB\Create\Pinterest\PaletteCreator;
use App\PUB\Create\Video\PdoVideoJobRepository;
use App\PUB\Errors\PubErrorReporter;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComDisposition;
use App\PUB\PubCom\PubComManagerContract;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use App\PUB\Repos\PdoPubAssetRepository;
use App\PUB\Services\PubRunService;
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


    public function __construct(
        private PdoPubAssetRepository $assets,

        private CompositeCreator $compositeCreator,
        private BeforeAfterVideoCreator $beforeAfterVideoCreator,
        private IdeaCreator $ideaCreator,
        private PaletteCreator $paletteCreator,

        ?PubErrorReporter $errors = null,

        private ?PdoVideoJobRepository $videoJobs = null,
        private ?PubRunService $runService = null,
    ) {
        $this->errors =
            $errors
            ?? new PubErrorReporter(
                dirname(__DIR__)
                . '/Errors/pub_errors.log'
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
        array $boxes
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
            $orders
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
     * @param array<int, array<string, mixed>> $orders
     */
    public function processBatch(
        array $orders
    ): array {
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
                        $order
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
                 * Composite is the first Creator migrated to the
                 * new contract. The Manager hands the entire asset
                 * object to the repository untouched and waits for
                 * confirmation before treating the unit as created.
                 *
                 * Legacy Creator lines retain their existing
                 * persistence behavior until migrated individually.
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


        return [
            'created' =>
                $created,

            'queued' =>
                $queued,

            'failed' =>
                $failed,
        ];
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
        if (
            $this->videoJobs === null
        ) {
            throw new RuntimeException(
                'Create Manager has no video-job repository.'
            );
        }


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
            $this->videoJobs
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

                $this->videoJobs
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

                $this->videoJobs
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
                    $this->videoJobs
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
            $creatorKey !==
            'pinterest.before_after_video'
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
                    'Completed Before/After Video job requires output_rel_path.'
                );
            }


            /*
             * WORKER PREPARED IT; MANAGER AUTHORIZES PERMANENCE.
             *
             * The Creator promotes the physical file and returns the
             * finished asset object. CreateManager then owns the durable
             * creating -> created transition.
             */
            $createdAsset =
                $this->beforeAfterVideoCreator
                    ->promoteCompletedVideo(
                        $pubAssetId,
                        $path,
                        $outputFileSizeBytes
                    );


            $this->assets
                ->markCreated(
                    $pubAssetId,
                    $createdAsset
                );


            $this->videoJobs
                ->completeJob(
                    $jobId,
                    $path,
                    $outputFileSizeBytes
                );


            /*
             * Delete the private working copy only after BOTH durable
             * records have been successfully updated.
             */
            $this->beforeAfterVideoCreator
                ->discardWorkingFile(
                    $path
                );

        } else {
            $message =
                trim(
                    (string)$errorMessage
                );

            if ($message === '') {
                $message =
                    'Before/After Video rendering failed.';
            }


            $this->beforeAfterVideoCreator
                ->recordVideoFailure(
                    $pubAssetId,
                    $message
                );


            $this->videoJobs
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
            $this->runService !== null
            && is_array(
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
                    $this->runService
                        ->refreshProgressFromAssets(
                            $pubRunId
                        );
            }
        }


        return [
            'status' =>
                $status,

            'job' =>
                $this->videoJobs
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
        array $order
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
             * Reserve asset number and file
             * exact sealed order atomically.
             *
             * NOTE:
             *
             * PubCom readiness / preflight has already
             * cleared this NEW order before we get here.
             *
             * Physical-output staging is a separate
             * production-safety change and is not part
             * of this PubCom wiring pass.
             */
            $pubAssetId =
                $this->assets
                    ->reserveWithOrder(
                        [
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
                        ],

                        /*
                         * Exact Creator ingredients prepared
                         * by ANALYZE.
                         */
                        $ingredients
                    );


            return [
                'pub_asset_id' =>
                    $pubAssetId,

                'asset_type' =>
                    $assetType,

                'creator_key' =>
                    $creatorKey,

                'ingredients' =>
                    $ingredients,

                /*
                 * Retained only as NEW-order context while this
                 * Manager call is active. It is not filed as the
                 * Creator order.
                 */
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
        return match (
            $assetType
        ) {
            'pin_composite' =>
                $this->compositeCreator,

            'pin_before_after_video' =>
                $this->beforeAfterVideoCreator,

            'pin_idea' =>
                $this->ideaCreator,

            'pin_idea_palette' =>
                $this->paletteCreator,

            default =>
                throw new RuntimeException(
                    "CREATE has no Creator registered for asset_type '{$assetType}'."
                ),
        };
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
        return match (
            $assetType
        ) {
            'pin_composite' =>
                $this
                    ->compositeCreator
                    ->create(
                        $pubAssetId,
                        $ingredients
                    ),

            'pin_before_after_video' =>
                $this
                    ->beforeAfterVideoCreator
                    ->create(
                        $pubAssetId,
                        $ingredients
                    ),

            'pin_idea' =>
                $this
                    ->ideaCreator
                    ->create(
                        $pubAssetId,
                        $ingredients
                    ),

            'pin_idea_palette' =>
                $this
                    ->paletteCreator
                    ->create(
                        $pubAssetId,
                        $ingredients
                    ),

            default =>
                throw new RuntimeException(
                    "CREATE has no Creator registered for asset_type '{$assetType}'."
                ),
        };
    }


    /**
     * Persist one finished Creator asset.
     *
     * The Manager does not open or map the asset object.
     * PdoPubAssetRepository owns the pub_assets schema
     * and decides how the supplied asset fields are stored.
     *
     * Composite, Idea, and Palette are migrated lines. Legacy lines
     * still persist themselves until migrated individually.
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
            'pin_idea_palette' =>
                'pinterest',

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

            default =>
                throw new RuntimeException(
                    "CREATE has no Creator registered for asset_type '{$assetType}'."
                ),
        };
    }
}