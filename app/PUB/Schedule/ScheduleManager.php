<?php
declare(strict_types=1);

namespace App\PUB\Schedule;

use App\PUB\Dispatch\DispatchManager;
use App\PUB\Repos\PdoPubAssetRepository;
use App\PUB\Repos\PdoPubScheduleRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/**
 * SCHEDULE MANAGER
 *
 * Department head for the PUB loading dock.
 *
 * ScheduleManager owns the scheduling intelligence.
 *
 * It:
 *   - reads human-editable Schedule controls
 *   - checks each enabled publishing channel independently
 *   - reconstructs cadence/diversity state from durable shipped history
 *   - considers ONLY assets currently at pipeline_stage = queued
 *   - applies hard eligibility rules
 *   - ranks viable candidates using soft diversity rules
 *   - releases at most one asset per due channel per run
 *   - hands only the selected pub_asset_id to DispatchManager::shipOne()
 *
 * It does NOT:
 *   - create or package assets
 *   - open/map sealed package JSON
 *   - call Pinterest/YouTube APIs
 *   - maintain rotation pointers/cursors
 *   - remember "last type used"
 *   - catch up missed release intervals
 *
 * PACKED means sealed but held outside automatic Schedule.
 * QUEUED means explicitly released into Schedule's active candidate pool.
 *
 * Manual Send Now is a separate human override and does not come through
 * this automatic selection path.
 */
