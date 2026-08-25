<?php
declare(strict_types=1);

namespace App\PUB\Analyze;

use App\PUB\Analyze\Pinterest\BeforeAfterVideoAnalyzer;
use App\PUB\Analyze\Pinterest\CompositeAnalyzer;
use App\PUB\Analyze\Pinterest\IdeaAnalyzer;
use App\PUB\Analyze\Pinterest\PaletteAnalyzer;
use App\PUB\Analyze\Pinterest\YouTubeTeaserAnalyzer;
use App\PUB\Analyze\Sources\PlaylistSourcePreparer;
use App\PUB\Analyze\YouTube\PlaylistVideoAnalyzer;
use App\PUB\Contracts\PubContract;
use App\PUB\Services\PubRunService;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComDisposition;
use App\PUB\PubCom\PubComManagerContract;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use RuntimeException;
use Throwable;

final class AnalyzeManager implements PubComManagerContract
{
    public function __construct(
        private PubRunService $runService,
        private PlaylistSourcePreparer $playlistSourcePreparer,

        private CompositeAnalyzer $compositeAnalyzer,
        private BeforeAfterVideoAnalyzer $beforeAfterVideoAnalyzer,
        private IdeaAnalyzer $ideaAnalyzer,
        private PaletteAnalyzer $paletteAnalyzer,
        private PlaylistVideoAnalyzer $youtubeVideoAnalyzer,
        private YouTubeTeaserAnalyzer $youtubeTeaserAnalyzer,
    ) {}


