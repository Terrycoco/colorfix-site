<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Pinterest;

use App\PUB\Analyze\Pinterest\Support\TransformationPairFinder;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;

/**
 * PINTEREST COMPOSITE ANALYZER
 *
 * Receives the complete neutral Market source and performs its own pin = 1 cull.
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
 *   ingredients
 */
final class CompositeAnalyzer implements PubComWorkerContract
{
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
            'Composite Analyzer is ready.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * Inspect the Pinterest market source and confirm that
     * at least one valid Before / After pair can be formed.
     */
    public function preflight(
        array $source
    ): PubComSignal {
        $pinItems =
            $this->pinItems(
                $source
            );


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
                'composite_no_before_slides',
                'Composite cannot be analyzed because no Pinterest-eligible Before slides were found.',
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
                'composite_no_after_slides',
                'Composite cannot be analyzed because no Pinterest-eligible After slides were found.',
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
                'composite_no_transformation_pairs',
                'Composite cannot be analyzed because no valid Before/After transformation pairs could be formed.',
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
            'Composite assignment is eligible.',
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
     * Produce Composite proposals from the Pinterest
     * market source.
     */
    public function analyze(
        array $source
    ): array {
        $pinItems =
            $this->pinItems(
                $source
            );


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


        $proposals = [];


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


            /*
             * Base proposal.
             *
             * These are the only fields Composite is
             * allowed to contribute to the outer Box.
             */
            $proposal = [
                'asset_type' =>
                    'pin_composite',

                'ingredients' => [
                    'before' =>
                        $this->sourceItemIngredients(
                            $before
                        ),

                    'after' =>
                        $this->sourceItemIngredients(
                            $after
                        ),
                ],
            ];


            /*
             * Find the linked PV belonging to the
             * After photo.
             */
            $linkedPV =
                $this->linkedPVForPhoto(
                    $after,
                    $linkedPVs
                );


            /*
             * PV kicker and intro are optional
             * suggestions for outer Box fields.
             */
            if ($linkedPV !== null) {
                $kicker =
                    trim(
                        (string)(
                            $linkedPV['kicker']
                            ?? ''
                        )
                    );

                $intro =
                    trim(
                        (string)(
                            $linkedPV['intro']
                            ?? ''
                        )
                    );


                if ($kicker !== '') {
                    $proposal['search_title'] =
                        $kicker;
                }


                if ($intro !== '') {
                    $proposal['description'] =
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
                $photo['photo_library_id']
                ?? 0
            );


        if ($photoLibraryId <= 0) {
            return null;
        }


        foreach ($linkedPVs as $pv) {
            if (!is_array($pv)) {
                continue;
            }


            $photoPalettes =
                is_array(
                    $pv['photo_palettes']
                    ?? null
                )
                    ? $pv['photo_palettes']
                    : [];


            foreach ($photoPalettes as $photoPalette) {
                if (!is_array($photoPalette)) {
                    continue;
                }


                if (
                    (int)(
                        $photoPalette['photo_library_id']
                        ?? 0
                    ) === $photoLibraryId
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


        $count = 0;


        foreach ($items as $item) {
            $itemRole =
                strtolower(
                    trim(
                        (string)(
                            $item['analyzer_role']
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
     * CompositeCreator needs only the prepared
     * physical source file path.
     */
    private function sourceItemIngredients(
        array $item
    ): array {
        $photo =
            is_array(
                $item['photo']
                ?? null
            )
                ? $item['photo']
                : [];


        return [
            'file_path' =>
                trim(
                    (string)(
                        $photo['file_path']
                        ?? ''
                    )
                ),
        ];
    }

    /**
     * Pinterest channel participation belongs to the Pinterest specialist,
     * not AnalyzeManager.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pinItems(
        array $source
    ): array {
        $items = is_array(
            $source['items']
            ?? null
        )
            ? $source['items']
            : [];

        return array_values(
            array_filter(
                $items,
                static fn (mixed $item): bool =>
                    is_array($item)
                    && !empty($item['pin'])
            )
        );
    }

}