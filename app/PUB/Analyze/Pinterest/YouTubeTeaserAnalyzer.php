<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Pinterest;

use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use App\PUB\Analyze\Pinterest\Support\TransformationPairFinder;

/**
 * PINTEREST YOUTUBE TEASER ANALYZER
 *
 * Owns ONLY the eligibility/proposal rules for the
 * Pinterest YouTube Teaser format.
 *
 * Shared Before -> After pairing is provided by
 * TransformationPairFinder.
 *
 * One transformation pair produces one possible teaser.
 *
 * IMPORTANT:
 * The published teaser visually reveals ONLY the Before.
 *
 * The After is carried as context so CREATE/PACKAGE can:
 *   - identify the transformation being promoted
 *   - generate transformation-specific teaser copy
 *   - associate the teaser with the correct YouTube content
 *
 * The actual YouTube destination is NOT decided here.
 * That belongs to PACKAGE.
 *
 * Input:
 *   Pinterest-eligible, PUB-prepared playlist items.
 *
 * Output:
 *   YouTube Teaser AnalysisProposal-shaped arrays.
 *
 * PUBCOM:
 *
 *   - reports worker readiness
 *   - preflights the assignment before production
 *   - may communicate production conditions upward
 *     through its assigned PubComChannel
 */
final class YouTubeTeaserAnalyzer implements PubComWorkerContract
{
    private ?PubComChannel $pubComChannel = null;


    public function __construct(
        private ?TransformationPairFinder $pairFinder = null
    ) {}


    /**
     * PUBCOM ONBOARDING
     *
     * Connect this worker to the communication channel
     * owned by the Manager supervising the current work.
     */
    public function connectPubCom(
        PubComChannel $channel
    ): void {
        $this->pubComChannel =
            $channel;
    }


