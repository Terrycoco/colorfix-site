<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Pinterest;

use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;

/**
 * PINTEREST IDEA + PALETTE ANALYZER
 *
 * Receives the complete neutral Market source and performs its own pin = 1 cull:
 *
 *   items[]
 *   linked_pvs[]
 *
 * For each usable linked PV:
 *
 *   - match a Pinterest-eligible PhotoEntity by photo_library_id
 *   - raise PV kicker -> search_title
 *   - raise PV intro -> description
 *   - give the Creator source.file_path
 *   - give the Creator search_title
 *   - give the Creator 1-4 palette colors
 *
 * One proposal is created per usable linked PV.
 */
final class PaletteAnalyzer implements PubComWorkerContract
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
            'Idea + Palette Analyzer is ready.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    public function preflight(
        array $source
    ): PubComSignal {
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


        if ($linkedPVs === []) {
            return PubComSignal::ineligible(
                'idea_palette_no_viewers',
                'Idea + Palette cannot be analyzed because no linked Palette Viewers were supplied.',
                [
                    'worker' =>
                        self::class,

                    'pin_item_count' =>
                        count(
                            $pinItems
                        ),

                    'linked_pv_count' =>
                        0,

                    'usable_pv_count' =>
                        0,
                ]
            );
        }


        $usableCount =
            0;

        $missingPhotoCount =
            0;

        $missingPaletteCount =
            0;


        foreach (
            $linkedPVs
            as $pv
        ) {
            if (!is_array($pv)) {
                continue;
            }


            $match =
                $this->usableSourceForPV(
                    $pv,
                    $pinItems
                );


            if ($match !== null) {
                $usableCount++;

                continue;
            }


            if (
                !$this->pvHasUsablePalette(
                    $pv
                )
            ) {
                $missingPaletteCount++;
            }


            if (
                !$this->pvHasUsablePhoto(
                    $pv,
                    $pinItems
                )
            ) {
                $missingPhotoCount++;
            }
        }


        if ($usableCount === 0) {
            return PubComSignal::ineligible(
                'idea_palette_no_usable_viewers',
                'Idea + Palette cannot be analyzed because no linked Palette Viewer has both a usable Pinterest source photo and palette colors.',
                [
                    'worker' =>
                        self::class,

                    'pin_item_count' =>
                        count(
                            $pinItems
                        ),

                    'linked_pv_count' =>
                        count(
                            $linkedPVs
                        ),

                    'usable_pv_count' =>
                        0,

                    'missing_photo_count' =>
                        $missingPhotoCount,

                    'missing_palette_count' =>
                        $missingPaletteCount,
                ]
            );
        }


        return PubComSignal::ready(
            'Idea + Palette assignment is eligible.',
            [
                'worker' =>
                    self::class,

                'pin_item_count' =>
                    count(
                        $pinItems
                    ),

                'linked_pv_count' =>
                    count(
                        $linkedPVs
                    ),

                'usable_pv_count' =>
                    $usableCount,

                'missing_photo_count' =>
                    $missingPhotoCount,

                'missing_palette_count' =>
                    $missingPaletteCount,
            ]
        );
    }


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


        $proposals =
            [];


        foreach (
            $linkedPVs
            as $pv
        ) {
            if (!is_array($pv)) {
                continue;
            }


            /*
             * One usable Pinterest source photo
             * + its actual PV palette.
             */
            $match =
                $this->usableSourceForPV(
                    $pv,
                    $pinItems
                );


            if ($match === null) {
                continue;
            }


            $proposal = [
                'asset_type' =>
                    'pin_idea_palette',

                'ingredients' => [
                    'source' => [
                        'file_path' =>
                            $match[
                                'file_path'
                            ],
                    ],

                    /*
                     * Actual paint colors required
                     * by the Palette Creator.
                     */
                    'palette_colors' =>
                        $match[
                            'palette_colors'
                        ],
                ],
            ];


            $kicker =
                trim(
                    (string)(
                        $pv[
                            'kicker'
                        ]
                        ?? ''
                    )
                );


            $intro =
                trim(
                    (string)(
                        $pv[
                            'intro'
                        ]
                        ?? ''
                    )
                );


            /*
             * KICKER SERVES TWO CONSUMERS:
             *
             * 1. Box publishing metadata.
             * 2. Creator text baked into the JPEG.
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
             * DESCRIPTION IS PUBLISHING METADATA.
             */
            if ($intro !== '') {
                $proposal[
                    'description'
                ] =
                    $intro;
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
     * Return the first usable photo/palette pair for
     * one linked PV.
     *
     * A PV may contain several photos, but this product
     * produces one Idea + Palette asset per PV.
     */
    private function usableSourceForPV(
        array $pv,
        array $pinItems
    ): ?array {
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


            $photoLibraryId =
                (int)(
                    $photoPalette[
                        'photo_library_id'
                    ]
                    ?? 0
                );


            if ($photoLibraryId <= 0) {
                continue;
            }


            $paletteColors =
                $this->paletteColors(
                    $photoPalette[
                        'hex6s'
                    ]
                    ?? []
                );


            if ($paletteColors === []) {
                continue;
            }


            $item =
                $this->itemForPhoto(
                    $photoLibraryId,
                    $pinItems
                );


            if ($item === null) {
                continue;
            }


            $filePath =
                $this->sourceFilePath(
                    $item
                );


            if ($filePath === '') {
                continue;
            }


            return [
                'photo_library_id' =>
                    $photoLibraryId,

                'file_path' =>
                    $filePath,

                'palette_colors' =>
                    $paletteColors,
            ];
        }


        return null;
    }


    private function itemForPhoto(
        int $photoLibraryId,
        array $pinItems
    ): ?array {
        foreach (
            $pinItems
            as $item
        ) {
            if (!is_array($item)) {
                continue;
            }


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


            if (
                (int)(
                    $photo[
                        'photo_library_id'
                    ]
                    ?? 0
                ) ===
                $photoLibraryId
            ) {
                return $item;
            }
        }


        return null;
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


    /**
     * Convert PV hex6 values into the exact shape
     * declared by the Palette Creator contract.
     *
     * Preserve palette order.
     * Maximum four colors.
     */
    private function paletteColors(
        mixed $hex6s
    ): array {
        if (!is_array($hex6s)) {
            return [];
        }


        $colors =
            [];


        foreach (
            $hex6s
            as $hex6
        ) {
            $hex =
                strtoupper(
                    ltrim(
                        trim(
                            (string)$hex6
                        ),
                        '#'
                    )
                );


            if (
                !preg_match(
                    '/^[0-9A-F]{6}$/',
                    $hex
                )
            ) {
                continue;
            }


            $colors[] = [
                'color_hex6' =>
                    $hex,
            ];


            if (
                count($colors) >=
                4
            ) {
                break;
            }
        }


        return $colors;
    }


    private function pvHasUsablePalette(
        array $pv
    ): bool {
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
            if (
                is_array($photoPalette)
                &&
                $this->paletteColors(
                    $photoPalette[
                        'hex6s'
                    ]
                    ?? []
                ) !==
                []
            ) {
                return true;
            }
        }


        return false;
    }


    private function pvHasUsablePhoto(
        array $pv,
        array $pinItems
    ): bool {
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


            $photoLibraryId =
                (int)(
                    $photoPalette[
                        'photo_library_id'
                    ]
                    ?? 0
                );


            if ($photoLibraryId <= 0) {
                continue;
            }


            $item =
                $this->itemForPhoto(
                    $photoLibraryId,
                    $pinItems
                );


            if (
                $item !== null
                &&
                $this->sourceFilePath(
                    $item
                ) !==
                ''
            ) {
                return true;
            }
        }


        return false;
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