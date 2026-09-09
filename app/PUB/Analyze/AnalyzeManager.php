<?php
declare(strict_types=1);

namespace App\PUB\Analyze;

use App\PUB\Analyze\Pinterest\BeforeAfterVideoAnalyzer;
use App\PUB\Analyze\Pinterest\CompositeAnalyzer;
use App\PUB\Analyze\Pinterest\IdeaAnalyzer;
use App\PUB\Analyze\Pinterest\PaletteAnalyzer;
use App\PUB\Analyze\Pinterest\TeaserAnalyzer;
use App\PUB\Analyze\Support\DefaultPantry;
use App\PUB\Analyze\Sources\PlaylistSourcePreparer;
use App\PUB\Analyze\YouTube\PlaylistVideoAnalyzer;
use App\PUB\Contracts\PubContract;
use App\PUB\Services\PubRunService;
use App\PUB\Repos\PdoPubAssetRepository;
use App\PUB\Repos\PdoPubRunRepository;
use App\Repos\PdoPlaylistRepository;
use App\PV\PVService;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComDisposition;
use App\PUB\PubCom\PubComManagerContract;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use PDO;
use RuntimeException;
use Throwable;

/**
 * ANALYZE MANAGER
 *
 * One call represents one complete ANALYZE execution against one source.
 *
 *   1. Open one pub_run.
 *   2. Order one neutral Market delivery.
 *   3. Give that exact delivery to every Analyzer declared by PubContract.
 *   4. Let each Analyzer perform its own channel/recipe cull.
 *   5. Collect every proposal into one mixed stack of PUB Boxes.
 *   6. Stamp every Box with the same pub_run_id.
 *
 * The Manager does not filter pin/yt items and does not choose one output
 * type on behalf of the caller.
 */
final class AnalyzeManager implements PubComManagerContract
{
    private ?PubRunService $runService = null;
    private ?PlaylistSourcePreparer $playlistSourcePreparer = null;
    private ?DefaultPantry $defaultPantry = null;
    private ?PdoPubAssetRepository $assetRepository = null;

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

