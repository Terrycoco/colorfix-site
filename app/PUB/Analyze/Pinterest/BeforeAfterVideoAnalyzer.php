<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Pinterest;

use App\PUB\Analyze\Pinterest\Support\TransformationPairFinder;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;

/**
 * PINTEREST BEFORE / AFTER VIDEO ANALYZER
 *
 * Receives the common Pinterest market source.
 *
 * Uses:
 *   items[]
 *   linked_pvs[].kicker
 *   linked_pvs[].intro
 *   linked_pvs[].photo_palettes[].photo_library_id
 *
 * Returns:
 *   asset_type
 *   optional search_title
 *   optional description
 *   ingredients {
 *     before.file_path
 *     before.image_url
 *     after.file_path
 *     after.image_url
 *     search_title
 *   }
 *
 * Shared Before -> After pairing is provided by
 * TransformationPairFinder.
 *
 * One valid transformation pair produces one possible video.
 *
 * This Analyzer selects and prepares source material only.
 * Video timing, transitions, styling, labels, logo treatment,
 * and end-screen treatment belong to CREATE.
 */
final class BeforeAfterVideoAnalyzer implements PubComWorkerContract
{
    
        private const DEFAULT_END_SLIDE_TEXT =
            'See More Transformations';

        private ?PubComChannel $pubComChannel = null;


    public function __construct(
        private ?TransformationPairFinder $pairFinder = null
    ) {}


    public function connectPubCom(
        PubComChannel $channel
    ): void {
        $this->pubComChannel =
            $channel;
    }


    public function readiness(): PubComSignal
    {
        return PubComSignal::ready(
            'Before/After Video Analyzer is ready.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * Confirm that at least one valid Before / After
     * transformation pair can be formed from the
     * Pinterest market source.
     */
    public function preflight(
        array $source
    ): PubComSignal {
        $pinItems =
            is_array(
                $source['items']
                ?? null
            )
                ? $source['items']
                : [];


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
                'before_after_video_no_before_slides',
                'Before/After Video cannot be analyzed because no Pinterest-eligible Before slides were found.',
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
                'before_after_video_no_after_slides',
                'Before/After Video cannot be analyzed because no Pinterest-eligible After slides were found.',
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
                'before_after_video_no_transformation_pairs',
                'Before/After Video cannot be analyzed because no valid Before/After transformation pairs could be formed.',
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
            'Before/After Video assignment is eligible.',
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
     * Produce one Before/After Video proposal for each
     * valid transformation pair.
     */
    public function analyze(
        array $source
    ): array {
        $pinItems =
            is_array(
                $source['items']
                ?? null
            )
                ? $source['items']
                : [];


        $linkedPVs =
            is_array(
                $source['linked_pvs']
                ?? null
            )
                ? $source['linked_pvs']
                : [];


        $pairFinder =
            $this->pairFinder
            ?? new TransformationPairFinder();


        $transformationPairs =
            $pairFinder->find(
                $pinItems
            );


        $proposals =
            [];


        foreach (
            $transformationPairs
            as $pair
        ) {
            $before =
                is_array(
                    $pair['before_item']
                    ?? null
                )
                    ? $pair['before_item']
                    : null;


            $after =
                is_array(
                    $pair['after_item']
                    ?? null
                )
                    ? $pair['after_item']
                    : null;


            if (
                !$before
                || !$after
            ) {
                continue;
            }


            $beforeIngredients =
                $this->sourceItemIngredients(
                    $before
                );

            $afterIngredients =
                $this->sourceItemIngredients(
                    $after
                );


            if (
                $beforeIngredients['file_path'] === ''
                || $beforeIngredients['image_url'] === ''
                || $afterIngredients['file_path'] === ''
                || $afterIngredients['image_url'] === ''
            ) {
                continue;
            }


            $proposal = [
                'asset_type' =>
                    'pin_before_after_video',

                'ingredients' => [
    'before' =>
        $beforeIngredients,

    'after' =>
        $afterIngredients,

    'end_slide_text' =>
        self::DEFAULT_END_SLIDE_TEXT,
],
            ];


            /*
             * The After photo identifies the linked PV
             * whose publishing copy belongs to this pair.
             */
            $linkedPV =
                $this->linkedPVForPhoto(
                    $after,
                    $linkedPVs
                );


            if ($linkedPV !== null) {
                $kicker =
                    trim(
                        (string)(
                            $linkedPV[
                                'kicker'
                            ]
                            ?? ''
                        )
                    );


                $intro =
                    trim(
                        (string)(
                            $linkedPV[
                                'intro'
                            ]
                            ?? ''
                        )
                    );


                /*
                 * The kicker supplies Pinterest search metadata
                 * and, for this video format, the visible title
                 * required by the Creator.
                 */
                if ($kicker !== '') {
                    $proposal[
                        'search_title'
                    ] =
                        $kicker;

                    $proposal[
                        'ingredients'
                    ][
                        'search_title'
                    ] =
                        $kicker;
                }


                if ($intro !== '') {
                    $proposal[
                        'description'
                    ] =
                        $intro;
                }
            }


            $proposals[] =
                $proposal;
        }


        return [
            'proposals' =>
                $proposals,
        ];
    }


    /**
     * Find the first linked PV whose declared photo
     * mapping contains this PhotoEntity ID.
     */
    private function linkedPVForPhoto(
        array $item,
        array $linkedPVs
    ): ?array {
        $photo =
            is_array(
                $item['photo']
                ?? null
            )
                ? $item['photo']
                : [];


        $photoLibraryId =
            (int)(
                $photo[
                    'photo_library_id'
                ]
                ?? 0
            );


        if ($photoLibraryId <= 0) {
            return null;
        }


        foreach (
            $linkedPVs
            as $pv
        ) {
            if (!is_array($pv)) {
                continue;
            }


            $photoPalettes =
                is_array(
                    $pv[
                        'photo_palettes'
                    ]
                    ?? null
                )
                    ? $pv[
                        'photo_palettes'
                    ]
                    : [];


            foreach (
                $photoPalettes
                as $photoPalette
            ) {
                if (!is_array($photoPalette)) {
                    continue;
                }


                if (
                    (int)(
                        $photoPalette[
                            'photo_library_id'
                        ]
                        ?? 0
                    ) ===
                    $photoLibraryId
                ) {
                    return $pv;
                }
            }
        }


        return null;
    }


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


        $count =
            0;


        foreach (
            $items
            as $item
        ) {
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


    /**
     * BeforeAfterVideoCreator needs both:
     *
     *   file_path  - local validation / production source
     *   image_url  - URL passed into the Remotion recipe
     */
    private function sourceItemIngredients(
        array $item
    ): array {
        $photo =
            is_array(
                $item[
                    'photo'
                ]
                ?? null
            )
                ? $item[
                    'photo'
                ]
                : [];


        return [
            'file_path' =>
                trim(
                    (string)(
                        $photo[
                            'file_path'
                        ]
                        ?? ''
                    )
                ),

            'image_url' =>
                trim(
                    (string)(
                        $photo[
                            'image_url'
                        ]
                        ?? ''
                    )
                ),
        ];
    }
}