    public function readiness(): PubComSignal
    {
        return PubComSignal::ready(
            'Analyze Manager is ready.',
            [
                'department' => 'analyze',
                'manager' => self::class,
            ]
        );
    }


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
                PubComDisposition::STOP_LINE,
                PubComDisposition::DISPLAY_POPUP,
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
            "ANALYZE received an unsupported PubCom signal type '{$signal->type()}'."
        );
    }


    /**
     * @return array<string, mixed>
     */
    public function analyze(
        string $sourceType,
        int $sourceId,
        string $outputType,
        string $runMode = 'check',
        int $overwritePubRunId = 0
    ): array {
        $sourceType =
            strtolower(
                trim(
                    $sourceType
                )
            );

        $outputType =
            strtolower(
                trim(
                    $outputType
                )
            );

        $runMode =
            strtolower(
                trim(
                    $runMode
                )
            );


        if ($sourceType === '') {
            throw new RuntimeException(
                'ANALYZE requires source_type.'
            );
        }

        if ($sourceId <= 0) {
            throw new RuntimeException(
                'ANALYZE requires a valid source_id.'
            );
        }

        if ($outputType === '') {
            throw new RuntimeException(
                'ANALYZE requires output_type.'
            );
        }

        if (
            !in_array(
                $runMode,
                [
                    'check',
                    'new',
                    'overwrite',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                "ANALYZE does not support run mode '{$runMode}'."
            );
        }


        $pubRunId = 0;
        $job = [];
        $boxes = [];
        $failed = [];
        $pubCom = [];


        try {
            /*
             * CONTRACT FIRST.
             */
            $contract =
                PubContract::effective(
                    'analyze',
                    $outputType
                );

            if ($contract === null) {
                throw new RuntimeException(
                    "No ANALYZE contract exists for output type '{$outputType}'."
                );
            }


            $channel =
                strtolower(
                    trim(
                        (string)(
                            $contract['channel']
                            ?? ''
                        )
                    )
                );

            if ($channel === '') {
                throw new RuntimeException(
                    "ANALYZE contract '{$outputType}' has no dispatch channel."
                );
            }


            /*
             * MORNING MARKET RUN.
             *
             * PlaylistSourcePreparer gathers the common
             * PUB source material once.
             */
            $preparedSource =
                $this->prepareSource(
                    $sourceType,
                    $sourceId
                );


            /*
             * PREFLIGHT BEFORE JOB CREATION.
             */
            $assignment =
                $this->prepareOutputAssignment(
                    $outputType,
                    $channel,
                    $preparedSource
                );


            /** @var PubComChannel $pubComChannel */
            $pubComChannel =
                $assignment['channel'];


            /** @var PubComDisposition $disposition */
            $disposition =
                $assignment['disposition'];


            $pubCom =
                $pubComChannel
                    ->dispositionsAsArray();


            if (
                !$disposition
                    ->shouldContinue()
            ) {
                $failed[] = [
                    'pub_run_id' =>
                        null,

                    'asset_type' =>
                        $outputType,

                    'source_type' =>
                        $sourceType,

                    'source_id' =>
                        $sourceId,

                    'error' =>
                        $disposition
                            ->signal()
                            ->message(),

                    'pubcom' =>
                        $pubCom,
                ];


                return [
                    'code' =>
                        'analyze_blocked',

                    'pub_run_id' =>
                        0,

                    'run_mode' =>
                        $runMode,

                    'overwrote_existing_job' =>
                        false,

                    'deleted_asset_count' =>
                        0,

                    'source_type' =>
                        $sourceType,

                    'source_id' =>
                        $sourceId,

                    'output_type' =>
                        $outputType,

                    'boxes' =>
                        [],

                    'failed' =>
                        $failed,

                    'pubcom' =>
                        $pubCom,
                ];
            }


            /*
             * JOB DECISION.
             */
            $job =
                $this->establishJob(
                    $sourceType,
                    $sourceId,
                    $outputType,
                    $runMode,
                    $overwritePubRunId
                );


            if (
                ($job['code'] ?? '') ===
                'existing_pub_run'
            ) {
                return [
                    ...$job,

                    'pubcom' =>
                        $pubCom,
                ];
            }


            $pubRunId =
                (int)(
                    $job['pub_run_id']
                    ?? 0
                );

            if ($pubRunId <= 0) {
                throw new RuntimeException(
                    'ANALYZE could not establish a valid pub_run_id.'
                );
            }


            /*
             * RUN AUTHORIZED SPECIALIST.
             *
             * Analyzer receives raw market material only.
             * It does NOT receive the outer Box.
             */
            $specialist =
                $this->runAuthorizedAnalyzer(
                    $outputType,
                    $preparedSource,
                    $assignment
                );


            $pubCom =
                is_array(
                    $specialist['pubcom']
                    ?? null
                )
                    ? $specialist['pubcom']
                    : $pubCom;


            $proposals =
                is_array(
                    $specialist['proposals']
                    ?? null
                )
                    ? $specialist['proposals']
                    : [];


            foreach (
                $proposals
                as $proposal
            ) {
                if (!is_array($proposal)) {
                    throw new RuntimeException(
                        "Analyzer '{$outputType}' returned an invalid proposal."
                    );
                }


                /*
                 * SPECIALIST OWNS THE REAL ASSET TYPE.
                 */
                if (
                    trim(
                        (string)(
                            $proposal['asset_type']
                            ?? ''
                        )
                    ) === ''
                ) {
                    throw new RuntimeException(
                        "Analyzer '{$outputType}' returned a proposal with no asset_type."
                    );
                }


                /*
                 * MANAGER-OWNED OUTER BOX.
                 *
                 * The Analyzer never sees this.
                 */
                $box = [
                    'pub_run_id' =>
                        $pubRunId,

                    'channel' =>
                        $channel,

                    'asset_type' =>
                        '',

                    'source_type' =>
                        $sourceType,

                    'source_id' =>
                        $sourceId,

                    'search_title' =>
                        '',

                    'description' =>
                        '',

                    'pingback' =>
                        '',

                    'ingredients' =>
                        [],
                ];


                /*
                 * MIGRATED ANALYZERS.
                 *
                 * These specialists may contribute ONLY:
                 *
                 *   asset_type
                 *   search_title
                 *   description
                 *   ingredients
                 *
                 * Remaining analyzers retain their legacy
                 * proposal shape until migrated individually.
                 */
                if (
                    in_array(
                        $outputType,
                        [
                            'composite',
                            'before_after_video',
                            'idea',
                            'idea_palette',
                        ],
                        true
                    )
                ) {
                    $proposal =
                        array_intersect_key(
                            $proposal,
                            array_flip([
                                'asset_type',
                                'search_title',
                                'description',
                                'ingredients',
                            ])
                        );
                }


                /*
                 * PHP SPREAD MERGE.
                 *
                 * Manager owns the Box.
                 * Specialist simply returns fields whose names
                 * already match the Box fields it is allowed
                 * to suggest/fill.
                 */
                $boxes[] = [
                    ...$box,
                    ...$proposal,

                    /*
                     * Factory identity is always Manager-owned.
                     */
                    'pub_run_id' =>
                        $pubRunId,

                    'channel' =>
                        $channel,

                    'source_type' =>
                        $sourceType,

                    'source_id' =>
                        $sourceId,
                ];
            }

        } catch (Throwable $e) {
            $failed[] = [
                'pub_run_id' =>
                    $pubRunId > 0
                        ? $pubRunId
                        : null,

                'asset_type' =>
                    $outputType,

                'source_type' =>
                    $sourceType,

                'source_id' =>
                    $sourceId,

                'error' =>
                    $e->getMessage(),

                'pubcom' =>
                    $pubCom,
            ];
        }


        return [
            'code' =>
                'analyzed',

            'pub_run_id' =>
                $pubRunId,

            'run_mode' =>
                $runMode,

            'overwrote_existing_job' =>
                (bool)(
                    $job[
                        'overwrote_existing_job'
                    ]
                    ?? false
                ),

            'deleted_asset_count' =>
                (int)(
                    $job[
                        'deleted_asset_count'
                    ]
                    ?? 0
                ),

            'source_type' =>
                $sourceType,

            'source_id' =>
                $sourceId,

            'output_type' =>
                $outputType,

            'boxes' =>
                $boxes,

            'failed' =>
                $failed,

            'pubcom' =>
                $pubCom,
        ];
    }


    /**
     * @return array<string, mixed>
     */
    private function establishJob(
        string $sourceType,
        int $sourceId,
        string $outputType,
        string $runMode,
        int $overwritePubRunId
    ): array {
        if ($runMode === 'check') {
            $existingRun =
                $this->runService
                    ->findLatestMatching(
                        $sourceType,
                        $sourceId,
                        $outputType
                    );


            if ($existingRun !== null) {
                $existingRunId =
                    (int)(
                        $existingRun[
                            'pub_run_id'
                        ]
                        ?? 0
                    );

                if ($existingRunId <= 0) {
                    throw new RuntimeException(
                        'Matching PUB run has no valid pub_run_id.'
                    );
                }


                $overwriteStatus =
                    $this->runService
                        ->overwriteStatus(
                            $existingRunId
                        );


                $assetCount =
                    (int)(
                        $overwriteStatus[
                            'asset_count'
                        ]
                        ?? 0
                    );


                /*
                 * EMPTY JOB.
                 */
                if ($assetCount === 0) {
                    $this->runService
                        ->overwrite(
                            $existingRunId,
                            $sourceType,
                            $sourceId,
                            $outputType
                        );


                    return [
                        'code' =>
                            'job_ready',

                        'pub_run_id' =>
                            $existingRunId,

                        'run_mode' =>
                            $runMode,

                        'overwrote_existing_job' =>
                            false,

                        'deleted_asset_count' =>
                            0,
                    ];
                }


                return [
                    'code' =>
                        'existing_pub_run',

                    'pub_run_id' =>
                        $existingRunId,

                    'run_mode' =>
                        $runMode,

                    'source_type' =>
                        $sourceType,

                    'source_id' =>
                        $sourceId,

                    'output_type' =>
                        $outputType,

                    'existing_run' =>
                        $existingRun,

                    'can_overwrite' =>
                        (bool)(
                            $overwriteStatus[
                                'can_overwrite'
                            ]
                            ?? false
                        ),

                    'blocking_assets' =>
                        $overwriteStatus[
                            'blocking_assets'
                        ]
                        ?? [],

                    'asset_count' =>
                        (int)(
                            $overwriteStatus[
                                'asset_count'
                            ]
                            ?? 0
                        ),

                    'boxes' =>
                        [],

                    'failed' =>
                        [],
                ];
            }
        }


        if ($runMode === 'overwrite') {
            if ($overwritePubRunId <= 0) {
                throw new RuntimeException(
                    'ANALYZE overwrite requires overwrite_pub_run_id.'
                );
            }


            $overwriteResult =
                $this->runService
                    ->overwrite(
                        $overwritePubRunId,
                        $sourceType,
                        $sourceId,
                        $outputType
                    );


            return [
                'code' =>
                    'job_ready',

                'pub_run_id' =>
                    $overwritePubRunId,

                'run_mode' =>
                    $runMode,

                'overwrote_existing_job' =>
                    true,

                'deleted_asset_count' =>
                    (int)(
                        $overwriteResult[
                            'deleted_asset_count'
                        ]
                        ?? 0
                    ),
            ];
        }


        /*
         * CHECK WITH NO EXISTING JOB
         * OR EXPLICIT NEW.
         */
        $pubRunId =
            $this->runService
                ->start(
                    $sourceType,
                    $sourceId,
                    $outputType,
                    0
                );


        return [
            'code' =>
                'job_ready',

            'pub_run_id' =>
                $pubRunId,

            'run_mode' =>
                $runMode,

            'overwrote_existing_job' =>
                false,

            'deleted_asset_count' =>
                0,
        ];
    }


    /**
     * Convert source into canonical PUB market material.
     */
    private function prepareSource(
        string $sourceType,
        int $sourceId
    ): array {
        return match (
            $sourceType
        ) {
            'playlist' =>
                $this
                    ->playlistSourcePreparer
                    ->prepare(
                        $sourceId
                    ),

            default =>
                throw new RuntimeException(
                    "ANALYZE has no source preparer registered for '{$sourceType}'."
                ),
        };
    }


    /**
     * Select channel market material and specialist.
     *
     * Manager filters by CHANNEL.
     * Analyzer filters by RECIPE.
     */
    private function prepareOutputAssignment(
        string $outputType,
        string $channel,
        array $source
    ): array {
        $sourceId =
            (int)(
                $source['source_id']
                ?? 0
            );


        $items =
            is_array(
                $source['items']
                ?? null
            )
                ? $source['items']
                : [];


        /*
         * CHANNEL CULL.
         */
        $eligibleItems =
            match (
                $channel
            ) {
                'pinterest' =>
                    $this->pinterestItems(
                        $items
                    ),

                'youtube' =>
                    $this->youtubeItems(
                        $items
                    ),

                default =>
                    $items,
            };


        /*
         * CHANNEL MARKET SOURCE.
         *
         * Same prepared source, but items[] has been
         * replaced by the channel-eligible produce.
         *
         * linked_pvs[] and other common source material
         * remain available.
         */
        $channelSource = [
            ...$source,

            'items' =>
                $eligibleItems,
        ];


        /*
         * SELECT SPECIALIST.
         */
        $analyzer =
            match (
                $outputType
            ) {
                'composite' =>
                    $this->compositeAnalyzer,

                'before_after_video' =>
                    $this->beforeAfterVideoAnalyzer,

                'idea' =>
                    $this->ideaAnalyzer,

                'idea_palette' =>
                    $this->paletteAnalyzer,

                'youtube_video' =>
                    $this->youtubeVideoAnalyzer,

                'youtube_teaser_pin' =>
                    $this->youtubeTeaserAnalyzer,

                default =>
                    throw new RuntimeException(
                        "ANALYZE has no Analyzer registered for output type '{$outputType}'."
                    ),
            };


        /*
         * PREFLIGHT INPUT.
         *
         * Migrated Pinterest analyzers receive the complete
         * Pinterest market source.
         *
         * Remaining analyzers retain their legacy preflight
         * shape until migrated individually.
         */
        $preflightInput =
            match (
                $outputType
            ) {
                'composite',
                'before_after_video',
                'idea',
                'idea_palette' =>
                    $channelSource,

                'youtube_teaser_pin' =>
                    $eligibleItems,

                'youtube_video' =>
                    $source,

                default =>
                    $source,
            };


        $gate =
            $this->authorizeAnalyzer(
                $analyzer,
                $preflightInput
            );


        return [
            'analyzer' =>
                $analyzer,

            'source_id' =>
                $sourceId,

            'eligible_items' =>
                $eligibleItems,

            'channel_source' =>
                $channelSource,

            'channel' =>
                $gate['channel'],

            'disposition' =>
                $gate['disposition'],
        ];
    }


    /**
     * Run a specialist that already passed PubCom.
     */
    private function runAuthorizedAnalyzer(
        string $outputType,
        array $source,
        array $assignment
    ): array {
        $eligibleItems =
            is_array(
                $assignment['eligible_items']
                ?? null
            )
                ? $assignment['eligible_items']
                : [];


        $channelSource =
            is_array(
                $assignment['channel_source']
                ?? null
            )
                ? $assignment['channel_source']
                : [];


        $pubComChannel =
            $assignment['channel']
            ?? null;


        if (
            !$pubComChannel instanceof
            PubComChannel
        ) {
            throw new RuntimeException(
                'ANALYZE authorized assignment has no PubCom channel.'
            );
        }


        $result =
            match (
                $outputType
            ) {
                /*
                 * MIGRATED PINTEREST ANALYZERS.
                 *
                 * Each receives the same complete
                 * Pinterest market source and chooses
                 * the recipe-specific material it needs.
                 */
                'composite' =>
                    $this
                        ->compositeAnalyzer
                        ->analyze(
                            $channelSource
                        ),

                'idea' =>
                    $this
                        ->ideaAnalyzer
                        ->analyze(
                            $channelSource
                        ),

                'idea_palette' =>
                    $this
                        ->paletteAnalyzer
                        ->analyze(
                            $channelSource
                        ),

                'before_after_video' =>
                    $this
                        ->beforeAfterVideoAnalyzer
                        ->analyze(
                            $channelSource
                        ),


                /*
                 * Remaining analyzers stay untouched
                 * until migrated one by one.
                 */
                'youtube_video' =>
                    $this
                        ->youtubeVideoAnalyzer
                        ->analyze(
                            $source
                        ),

                'youtube_teaser_pin' =>
                    $this
                        ->youtubeTeaserAnalyzer
                        ->analyze(
                            $eligibleItems
                        ),

                default =>
                    throw new RuntimeException(
                        "ANALYZE has no Analyzer registered for output type '{$outputType}'."
                    ),
            };


        if (
            isset($result['proposals'])
            && is_array(
                $result['proposals']
            )
        ) {
            $proposals =
                array_values(
                    $result['proposals']
                );

        } else {
            $proposals =
                array_values(
                    $result
                );
        }


        return [
            'proposals' =>
                $proposals,

            'pubcom' =>
                $pubComChannel
                    ->dispositionsAsArray(),
        ];
    }


    /**
     * PubCom authorization.
     */
    private function authorizeAnalyzer(
        PubComWorkerContract $analyzer,
        array $input
    ): array {
        $pubComChannel =
            new PubComChannel(
                $this
            );


        $analyzer->connectPubCom(
            $pubComChannel
        );


        /*
         * CLOCK IN.
         */
        $readiness =
            $pubComChannel->report(
                $analyzer->readiness()
            );


        if (
            !$readiness
                ->shouldContinue()
        ) {
            return [
                'channel' =>
                    $pubComChannel,

                'disposition' =>
                    $readiness,
            ];
        }


        /*
         * INSPECT ASSIGNMENT.
         */
        $preflight =
            $pubComChannel->report(
                $analyzer->preflight(
                    $input
                )
            );


        return [
            'channel' =>
                $pubComChannel,

            'disposition' =>
                $preflight,
        ];
    }


    /**
     * Pinterest channel eligibility.
     */
    private function pinterestItems(
        array $items
    ): array {
        return array_values(
            array_filter(
                $items,

                static fn(
                    array $item
                ): bool =>
                    !empty(
                        $item['pin']
                    )
            )
        );
    }


    /**
     * YouTube channel eligibility.
     */
    private function youtubeItems(
        array $items
    ): array {
        return array_values(
            array_filter(
                $items,

                static fn(
                    array $item
                ): bool =>
                    !empty(
                        $item['yt']
                    )
            )
        );
    }
}