        /*
         * STOP_LINE now means stop THIS specialist assignment only.
         * The mixed ANALYZE run continues with the other specialists.
         */
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
     * Run the complete ANALYZE department against one source.
     *
     * @return array<string, mixed>
     */
    public function analyze(
        string $sourceType,
        int $sourceId
    ): array {
        $sourceType = strtolower(
            trim($sourceType)
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

        $pubRunId = 0;
        $boxes = [];
        $failed = [];
        $skipped = [];
        $pubCom = [];
        $existingAssetMatches = [];
        $producedTypes = [];

        try {
            /*
             * ONE RUN = ONE ANALYZE EXECUTION AGAINST THIS SOURCE.
             */
            $pubRunId = $this->runService()
                ->start(
                    $sourceType,
                    $sourceId,
                    0
                );

            if ($pubRunId <= 0) {
                throw new RuntimeException(
                    'ANALYZE could not establish a valid pub_run_id.'
                );
            }

            /*
             * ONE MORNING MARKET RUN.
             *
             * Procurement brings every active item and its authored flags.
             * This array is held once and handed unchanged to every worker.
             */
            $preparedSource = $this->prepareSource(
                $sourceType,
                $sourceId
            );

            $contracts = PubContract::assetTypes(
                'analyze'
            );

            if ($contracts === []) {
                throw new RuntimeException(
                    'ANALYZE has no specialist contracts registered.'
                );
            }

            foreach ($contracts as $outputType => $contract) {
                if (!is_array($contract)) {
                    $failed[] = [
                        'pub_run_id' => $pubRunId,
                        'output_type' => (string)$outputType,
                        'source_type' => $sourceType,
                        'source_id' => $sourceId,
                        'error' => 'ANALYZE specialist contract is invalid.',
                    ];

                    continue;
                }

                $outputType = strtolower(
                    trim((string)$outputType)
                );

                $channel = strtolower(
                    trim(
                        (string)(
                            $contract['channel']
                            ?? ''
                        )
                    )
                );

                if ($outputType === '' || $channel === '') {
                    $failed[] = [
                        'pub_run_id' => $pubRunId,
                        'output_type' => $outputType,
                        'source_type' => $sourceType,
                        'source_id' => $sourceId,
                        'error' => 'ANALYZE specialist contract is missing its output key or channel.',
                    ];

                    continue;
                }

                try {
                    /*
                     * Every specialist receives the SAME complete Market box.
                     * The Manager performs no channel cull.
                     */
                    $assignment = $this->prepareAnalyzerAssignment(
                        $outputType,
                        $preparedSource
                    );

                    /** @var PubComChannel $pubComChannel */
                    $pubComChannel = $assignment['channel'];

                    /** @var PubComDisposition $disposition */
                    $disposition = $assignment['disposition'];

                    $assignmentPubCom = $pubComChannel
                        ->dispositionsAsArray();

                    $pubCom = [
                        ...$pubCom,
                        ...$assignmentPubCom,
                    ];

                    if (!$disposition->shouldContinue()) {
                        $skipped[] = [
                            'pub_run_id' => $pubRunId,
                            'output_type' => $outputType,
                            'channel' => $channel,
                            'source_type' => $sourceType,
                            'source_id' => $sourceId,
                            'reason' => $disposition
                                ->signal()
                                ->message(),
                        ];

                        continue;
                    }

                    $specialist = $this->runAuthorizedAnalyzer(
                        $outputType,
                        $preparedSource,
                        $assignment
                    );

                    $specialistPubCom = is_array(
                        $specialist['pubcom']
                        ?? null
                    )
                        ? $specialist['pubcom']
                        : [];

                    /*
                     * authorizeAnalyzer() already contributed the same
                     * channel dispositions above. Do not duplicate them here.
                     */

                    $proposals = is_array(
                        $specialist['proposals']
                        ?? null
                    )
                        ? $specialist['proposals']
                        : [];

                    foreach ($proposals as $proposalIndex => $proposal) {
                        if (!is_array($proposal)) {
                            $failed[] = [
                                'pub_run_id' => $pubRunId,
                                'output_type' => $outputType,
                                'channel' => $channel,
                                'source_type' => $sourceType,
                                'source_id' => $sourceId,
                                'error' => "Analyzer '{$outputType}' returned an invalid proposal.",
                            ];

                            continue;
                        }

                        $assetType = trim(
                            (string)(
                                $proposal['asset_type']
                                ?? ''
                            )
                        );

                        if ($assetType === '') {
                            $failed[] = [
                                'pub_run_id' => $pubRunId,
                                'output_type' => $outputType,
                                'channel' => $channel,
                                'source_type' => $sourceType,
                                'source_id' => $sourceId,
                                'error' => "Analyzer '{$outputType}' returned a proposal with no asset_type.",
                            ];

                            continue;
                        }

                        /*
                         * DURABLE LOGICAL SIBLING ORDER.
                         *
                         * Most specialists do not need to author sort_order
                         * explicitly. Historically the single-output workbench
                         * supplied proposalIndex + 1 before CREATE, so existing
                         * durable assets are keyed that way.
                         *
                         * Mixed ANALYZE must preserve the same identity here,
                         * BEFORE predecessor lookup. The index is local to this
                         * specialist/output, so Composite #1, Idea #1, etc. each
                         * retain their own sibling sequence.
                         */
                        $proposalSortOrder =
                            isset($proposal['sort_order'])
                            && $proposal['sort_order'] !== null
                                ? (int)$proposal['sort_order']
                                : ((int)$proposalIndex + 1);

                        /*
                         * Every specialist is now migrated to the standard
                         * proposal vocabulary. Nothing else may leak into the
                         * outer PUB Box.
                         */
                        $proposal = array_intersect_key(
                            $proposal,
                            array_flip([
                                'asset_type',
                                'sort_order',
                                'search_title',
                                'description',
                                'estimated_duration_ms',
                                'ingredients',
                            ])
                        );

                        $box = [
                            'pub_run_id' => $pubRunId,
                            'channel' => $channel,
                            'asset_type' => '',
                            'source_type' => $sourceType,
                            'source_id' => $sourceId,
                            'sort_order' => $proposalSortOrder,
                            'search_title' => '',
                            'description' => '',
                            'estimated_duration_ms' => null,
                            'pingback' => '',
                            'ingredients' => [],
                        ];

                        $nextBox = [
                            ...$box,
                            ...$proposal,

                            /* Factory identity is always Manager-owned. */
                            'pub_run_id' => $pubRunId,
                            'channel' => $channel,
                            'source_type' => $sourceType,
                            'source_id' => $sourceId,
                            'sort_order' => $proposalSortOrder,
                        ];

                        /*
                         * LOGICAL PREDECESSOR LOOKUP.
                         *
                         * This remains per Box, not per run. Re-analysis
                         * therefore carries forward the latest durable outside
                         * copy even though every Analyze click gets a fresh run.
                         */
                        $logicalMatch = $this->logicalAssetMatch(
                            $sourceType,
                            $sourceId,
                            (string)$nextBox['asset_type'],
                            is_array(
                                $nextBox['ingredients']
                                ?? null
                            )
                                ? $nextBox['ingredients']
                                : []
                        );

                        if ($logicalMatch !== null) {
                            $existingAsset = $logicalMatch['existing_asset'];

                            $nextBox['search_title'] =
                                (string)(
                                    $existingAsset['search_title']
                                    ?? ''
                                );

                            $nextBox['description'] =
                                (string)(
                                    $existingAsset['description']
                                    ?? ''
                                );

                            $existingAssetMatches[] = [
                                'output_type' => $outputType,
                                'order_index' => (int)$proposalIndex,
                                'existing_state' =>
                                    $logicalMatch['existing_state'],
                                'asset_type' =>
                                    (string)$nextBox['asset_type'],
                                'source_type' => $sourceType,
                                'source_id' => $sourceId,
                                'sort_order' => $proposalSortOrder,
                                'existing_asset' => $existingAsset,
                            ];
                        }

                        $boxes[] = $nextBox;
                        $producedTypes[$outputType] = true;
                    }

                } catch (Throwable $e) {
                    /*
                     * One broken/ineligible product line must never prevent
                     * the other specialists from inspecting the delivery.
                     */
                    $failed[] = [
                        'pub_run_id' => $pubRunId,
                        'output_type' => $outputType,
                        'channel' => $channel,
                        'source_type' => $sourceType,
                        'source_id' => $sourceId,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $this->runService()
                ->setExpectedCount(
                    $pubRunId,
                    count($boxes)
                );

            /*
             * A whole Analyze execution that produces no Boxes is a failed
             * run. Zero teaser proposals alone are NOT a failure; Teaser is
             * optional and the other specialists continue normally.
             */
            if ($boxes === []) {
                $this->runService()
                    ->complete(
                        $pubRunId,
                        0,
                        max(1, count($failed))
                    );
            }

        } catch (Throwable $e) {
            if ($pubRunId > 0) {
                try {
                    $this->runService()
                        ->setExpectedCount(
                            $pubRunId,
                            0
                        );

                    $this->runService()
                        ->complete(
                            $pubRunId,
                            0,
                            1
                        );

                } catch (Throwable) {
                    /* Preserve the original ANALYZE failure. */
                }
            }

            $failed[] = [
                'pub_run_id' =>
                    $pubRunId > 0
                        ? $pubRunId
                        : null,
                'output_type' => null,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'code' => 'analyzed',
            'pub_run_id' => $pubRunId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'output_types' => array_keys($producedTypes),
            'boxes' => $boxes,
            'existing_asset_matches' => $existingAssetMatches,
            'failed' => $failed,
            'skipped' => $skipped,
            'pubcom' => $pubCom,
        ];
    }


    /**
     * Find the current in-house predecessor for one logical output.
     *
     * Logical identity is intentionally NOT sort_order.
     *
     * Match rules:
     *
     *   source_type
     *   source_id
     *   asset_type
     *   + photo_library_id when the product has one
     *
     * The newly analyzed Box always owns the current sort_order and current
     * ingredients. A match is used only to carry forward durable outside copy
     * and to identify an existing in-house row that CREATE may replace.
     *
     * Historical shipping/shipped/dispatched/published rows are excluded by
     * the repository. Once an asset has left the building, re-analysis starts
     * fresh.
     *
     * Older in-house orders created before photo_library_id was preserved in
     * Creator ingredients may be matched by the same subject file_path as a
     * one-time legacy fallback. New orders should match by photo_library_id.
     *
     * @return array<string, mixed>|null
     */
    private function logicalAssetMatch(
        string $sourceType,
        int $sourceId,
        string $assetType,
        array $newIngredients
    ): ?array {
        $candidates = $this->assetRepository()
            ->listInHouseLogicalAssetCandidates(
                $sourceType,
                $sourceId,
                $assetType
            );

        if ($candidates === []) {
            return null;
        }

        $newSubject = $this->subjectIdentity(
            $newIngredients
        );

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $candidateIngredients = is_array(
                $candidate['ingredients']
                ?? null
            )
                ? $candidate['ingredients']
                : [];

            $candidateSubject = $this->subjectIdentity(
                $candidateIngredients
            );

            if (!$this->sameLogicalSubject(
                $newSubject,
                $candidateSubject
            )) {
                continue;
            }

            return [
                'existing_state' => 'unshipped',
                'existing_asset' => [
                    'pub_asset_id' =>
                        (int)(
                            $candidate['pub_asset_id']
                            ?? 0
                        ),
                    'pipeline_stage' =>
                        (string)(
                            $candidate['pipeline_stage']
                            ?? ''
                        ),
                    'search_title' =>
                        (string)(
                            $candidate['search_title']
                            ?? ''
                        ),
                    'description' =>
                        (string)(
                            $candidate['description']
                            ?? ''
                        ),
                ],
            ];
        }

        return null;
    }


    /**
     * Extract the stable photo-backed subject from one Creator ingredient box.
     *
     * Single-photo products use ingredients.source.
     * Before/After products use ingredients.after because the After photo owns
     * the linked PV copy for those products.
     *
     * Products with no single photo subject (for example one YouTube playlist
     * video per source) return an empty subject and therefore match by
     * source/type only.
     *
     * @return array{photo_library_id:int|null,file_path:string|null}
     */
    private function subjectIdentity(
        array $ingredients
    ): array {
        $subject = [];

        if (
            isset($ingredients['source'])
            && is_array($ingredients['source'])
        ) {
            $subject = $ingredients['source'];

        } elseif (
            isset($ingredients['after'])
            && is_array($ingredients['after'])
        ) {
            $subject = $ingredients['after'];
        }

        $photoLibraryId =
            isset($subject['photo_library_id'])
            && (int)$subject['photo_library_id'] > 0
                ? (int)$subject['photo_library_id']
                : null;

        $filePath = trim(
            (string)(
                $subject['file_path']
                ?? ''
            )
        );

        return [
            'photo_library_id' => $photoLibraryId,
            'file_path' => $filePath !== ''
                ? $filePath
                : null,
        ];
    }


    /**
     * Compare the actual logical subject of two same-source/same-type Boxes.
     *
     * If the new Box has a photo ID, that ID is authoritative. The file path
     * fallback exists only so already-created in-house orders from before the
     * photo-ID preservation fix can still retain their copy on the first
     * re-analysis after deployment.
     *
     * If the product has no single photo subject, source + asset_type is the
     * complete logical identity; the repository has already applied those
     * constraints before candidates reach this method.
     */
    private function sameLogicalSubject(
        array $newSubject,
        array $candidateSubject
    ): bool {
        $newPhotoId =
            isset($newSubject['photo_library_id'])
            && $newSubject['photo_library_id'] !== null
                ? (int)$newSubject['photo_library_id']
                : null;

        $candidatePhotoId =
            isset($candidateSubject['photo_library_id'])
            && $candidateSubject['photo_library_id'] !== null
                ? (int)$candidateSubject['photo_library_id']
                : null;

        if ($newPhotoId !== null) {
            if ($candidatePhotoId !== null) {
                return $newPhotoId === $candidatePhotoId;
            }

            $newFilePath = trim(
                (string)(
                    $newSubject['file_path']
                    ?? ''
                )
            );

            $candidateFilePath = trim(
                (string)(
                    $candidateSubject['file_path']
                    ?? ''
                )
            );

            return
                $newFilePath !== ''
                && $candidateFilePath !== ''
                && $newFilePath === $candidateFilePath;
        }

        return true;
    }


    /**
     * Convert source into canonical PUB Market material.
     * One procurement trip per source per Manager wake.
     */
    private function prepareSource(
        string $sourceType,
        int $sourceId
    ): array {
        $cacheKey = $sourceType . ':' . $sourceId;

        if (isset($this->preparedSources[$cacheKey])) {
            return $this->preparedSources[$cacheKey];
        }

        $prepared = match ($sourceType) {
            'playlist' =>
                $this->playlistSourcePreparer()
                    ->prepare($sourceId),

            default =>
                throw new RuntimeException(
                    "ANALYZE has no source preparer registered for '{$sourceType}'."
                ),
        };

        $this->preparedSources[$cacheKey] = $prepared;

        return $prepared;
    }


    /**
     * Wake one specialist and authorize it against the complete, unchanged
     * Market delivery. Channel and recipe culling belong to the specialist.
     */
    private function prepareAnalyzerAssignment(
        string $outputType,
        array $source
    ): array {
        $analyzer = $this->analyzerForOutput(
            $outputType
        );

        $gate = $this->authorizeAnalyzer(
            $analyzer,
            $source
        );

        return [
            'analyzer' => $analyzer,
            'channel' => $gate['channel'],
            'disposition' => $gate['disposition'],
        ];
    }


    /**
     * Run the exact worker that passed readiness/preflight.
     */
    private function runAuthorizedAnalyzer(
        string $outputType,
        array $source,
        array $assignment
    ): array {
        $pubComChannel = $assignment['channel'] ?? null;

        if (!$pubComChannel instanceof PubComChannel) {
            throw new RuntimeException(
                'ANALYZE authorized assignment has no PubCom channel.'
            );
        }

        $analyzer = $assignment['analyzer'] ?? null;

        if (!$analyzer instanceof PubComWorkerContract) {
            throw new RuntimeException(
                'ANALYZE authorized assignment has no Analyzer.'
            );
        }

        if (!method_exists($analyzer, 'analyze')) {
            throw new RuntimeException(
                "ANALYZE worker '{$outputType}' has no analyze() method."
            );
        }

        $result = $analyzer->analyze(
            $source
        );

        if (
            isset($result['proposals'])
            && is_array($result['proposals'])
        ) {
            $proposals = array_values(
                $result['proposals']
            );
        } else {
            $proposals = array_values($result);
        }

        return [
            'proposals' => $proposals,
            'pubcom' => $pubComChannel
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
        $pubComChannel = new PubComChannel(
            $this
        );

        $analyzer->connectPubCom(
            $pubComChannel
        );

        $readiness = $pubComChannel->report(
            $analyzer->readiness()
        );

        if (!$readiness->shouldContinue()) {
            return [
                'channel' => $pubComChannel,
                'disposition' => $readiness,
            ];
        }

        $preflight = $pubComChannel->report(
            $analyzer->preflight(
                $input
            )
        );

        return [
            'channel' => $pubComChannel,
            'disposition' => $preflight,
        ];
    }


    private function assetRepository(): PdoPubAssetRepository
    {
        if ($this->assetRepository === null) {
            $this->assetRepository = new PdoPubAssetRepository(
                $this->pdo
            );
        }

        return $this->assetRepository;
    }


    private function runService(): PubRunService
    {
        if ($this->runService === null) {
            $this->runService = new PubRunService(
                new PdoPubRunRepository(
                    $this->pdo
                ),
                $this->assetRepository()
            );
        }

        return $this->runService;
    }


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
     * Analyze-stage specialist registry.
     *
     * PubContract decides which output lines are walked; this registry maps
     * each contract key to the worker implementation for that line.
     */
    private function analyzerForOutput(
        string $outputType
    ): PubComWorkerContract {
        if (isset($this->analyzers[$outputType])) {
            return $this->analyzers[$outputType];
        }

        $analyzer = match ($outputType) {
            'composite' =>
                new CompositeAnalyzer(),

            'before_after_video' =>
                new BeforeAfterVideoAnalyzer(),

            'idea' =>
                new IdeaAnalyzer(),

            'idea_palette' =>
                new PaletteAnalyzer(),

            'teaser' =>
                new TeaserAnalyzer(),

            'youtube_video' =>
                new PlaylistVideoAnalyzer(
                    $this->defaultPantry()
                ),

            default =>
                throw new RuntimeException(
                    "ANALYZE has no Analyzer registered for output type '{$outputType}'."
                ),
        };

        $this->analyzers[$outputType] = $analyzer;

        return $analyzer;
    }


    private function defaultPantry(): DefaultPantry
    {
        if ($this->defaultPantry === null) {
            $this->defaultPantry = new DefaultPantry(
                $this->pdo,
                $this->projectRoot
            );
        }

        return $this->defaultPantry;
    }
}