    /**
     * PUBCOM READINESS
     *
     * This Analyzer has no external workstation or
     * service dependency requiring a separate
     * availability check.
     */
    public function readiness(): PubComSignal
    {
        return PubComSignal::ready(
            'YouTube Teaser Analyzer is ready.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * PUBCOM PREFLIGHT
     *
     * YouTube Teaser requires usable Before and After
     * material from which TransformationPairFinder can
     * form at least one valid transformation pair.
     *
     * The teaser's eventual YouTube destination is NOT
     * part of ANALYZE eligibility. PACKAGE owns that.
     *
     * Pairing rules remain entirely inside
     * TransformationPairFinder.
     */
    public function preflight(
        array $pinItems
    ): PubComSignal {
        $beforeCount =
            $this->countRole(
                $pinItems,
                'before'
            );

        $afterCount =
            $this->countRole(
                $pinItems,
                'after'
            );


        if ($beforeCount === 0) {
            return PubComSignal::ineligible(
                'youtube_teaser_no_before_slides',
                'YouTube Teaser cannot be analyzed because no Pinterest-eligible Before slides were found.',
                [
                    'worker' =>
                        self::class,

                    'pin_item_count' =>
                        count($pinItems),

                    'before_count' =>
                        0,

                    'after_count' =>
                        $afterCount,
                ]
            );
        }


        if ($afterCount === 0) {
            return PubComSignal::ineligible(
                'youtube_teaser_no_after_slides',
                'YouTube Teaser cannot be analyzed because no Pinterest-eligible After slides were found.',
                [
                    'worker' =>
                        self::class,

                    'pin_item_count' =>
                        count($pinItems),

                    'before_count' =>
                        $beforeCount,

                    'after_count' =>
                        0,
                ]
            );
        }


        $pairFinder =
            $this->pairFinder
            ?? new TransformationPairFinder();

        $transformationPairs =
            $pairFinder->find(
                $pinItems
            );


        if ($transformationPairs === []) {
            return PubComSignal::ineligible(
                'youtube_teaser_no_transformation_pairs',
                'YouTube Teaser cannot be analyzed because no valid Before/After transformation pairs could be formed.',
                [
                    'worker' =>
                        self::class,

                    'pin_item_count' =>
                        count($pinItems),

                    'before_count' =>
                        $beforeCount,

                    'after_count' =>
                        $afterCount,

                    'pair_count' =>
                        0,
                ]
            );
        }


        return PubComSignal::ready(
            'YouTube Teaser assignment is eligible.',
            [
                'worker' =>
                    self::class,

                'pin_item_count' =>
                    count($pinItems),

                'before_count' =>
                    $beforeCount,

                'after_count' =>
                    $afterCount,

                'pair_count' =>
                    count(
                        $transformationPairs
                    ),
            ]
        );
    }


    /**
     * Perform YouTube Teaser analysis.
     *
     * AnalyzeManager is responsible for running
     * readiness and preflight before calling analyze().
     */
    public function analyze(
        array $pinItems
    ): array {
        $pairFinder =
            $this->pairFinder
            ?? new TransformationPairFinder();

        $transformationPairs =
            $pairFinder->find(
                $pinItems
            );

        $proposals = [];


        foreach (
            $transformationPairs
            as $pair
        ) {
            $beforeId =
                (int)(
                    $pair[
                        'before_playlist_item_id'
                    ]
                    ?? 0
                );

            $afterId =
                (int)(
                    $pair[
                        'after_playlist_item_id'
                    ]
                    ?? 0
                );

            $before =
                is_array(
                    $pair[
                        'before_item'
                    ]
                    ?? null
                )
                    ? $pair[
                        'before_item'
                    ]
                    : null;

            $after =
                is_array(
                    $pair[
                        'after_item'
                    ]
                    ?? null
                )
                    ? $pair[
                        'after_item'
                    ]
                    : null;


            if (
                $beforeId <= 0
                || $afterId <= 0
                || !$before
                || !$after
            ) {
                continue;
            }


            $proposals[] = [
                'proposal_key' =>
                    "youtube-teaser-{$beforeId}-{$afterId}",

                'asset_type' =>
                    'pin_youtube_teaser',

                'pin_type' =>
                    'youtube_teaser',

                'before_playlist_item_id' =>
                    $beforeId,

                'after_playlist_item_id' =>
                    $afterId,

                /*
                 * BEFORE is the visual source.
                 */
                'before' =>
                    $this->sourceItemPayload(
                        $before
                    ),

                /*
                 * AFTER is context only.
                 * CREATE must not visually reveal it.
                 */
                'after_context' =>
                    $this->sourceItemPayload(
                        $after
                    ),
            ];
        }


        return [
            'proposals' =>
                $proposals,
        ];
    }


    /**
     * Count Pinterest-eligible items assigned a specific
     * Analyze role.
     *
     * This is eligibility inspection only.
     * It does NOT determine transformation pairing.
     */
    private function countRole(
        array $items,
        string $role
    ): int {
        $role =
            strtolower(
                trim(
                    $role
                )
            );

        $count = 0;


        foreach ($items as $item) {
            $itemRole =
                strtolower(
                    trim(
                        (string)(
                            $item[
                                'analyzer_role'
                            ]
                            ?? 'ignore'
                        )
                    )
                );

            if ($itemRole === $role) {
                $count++;
            }
        }


        return $count;
    }


    private function sourceItemPayload(
        array $item
    ): array {
        /*
         * PlaylistSourcePreparer already converted
         * the photo into canonical PUB shape.
         *
         * Do not reconstruct it here.
         */
        $photo =
            is_array(
                $item['photo']
                ?? null
            )
                ? $item['photo']
                : [];


        return [
            'playlist_item_id' =>
                (int)(
                    $item[
                        'playlist_item_id'
                    ]
                    ?? 0
                ),

            'order_index' =>
                (float)(
                    $item[
                        'order_index'
                    ]
                    ?? 0
                ),

            /*
             * DROP THE WHOLE PREPARED PHOTO BOX IN.
             */
            ...$photo,

            'saved_palette_set_id' =>
                isset(
                    $item[
                        'saved_palette_set_id'
                    ]
                )
                && $item[
                    'saved_palette_set_id'
                ] !== null
                    ? (int)$item[
                        'saved_palette_set_id'
                    ]
                    : null,

            'ap_id' =>
                isset(
                    $item['ap_id']
                )
                && $item['ap_id'] !== null
                    ? (int)$item[
                        'ap_id'
                    ]
                    : null,

            'palette_hash' =>
                trim(
                    (string)(
                        $item[
                            'palette_hash'
                        ]
                        ?? ''
                    )
                ) ?: null,

            'subtitle' =>
                (string)(
                    $item[
                        'subtitle'
                    ]
                    ?? ''
                ),

            'item_type' =>
                (string)(
                    $item[
                        'item_type'
                    ]
                    ?? ''
                ),

            'pin_role' =>
                strtolower(
                    trim(
                        (string)(
                            $item[
                                'analyzer_role'
                            ]
                            ?? 'ignore'
                        )
                    )
                ),
        ];
    }
}