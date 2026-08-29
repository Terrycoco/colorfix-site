<?php
declare(strict_types=1);

namespace App\PUB\Analyze;

use App\PUB\Analyze\Pinterest\BeforeAfterVideoAnalyzer;
use App\PUB\Analyze\Pinterest\CompositeAnalyzer;
use App\PUB\Analyze\Pinterest\IdeaAnalyzer;
use App\PUB\Analyze\Pinterest\PaletteAnalyzer;
use App\PUB\Analyze\Pinterest\YouTubeTeaserAnalyzer;
use App\PUB\Analyze\Pinterest\Support\PlaylistPaletteResolver;
use App\PUB\Analyze\Support\DefaultPantry;
use App\PUB\Analyze\Sources\PlaylistSourcePreparer;
use App\PUB\Analyze\YouTube\PlaylistVideoAnalyzer;
use App\PUB\Contracts\PubContract;
use App\PUB\Services\PubRunService;
use App\PUB\Repos\PdoPubAssetRepository;
use App\PUB\Repos\PdoPubRunRepository;
use App\Repos\PdoPlaylistRepository;
use App\PV\PVService;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReservationRelationships;
use App\REX\Services\RexReserver;
use App\REX\Services\RexTokenGenerator;
use App\Services\ViewerService;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComDisposition;
use App\PUB\PubCom\PubComManagerContract;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use PDO;
use RuntimeException;
use Throwable;

final class AnalyzeManager implements PubComManagerContract
{
    /*
     * LAZY DEPARTMENT STAFFING
     *
     * The endpoint wakes only the Manager.
     * The Manager creates Procurement, PubRun infrastructure, and the
     * requested specialist Analyzer only when the assignment reaches
     * the point where each is actually needed.
     *
     * Objects are memoized for this Manager/request so a future
     * multi-output ANALYZE call can reuse Procurement and any worker
     * already clocked in during the same batch.
     */
    private ?PubRunService $runService = null;
    private ?PlaylistSourcePreparer $playlistSourcePreparer = null;
    private ?DefaultPantry $defaultPantry = null;

    /** @var array<string, PubComWorkerContract> */
    private array $analyzers = [];

    /** @var array<string, array<string, mixed>> */
    private array $preparedSources = [];