final class ScheduleManager
{
    private PdoPubScheduleRepository $schedule;
    private PdoPubAssetRepository $assets;
    private ?DispatchManager $dispatch = null;


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
                'Schedule Manager requires project root.'
            );
        }


        $this->schedule =
            new PdoPubScheduleRepository(
                $this->pdo
            );


        $this->assets =
            new PdoPubAssetRepository(
                $this->pdo
            );
    }


    /*
     * ========================================================
     * ADMIN CONTROL
     * ========================================================
     */

    public function getConfig(): array
    {
        return $this->schedule
            ->getConfig();
    }


    public function updateSettings(
        bool $schedulerEnabled,
        string $timezone
    ): array {
        return $this->schedule
            ->updateSettings(
                $schedulerEnabled,
                $timezone
            );
    }


    public function saveChannelRule(
        string $channel,
        bool $enabled,
        int $releaseIntervalMinutes,
        int $sameSourceMax,
        int $sameSourceWindowMinutes
    ): array {
        return $this->schedule
            ->saveChannelRule(
                $channel,
                $enabled,
                $releaseIntervalMinutes,
                $sameSourceMax,
                $sameSourceWindowMinutes
            );
    }


    /**
     * Explicit admin release into automatic Schedule.
     *
     *   packed -> queued
     */
    public function enqueueAsset(
        int $pubAssetId
    ): array {
        return $this->assets
            ->enqueue(
                $pubAssetId
            );
    }


    /**
     * Explicit admin hold/removal from automatic Schedule.
     *
     *   queued -> packed
     */
    public function dequeueAsset(
        int $pubAssetId
    ): array {
        return $this->assets
            ->dequeue(
                $pubAssetId
            );
    }


    /**
     * GOD-MODE MANUAL SEND NOW.
     *
     * This deliberately bypasses:
     *   - global Scheduler ON/OFF
     *   - per-channel ON/OFF
     *   - cadence timing
     *   - same-source limits
     *   - diversity/description ranking
     *
     * A PACKED asset is explicitly promoted into QUEUED immediately,
     * then handed straight to Dispatch. A QUEUED asset is handed directly.
     *
     * Manual shipments become ordinary shipped history afterward, so later
     * automatic Schedule runs naturally see them.
     */
    public function sendNow(
        int $pubAssetId
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Manual Send Now requires a valid pub_asset_id.'
            );
        }


        $asset =
            $this->assets
                ->getById(
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
                "PUB asset #{$pubAssetId} cannot Send Now from stage '{$stage}'."
            );
        }


        /*
         * Wake Dispatch before changing a PACKED row. If Dispatch itself
         * cannot even be constructed, the asset remains safely PACKED.
         */
        $dispatch =
            $this->dispatch();

        $promotedFromPacked =
            $stage === 'packed';


        if ($promotedFromPacked) {
            $this->assets
                ->enqueue(
                    $pubAssetId
                );
        }


        try {
            return $dispatch
                ->shipOne(
                    $pubAssetId
                );

        } catch (Throwable $e) {
            /*
             * If Dispatch never accepted custody, restore the explicit hold.
             * Once Dispatch has changed QUEUED -> SHIPPING (or ERROR/DISPATCH),
             * it owns the row and we do not pull it back.
             */
            if ($promotedFromPacked) {
                $current =
                    $this->assets
                        ->getById(
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


                if ($currentStage === 'queued') {
                    $this->assets
                        ->dequeue(
                            $pubAssetId
                        );
                }
            }


            throw $e;
        }
    }


    /**
     * Wake Schedule once.
     *
     * One run may release at most one asset for each due channel.
     * Missed intervals are never replayed as a catch-up burst.
     *
     * @return array{
     *   scheduler_enabled: bool,
     *   checked_at: string,
     *   lanes: array<int, array<string, mixed>>
     * }
     */
    public function run(): array
    {
        $config =
            $this->schedule
                ->getConfig();


        $settings =
            is_array(
                $config[
                    'settings'
                ]
                ?? null
            )
                ? $config[
                    'settings'
                ]
                : [];


        $timezoneName =
            trim(
                (string)(
                    $settings[
                        'timezone'
                    ]
                    ?? ''
                )
            );


        if ($timezoneName === '') {
            throw new RuntimeException(
                'Schedule timezone is not configured.'
            );
        }


        $timezone =
            new DateTimeZone(
                $timezoneName
            );


        $now =
            new DateTimeImmutable(
                'now',
                $timezone
            );


        $schedulerEnabled =
            (bool)(
                $settings[
                    'scheduler_enabled'
                ]
                ?? false
            );


        $rules =
            is_array(
                $config[
                    'channel_rules'
                ]
                ?? null
            )
                ? $config[
                    'channel_rules'
                ]
                : [];


        /*
         * The global master switch freezes automatic scheduling only.
         * PACKED and QUEUED inventory remains untouched.
         */
        if (!$schedulerEnabled) {
            return [
                'scheduler_enabled' =>
                    false,

                'checked_at' =>
                    $now->format(
                        DATE_ATOM
                    ),

                'lanes' =>
                    array_values(
                        array_map(
                            static fn (
                                array $rule
                            ): array => [
                                'channel' =>
                                    (string)(
                                        $rule[
                                            'channel'
                                        ]
                                        ?? ''
                                    ),

                                'action' =>
                                    'scheduler_disabled',

                                'selected_pub_asset_id' =>
                                    null,
                            ],

                            $rules
                        )
                    ),
            ];
        }


        /*
         * Load all shipped history once.
         *
         * This is the only "memory" Schedule uses.
         * It includes both automatic and manual shipments naturally.
         */
        $allHistory =
            $this->assets
                ->listShippedForSchedule();


        $lanes = [];


        foreach (
            $rules
            as $rule
        ) {
            if (!is_array($rule)) {
                continue;
            }


            $lanes[] =
                $this->runLane(
                    $rule,
                    $allHistory,
                    $now,
                    $timezone
                );
        }


        return [
            'scheduler_enabled' =>
                true,

            'checked_at' =>
                $now->format(
                    DATE_ATOM
                ),

            'lanes' =>
                $lanes,
        ];
    }


    /**
     * Evaluate one publishing channel independently.
     */
    private function runLane(
        array $rule,
        array $allHistory,
        DateTimeImmutable $now,
        DateTimeZone $timezone
    ): array {
        $channel =
            strtolower(
                trim(
                    (string)(
                        $rule[
                            'channel'
                        ]
                        ?? ''
                    )
                )
            );


        if ($channel === '') {
            throw new RuntimeException(
                'Schedule channel rule is missing channel.'
            );
        }


        if (
            !(
                $rule[
                    'enabled'
                ]
                ?? false
            )
        ) {
            return [
                'channel' =>
                    $channel,

                'action' =>
                    'channel_disabled',

                'selected_pub_asset_id' =>
                    null,
            ];
        }


        $releaseIntervalMinutes =
            $this->positiveRuleValue(
                $rule,
                'release_interval_minutes',
                $channel
            );


        $sameSourceMax =
            $this->positiveRuleValue(
                $rule,
                'same_source_max',
                $channel
            );


        $sameSourceWindowMinutes =
            $this->positiveRuleValue(
                $rule,
                'same_source_window_minutes',
                $channel
            );


        $history =
            array_values(
                array_filter(
                    $allHistory,

                    static fn (
                        array $row
                    ): bool =>
                        strtolower(
                            trim(
                                (string)(
                                    $row[
                                        'channel'
                                    ]
                                    ?? ''
                                )
                            )
                        ) ===
                        $channel
                )
            );


        $due =
            $this->channelDue(
                $history,
                $releaseIntervalMinutes,
                $now,
                $timezone
            );


        if (!$due['due']) {
            return [
                'channel' =>
                    $channel,

                'action' =>
                    'not_due',

                'selected_pub_asset_id' =>
                    null,

                'last_dispatched_at' =>
                    $due[
                        'last_dispatched_at'
                    ],

                'next_due_at' =>
                    $due[
                        'next_due_at'
                    ],
            ];
        }


        $queued =
            $this->assets
                ->listQueued(
                    $channel
                );


        if ($queued === []) {
            return [
                'channel' =>
                    $channel,

                'action' =>
                    'no_queued_assets',

                'selected_pub_asset_id' =>
                    null,
            ];
        }


        $eligible = [];


        foreach (
            $queued
            as $candidate
        ) {
            if (
                !$this->sourceLimitAllows(
                    $candidate,
                    $history,
                    $sameSourceMax,
                    $sameSourceWindowMinutes,
                    $now,
                    $timezone
                )
            ) {
                continue;
            }


            if (
                !$this->dependencySatisfied(
                    $candidate,
                    $allHistory
                )
            ) {
                continue;
            }


            $eligible[] =
                $candidate;
        }


        if ($eligible === []) {
            return [
                'channel' =>
                    $channel,

                'action' =>
                    'no_eligible_assets',

                'selected_pub_asset_id' =>
                    null,
            ];
        }


        $selected =
            $this->chooseCandidate(
                $channel,
                $eligible,
                $history,
                $timezone
            );


        $pubAssetId =
            (int)(
                $selected[
                    'pub_asset_id'
                ]
                ?? 0
            );


        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                "Schedule selected an invalid {$channel} pub_asset_id."
            );
        }


        /*
         * Final lifecycle re-check immediately before handoff.
         *
         * An admin may have dequeued the asset after this run loaded
         * the candidate list. Schedule must never override that choice.
         */
        $current =
            $this->assets
                ->getById(
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
            || $currentStage !== 'queued'
        ) {
            return [
                'channel' =>
                    $channel,

                'action' =>
                    'no_eligible_assets',

                'selected_pub_asset_id' =>
                    null,

                'note' =>
                    'Selected asset left QUEUED before Dispatch handoff.',
            ];
        }


        /*
         * Schedule's only downstream action:
         *
         *   "This queued box goes now."
         *
         * Dispatch owns queued -> shipping and every external API action.
         */
        $dispatchResult =
            $this->dispatch()
                ->shipOne(
                    $pubAssetId
                );


        return [
            'channel' =>
                $channel,

            'action' =>
                'released_to_dispatch',

            'selected_pub_asset_id' =>
                $pubAssetId,

            'dispatch' =>
                $dispatchResult,
        ];
    }


    /**
     * A channel is due when there is no prior successful shipment,
     * or the configured interval has elapsed since the most recent one.
     */
    private function channelDue(
        array $history,
        int $releaseIntervalMinutes,
        DateTimeImmutable $now,
        DateTimeZone $timezone
    ): array {
        $latest =
            $history[
                0
            ]
            ?? null;


        if (!is_array($latest)) {
            return [
                'due' =>
                    true,

                'last_dispatched_at' =>
                    null,

                'next_due_at' =>
                    null,
            ];
        }


        $last =
            $this->parseDatabaseDate(
                $latest[
                    'dispatched_at'
                ]
                ?? null,
                $timezone
            );


        if ($last === null) {
            /*
             * Malformed historical time should not permanently stop a lane.
             * The row remains history for diversity ranking, but cannot
             * provide a useful cadence boundary.
             */
            return [
                'due' =>
                    true,

                'last_dispatched_at' =>
                    null,

                'next_due_at' =>
                    null,
            ];
        }


        $nextDue =
            $last->modify(
                "+{$releaseIntervalMinutes} minutes"
            );


        return [
            'due' =>
                $now >= $nextDue,

            'last_dispatched_at' =>
                $last->format(
                    DATE_ATOM
                ),

            'next_due_at' =>
                $nextDue->format(
                    DATE_ATOM
                ),
        ];
    }


    /**
     * HARD RULE:
     *
     * Do not automatically publish more than X assets from the same
     * source_type + source_id to this channel during the rolling Y window.
     */
    private function sourceLimitAllows(
        array $candidate,
        array $channelHistory,
        int $sameSourceMax,
        int $sameSourceWindowMinutes,
        DateTimeImmutable $now,
        DateTimeZone $timezone
    ): bool {
        $sourceType =
            strtolower(
                trim(
                    (string)(
                        $candidate[
                            'source_type'
                        ]
                        ?? ''
                    )
                )
            );


        $sourceId =
            (int)(
                $candidate[
                    'source_id'
                ]
                ?? 0
            );


        if (
            $sourceType === ''
            || $sourceId <= 0
        ) {
            return false;
        }


        $windowStart =
            $now->modify(
                "-{$sameSourceWindowMinutes} minutes"
            );


        $count = 0;


        foreach (
            $channelHistory
            as $row
        ) {
            if (
                strtolower(
                    trim(
                        (string)(
                            $row[
                                'source_type'
                            ]
                            ?? ''
                        )
                    )
                ) !==
                    $sourceType
                ||
                (int)(
                    $row[
                        'source_id'
                    ]
                    ?? 0
                ) !==
                    $sourceId
            ) {
                continue;
            }


            $dispatchedAt =
                $this->parseDatabaseDate(
                    $row[
                        'dispatched_at'
                    ]
                    ?? null,
                    $timezone
                );


            if (
                $dispatchedAt === null
                || $dispatchedAt < $windowStart
                || $dispatchedAt > $now
            ) {
                continue;
            }


            $count++;


            if ($count >= $sameSourceMax) {
                return false;
            }
        }


        return true;
    }


    /**
     * HARD PRODUCT DEPENDENCY:
     *
     * A Pinterest teaser may not leave until its pingback destination
     * is already the external URL of a successfully shipped YouTube asset.
     *
     * No relationship/cursor table is required.
     */
    private function dependencySatisfied(
        array $candidate,
        array $allHistory
    ): bool {
        $channel =
            strtolower(
                trim(
                    (string)(
                        $candidate[
                            'channel'
                        ]
                        ?? ''
                    )
                )
            );


        $assetType =
            strtolower(
                trim(
                    (string)(
                        $candidate[
                            'asset_type'
                        ]
                        ?? ''
                    )
                )
            );


        if (
            $channel !== 'pinterest'
            || $assetType !== 'pin_teaser'
        ) {
            return true;
        }


        $pingback =
            $this->normalizeUrl(
                (string)(
                    $candidate[
                        'pingback'
                    ]
                    ?? ''
                )
            );


        if ($pingback === '') {
            return false;
        }


        foreach (
            $allHistory
            as $row
        ) {
            if (
                strtolower(
                    trim(
                        (string)(
                            $row[
                                'channel'
                            ]
                            ?? ''
                        )
                    )
                ) !== 'youtube'
            ) {
                continue;
            }


            $externalUrl =
                $this->normalizeUrl(
                    (string)(
                        $row[
                            'external_url'
                        ]
                        ?? ''
                    )
                );


            if (
                $externalUrl !== ''
                && $externalUrl === $pingback
            ) {
                return true;
            }
        }


        return false;
    }


    /**
     * SOFT SELECTION.
     *
     * Priority:
     *   1. avoid immediately repeating the same Pinterest description
     *   2. prefer underused asset types
     *   3. prefer least-recently-used asset types
     *   4. preserve lower source sort_order where otherwise comparable
     *   5. prefer older queued inventory
     *   6. stable pub_asset_id tie-break
     */
    private function chooseCandidate(
        string $channel,
        array $eligible,
        array $history,
        DateTimeZone $timezone
    ): array {
        $lastDescription =
            '';

        if (
            $channel === 'pinterest'
            && isset(
                $history[
                    0
                ]
            )
        ) {
            $lastDescription =
                $this->normalizeDescription(
                    (string)(
                        $history[
                            0
                        ][
                            'description'
                        ]
                        ?? ''
                    )
                );
        }


        /*
         * If any viable Pinterest candidate has a different description,
         * temporarily pass exact-description repeats.
         *
         * If every viable candidate repeats, keep them all so Schedule
         * never deadlocks merely because inventory is thin.
         */
        if (
            $channel === 'pinterest'
            && $lastDescription !== ''
        ) {
            $nonRepeats =
                array_values(
                    array_filter(
                        $eligible,

                        fn (
                            array $candidate
                        ): bool =>
                            $this->normalizeDescription(
                                (string)(
                                    $candidate[
                                        'description'
                                    ]
                                    ?? ''
                                )
                            ) !==
                            $lastDescription
                    )
                );


            if ($nonRepeats !== []) {
                $eligible =
                    $nonRepeats;
            }
        }


        $typeCounts = [];
        $typeLastUsed = [];


        foreach (
            $history
            as $row
        ) {
            $type =
                strtolower(
                    trim(
                        (string)(
                            $row[
                                'asset_type'
                            ]
                            ?? ''
                        )
                    )
                );


            if ($type === '') {
                continue;
            }


            $typeCounts[
                $type
            ] =
                (
                    $typeCounts[
                        $type
                    ]
                    ?? 0
                ) + 1;


            if (
                !array_key_exists(
                    $type,
                    $typeLastUsed
                )
            ) {
                /*
                 * History is newest first, so the first occurrence is the
                 * most recent use of this type.
                 */
                $typeLastUsed[
                    $type
                ] =
                    $this->dateTimestamp(
                        $row[
                            'dispatched_at'
                        ]
                        ?? null,
                        $timezone
                    );
            }
        }


        usort(
            $eligible,

            function (
                array $a,
                array $b
            ) use (
                $typeCounts,
                $typeLastUsed,
                $timezone
            ): int {
                $typeA =
                    strtolower(
                        trim(
                            (string)(
                                $a[
                                    'asset_type'
                                ]
                                ?? ''
                            )
                        )
                    );

                $typeB =
                    strtolower(
                        trim(
                            (string)(
                                $b[
                                    'asset_type'
                                ]
                                ?? ''
                            )
                        )
                    );


                $countA =
                    $typeCounts[
                        $typeA
                    ]
                    ?? 0;

                $countB =
                    $typeCounts[
                        $typeB
                    ]
                    ?? 0;


                if ($countA !== $countB) {
                    return $countA <=> $countB;
                }


                /*
                 * Never-used types get timestamp 0 and therefore sort first.
                 */
                $lastA =
                    $typeLastUsed[
                        $typeA
                    ]
                    ?? 0;

                $lastB =
                    $typeLastUsed[
                        $typeB
                    ]
                    ?? 0;


                if ($lastA !== $lastB) {
                    return $lastA <=> $lastB;
                }


                $sortA =
                    isset(
                        $a[
                            'sort_order'
                        ]
                    )
                    && $a[
                        'sort_order'
                    ] !== null
                        ? (int)$a[
                            'sort_order'
                        ]
                        : PHP_INT_MAX;

                $sortB =
                    isset(
                        $b[
                            'sort_order'
                        ]
                    )
                    && $b[
                        'sort_order'
                    ] !== null
                        ? (int)$b[
                            'sort_order'
                        ]
                        : PHP_INT_MAX;


                if (
                    strtolower(
                        trim(
                            (string)(
                                $a[
                                    'source_type'
                                ]
                                ?? ''
                            )
                        )
                    ) ===
                    strtolower(
                        trim(
                            (string)(
                                $b[
                                    'source_type'
                                ]
                                ?? ''
                            )
                        )
                    )
                    &&
                    (int)(
                        $a[
                            'source_id'
                        ]
                        ?? 0
                    ) ===
                    (int)(
                        $b[
                            'source_id'
                        ]
                        ?? 0
                    )
                    &&
                    $sortA !== $sortB
                ) {
                    return $sortA <=> $sortB;
                }


                $ageA =
                    $this->dateTimestamp(
                        $a[
                            'updated_at'
                        ]
                        ?? null,
                        $timezone
                    );

                $ageB =
                    $this->dateTimestamp(
                        $b[
                            'updated_at'
                        ]
                        ?? null,
                        $timezone
                    );


                if ($ageA !== $ageB) {
                    return $ageA <=> $ageB;
                }


                return
                    (int)(
                        $a[
                            'pub_asset_id'
                        ]
                        ?? 0
                    )
                    <=>
                    (int)(
                        $b[
                            'pub_asset_id'
                        ]
                        ?? 0
                    );
            }
        );


        return $eligible[
            0
        ];
    }


    private function dispatch(): DispatchManager
    {
        if ($this->dispatch === null) {
            $this->dispatch =
                new DispatchManager(
                    $this->pdo,
                    $this->projectRoot
                );
        }


        return $this->dispatch;
    }


    private function positiveRuleValue(
        array $rule,
        string $field,
        string $channel
    ): int {
        $value =
            (int)(
                $rule[
                    $field
                ]
                ?? 0
            );


        if ($value <= 0) {
            throw new RuntimeException(
                "Schedule channel '{$channel}' requires {$field} > 0."
            );
        }


        return $value;
    }


    private function normalizeDescription(
        string $description
    ): string {
        $description =
            mb_strtolower(
                trim(
                    $description
                ),
                'UTF-8'
            );


        if ($description === '') {
            return '';
        }


        $description =
            preg_replace(
                '/[^\p{L}\p{N}\s]+/u',
                ' ',
                $description
            )
            ?? $description;


        return trim(
            preg_replace(
                '/\s+/u',
                ' ',
                $description
            )
            ?? $description
        );
    }


    private function normalizeUrl(
        string $url
    ): string {
        $url =
            trim(
                $url
            );


        if ($url === '') {
            return '';
        }


        /*
         * Ignore a trailing slash only.
         *
         * Do not strip query parameters: the actual durable YouTube
         * destination is expected to match the packaged teaser pingback.
         */
        return rtrim(
            $url,
            '/'
        );
    }


    private function parseDatabaseDate(
        mixed $value,
        DateTimeZone $timezone
    ): ?DateTimeImmutable {
        $text =
            trim(
                (string)$value
            );


        if ($text === '') {
            return null;
        }


        try {
            return new DateTimeImmutable(
                $text,
                $timezone
            );

        } catch (Throwable) {
            return null;
        }
    }


    private function dateTimestamp(
        mixed $value,
        DateTimeZone $timezone
    ): int {
        return $this->parseDatabaseDate(
            $value,
            $timezone
        )
            ?->getTimestamp()
            ?? 0;
    }
}
