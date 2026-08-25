<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Pinterest;

use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;

/**
 * PINTEREST IDEA ANALYZER
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
 *     source.file_path
 *     search_title
 *   }
 *
 * The PV kicker is raised to:
 *
 *   search_title
 *
 * and is also supplied to:
 *
 *   ingredients.search_title
 *
 * because the Creator needs the search title as physical
 * production material.
 *
 * The PV intro is raised to:
 *
 *   description
 */
final class IdeaAnalyzer implements PubComWorkerContract
{
    private ?PubComChannel $pubComChannel = null;


    public function connectPubCom(
        PubComChannel $channel
    ): void {
        $this->pubComChannel =
            $channel;
    }


    public function readiness(): PubComSignal
    {
        return PubComSignal::ready(
            'Idea Analyzer is ready.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * Idea requires at least one Pinterest-eligible
     * After or Single slide with a prepared physical
     * source file.
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


        $eligibleCount =
            0;

        $usableCount =
            0;


        foreach (
            $pinItems
            as $item
        ) {
            if (
                !$this->isIdeaSource(
                    $item
                )
            ) {
                continue;
            }


            $eligibleCount++;


            if (
                $this->sourceFilePath(
                    $item
                ) !== ''
            ) {
                $usableCount++;
            }
        }


        if ($eligibleCount === 0) {
            return PubComSignal::ineligible(
                'idea_no_eligible_slides',
                'Idea cannot be analyzed because no Pinterest-eligible After or Single slides were found.',
                [
                    'worker' =>
                        self::class,

                    'pin_item_count' =>
                        count(
                            $pinItems
                        ),

                    'eligible_count' =>
                        0,

                    'usable_count' =>
                        0,
                ]
            );
        }


        if ($usableCount === 0) {
            return PubComSignal::ineligible(
                'idea_no_usable_source_files',
                'Idea cannot be analyzed because its eligible slides have no prepared source file paths.',
                [
                    'worker' =>
                        self::class,

                    'pin_item_count' =>
                        count(
                            $pinItems
                        ),

                    'eligible_count' =>
                        $eligibleCount,

                    'usable_count' =>
                        0,
                ]
            );
        }


        return PubComSignal::ready(
            'Idea assignment is eligible.',
            [
                'worker' =>
                    self::class,

                'pin_item_count' =>
                    count(
                        $pinItems
                    ),

                'eligible_count' =>
                    $eligibleCount,

                'usable_count' =>
                    $usableCount,
            ]
        );
    }


    /**
     * Prepare one Idea proposal per usable source photo.
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


        $proposals =
            [];


        foreach (
            $pinItems
            as $item
        ) {
            if (
                !$this->isIdeaSource(
                    $item
                )
            ) {
                continue;
            }


            $filePath =
                $this->sourceFilePath(
                    $item
                );


            if ($filePath === '') {
                continue;
            }


            /*
             * CREATOR INGREDIENTS.
             *
             * search_title is added below when
             * a matching PV provides the kicker.
             */
            $proposal = [
                'asset_type' =>
                    'pin_idea',

                'ingredients' => [
                    'source' => [
                        'file_path' =>
                            $filePath,
                    ],
                ],
            ];


            /*
             * Find the PV belonging to this source photo.
             */
            $linkedPV =
                $this->linkedPVForPhoto(
                    $item,
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
                 * KICKER SERVES TWO CONSUMERS:
                 *
                 * 1. Outer Box publishing metadata.
                 * 2. Creator production ingredient.
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


                /*
                 * Public PV intro becomes the
                 * suggested Box description.
                 */
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
     * Find the linked PV whose photo mapping contains
     * this Idea source PhotoEntity ID.
     */
    private function linkedPVForPhoto(
        array $item,
        array $linkedPVs
    ): ?array {
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


    private function isIdeaSource(
        array $item
    ): bool {
        return in_array(
            $this->role(
                $item
            ),
            [
                'after',
                'single',
            ],
            true
        );
    }


    private function role(
        array $item
    ): string {
        return strtolower(
            trim(
                (string)(
                    $item[
                        'analyzer_role'
                    ]
                    ?? 'ignore'
                )
            )
        );
    }


    private function sourceFilePath(
        array $item
    ): string {
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


        return trim(
            (string)(
                $photo[
                    'file_path'
                ]
                ?? ''
            )
        );
    }
}