    public function __construct(
        private PDO $pdo,
        private string $projectRoot,
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
        /*
         * LEGACY CALL-SHAPE COMPATIBILITY.
         *
         * The endpoint may still supply runMode / overwritePubRunId for now,
         * but ANALYZE no longer branches on either value.
         *
         * Re-analysis is always allowed and always starts a fresh PUB run.
         * Historical duplicate detection belongs exclusively at the CREATE
         * front door via production_signature.
         */
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

        $pubRunId = 0;
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
             * NEW ANALYSIS RUN.
             *
             * ANALYZE does not inspect prior PUB runs or shipped history.
             * Re-analysis is harmless and may happen repeatedly.
             *
             * CREATE owns duplicate manufacturing protection. When these
             * sealed boxes later reach CREATE, production_signature history
             * decides whether to warn about an already-shipped product.
             */
            $pubRunId =
                $this->runService()
                    ->start(
                        $sourceType,
                        $sourceId,
                        $outputType,
                        0
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
                            'youtube_video',
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
     * Convert source into canonical PUB market material.
     */
    private function prepareSource(
        string $sourceType,
        int $sourceId
    ): array {
        /*
         * One procurement trip per source per Manager wake.
         *
         * Today analyze() receives one output type. If ANALYZE later
         * accepts several output types in one request, every specialist
         * can work from this same prepared market haul instead of
         * reloading the Playlist/PV source for each output.
         */
        $cacheKey =
            $sourceType
            . ':'
            . $sourceId;


        if (isset(
            $this->preparedSources[
                $cacheKey
            ]
        )) {
            return $this->preparedSources[
                $cacheKey
            ];
        }


        $prepared =
            match (
                $sourceType
            ) {
                'playlist' =>
                    $this
                        ->playlistSourcePreparer()
                        ->prepare(
                            $sourceId
                        ),

                default =>
                    throw new RuntimeException(
                        "ANALYZE has no source preparer registered for '{$sourceType}'."
                    ),
            };


        $this->preparedSources[
            $cacheKey
        ] = $prepared;


        return $prepared;
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
         * WAKE SPECIALIST.
         *
         * This is the first moment a recipe-specific Analyzer is
         * needed, so this is where the Manager clocks that worker in.
         */
        $analyzer =
            $this->analyzerForOutput(
                $outputType
            );


        /*
         * PREFLIGHT INPUT.
         *
         * Migrated Pinterest analyzers receive the complete
         * Pinterest market source. YouTube Playlist Video receives
         * the complete YouTube channel market source.
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
                    $channelSource,

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


        $analyzer =
            $assignment['analyzer']
            ?? null;


        if (
            !$analyzer instanceof
            PubComWorkerContract
        ) {
            throw new RuntimeException(
                'ANALYZE authorized assignment has no Analyzer.'
            );
        }


        /*
         * The exact SAME worker that passed readiness/preflight now
         * performs the assignment. No second Analyzer is instantiated.
         */
        $analyzeInput =
            match (
                $outputType
            ) {
                'composite',
                'before_after_video',
                'idea',
                'idea_palette',
                'youtube_video' =>
                    $channelSource,

                'youtube_teaser_pin' =>
                    $eligibleItems,

                default =>
                    $source,
            };


        if (!method_exists(
            $analyzer,
            'analyze'
        )) {
            throw new RuntimeException(
                "ANALYZE worker '{$outputType}' has no analyze() method."
            );
        }


        $result =
            $analyzer->analyze(
                $analyzeInput
            );


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
     * Department administrative infrastructure.
     *
     * Wakes only if ANALYZE gets far enough to open a fresh PUB run.
     */
    private function runService(): PubRunService
    {
        if ($this->runService === null) {
            $this->runService =
                new PubRunService(
                    new PdoPubRunRepository(
                        $this->pdo
                    ),
                    new PdoPubAssetRepository(
                        $this->pdo
                    )
                );
        }


        return $this->runService;
    }


    /**
     * Wake Playlist procurement only when a Playlist source is actually
     * requested. The same preparer remains available for the rest of the
     * current Manager/request.
     */
    private function playlistSourcePreparer(): PlaylistSourcePreparer
    {
        if ($this->playlistSourcePreparer === null) {
            $this->playlistSourcePreparer =
                new PlaylistSourcePreparer(
                    new PdoPlaylistRepository(
                        $this->pdo
                    ),
                    new PVService(
                        $this->pdo
                    )
                );
        }


        return $this->playlistSourcePreparer;
    }


    /**
     * Wake only the specialist required by the requested output type.
     *
     * Workers are cached for this Manager/request. That matters when a
     * future ANALYZE batch asks the same specialist to handle multiple
     * assignments: the worker clocks in once for that batch.
     */
    private function analyzerForOutput(
        string $outputType
    ): PubComWorkerContract {
        if (isset(
            $this->analyzers[
                $outputType
            ]
        )) {
            return $this->analyzers[
                $outputType
            ];
        }


        $analyzer =
            match (
                $outputType
            ) {
                'composite' =>
                    new CompositeAnalyzer(),

                'before_after_video' =>
                    new BeforeAfterVideoAnalyzer(),

                'idea' =>
                    new IdeaAnalyzer(),

                'idea_palette' =>
                    $this->makePaletteAnalyzer(),

                'youtube_video' =>
                    new PlaylistVideoAnalyzer(
                        $this->defaultPantry()
                    ),

                'youtube_teaser_pin' =>
                    new YouTubeTeaserAnalyzer(),

                default =>
                    throw new RuntimeException(
                        "ANALYZE has no Analyzer registered for output type '{$outputType}'."
                    ),
            };


        $this->analyzers[
            $outputType
        ] = $analyzer;


        return $analyzer;
    }


    /**
     * Shared ANALYZE default pantry.
     *
     * Wake it only when a specialist actually needs a declared fallback
     * ingredient. Today that is YouTube music.
     *
     * The pantry may resolve Asset Library references. It returns prepared
     * ingredient shapes; Creators never receive pantry IDs or perform lookups.
     */
    private function defaultPantry(): DefaultPantry
    {
        if ($this->defaultPantry === null) {
            $this->defaultPantry =
                new DefaultPantry(
                    $this->pdo,
                    $this->projectRoot
                );
        }


        return $this->defaultPantry;
    }


    /**
     * Palette Analyzer has department equipment of its own. Build that
     * equipment only when this specialist is actually assigned work.
     */
    private function makePaletteAnalyzer(): PaletteAnalyzer
    {
        $rexRepository =
            new PdoRexReservationRepository(
                $this->pdo
            );

        $playlistPaletteResolver =
            new PlaylistPaletteResolver(
                $rexRepository,
                new RexReservationRelationships(
                    $rexRepository
                ),
                new RexReserver(
                    $rexRepository,
                    new RexTokenGenerator()
                ),
                new ViewerService(
                    $this->pdo
                )
            );


        return new PaletteAnalyzer(
            $playlistPaletteResolver
        );
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