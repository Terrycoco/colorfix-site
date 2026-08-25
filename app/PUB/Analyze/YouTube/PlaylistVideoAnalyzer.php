<?php
declare(strict_types=1);

namespace App\PUB\Analyze\YouTube;

use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;

/**
 * YOUTUBE PLAYLIST VIDEO ANALYZER
 *
 * Receives the YouTube-channel market source from AnalyzeManager.
 * items[] has already been culled to yt = 1.
 *
 * The Analyzer prepares ONE ingredient box for ONE YouTube video.
 * It does not pass raw playlist slide rows to CREATE.
 *
 * Current Chef order:
 *
 *   ingredients {
 *     slides[] {
 *       item_type
 *       optional photo {
 *         file_path
 *         image_url
 *       }
 *       optional title
 *       optional subtitle
 *       optional body
 *     }
 *   }
 *
 * Array order is playback order.
 *
 * This class prepares ingredients only.
 * Timing, layout, transitions, typography, intro treatment,
 * text-only treatment, and all other video decisions belong to CREATE.
 */
final class PlaylistVideoAnalyzer implements PubComWorkerContract
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
            'YouTube Playlist Video Analyzer is ready.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * Confirm that the YouTube-channel haul contains at least one slide
     * and that every slide can satisfy the Chef's current baseline order.
     */
    public function preflight(
        array $source
    ): PubComSignal {
        $items =
            $this->sourceItems(
                $source
            );


        if ($items === []) {
            return PubComSignal::ineligible(
                'youtube_playlist_video_no_slides',
                'YouTube Playlist Video cannot be analyzed because no YouTube-eligible slides were found.',
                [
                    'worker' =>
                        self::class,

                    'youtube_item_count' =>
                        0,
                ]
            );
        }


        foreach (
            $items
            as $index => $item
        ) {
            if (!is_array($item)) {
                return PubComSignal::ineligible(
                    'youtube_playlist_video_invalid_slide',
                    'YouTube Playlist Video cannot be analyzed because a YouTube slide is invalid.',
                    [
                        'worker' =>
                            self::class,

                        'slide_index' =>
                            $index,
                    ]
                );
            }


            $itemType =
                trim(
                    (string)(
                        $item[
                            'item_type'
                        ]
                        ?? ''
                    )
                );


            if ($itemType === '') {
                return PubComSignal::ineligible(
                    'youtube_playlist_video_missing_item_type',
                    'YouTube Playlist Video cannot be analyzed because a YouTube slide has no item_type.',
                    [
                        'worker' =>
                            self::class,

                        'slide_index' =>
                            $index,
                    ]
                );
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
                    : null;


            if ($photo !== null) {
                $filePath =
                    trim(
                        (string)(
                            $photo[
                                'file_path'
                            ]
                            ?? ''
                        )
                    );

                $imageUrl =
                    trim(
                        (string)(
                            $photo[
                                'image_url'
                            ]
                            ?? ''
                        )
                    );


                if (
                    $filePath === ''
                    || $imageUrl === ''
                ) {
                    return PubComSignal::ineligible(
                        'youtube_playlist_video_incomplete_photo',
                        'YouTube Playlist Video cannot be analyzed because a prepared slide photo is incomplete.',
                        [
                            'worker' =>
                                self::class,

                            'slide_index' =>
                                $index,

                            'item_type' =>
                                $itemType,
                        ]
                    );
                }
            }
        }


        return PubComSignal::ready(
            'YouTube Playlist Video assignment is eligible.',
            [
                'worker' =>
                    self::class,

                'youtube_item_count' =>
                    count(
                        $items
                    ),
            ]
        );
    }


    /**
     * Prepare the complete ordered slide sequence as ONE proposal.
     */
/**
 * Prepare the complete ordered slide sequence as ONE proposal.
 *
 * The first usable intro title is also raised as the Analyzer's
 * suggested search title for the finished YouTube video.
 */
public function analyze(
    array $source
): array {
    $items =
        $this->sourceItems(
            $source
        );


    $slides = [];

    $searchTitle = '';


    foreach (
        $items
        as $item
    ) {
        if (!is_array($item)) {
            continue;
        }


        /*
         * An authored INTRO title is a strong first suggestion
         * for the title of the complete YouTube video.
         *
         * Raise it to the proposal level so it can be edited
         * during the ANALYZE handoff before CREATE.
         */
        $itemType =
            strtolower(
                trim(
                    (string)(
                        $item[
                            'item_type'
                        ]
                        ?? ''
                    )
                )
            );


        if (
            $searchTitle === ''
            && $itemType === 'intro'
        ) {
            $introTitle =
                trim(
                    (string)(
                        $item[
                            'title'
                        ]
                        ?? ''
                    )
                );


            if ($introTitle !== '') {
                $searchTitle =
                    $introTitle;
            }
        }


        $slides[] =
            $this->prepareSlide(
                $item
            );
    }


    return [
        'proposals' => [
            [
                'asset_type' =>
                    'youtube_video',

                'search_title' =>
                    $searchTitle,

                'ingredients' => [
                    'slides' =>
                        $slides,
                ],
            ],
        ],
    ];
}


    /**
     * AnalyzeManager already performs the YouTube channel cull.
     */
    private function sourceItems(
        array $source
    ): array {
        return is_array(
            $source[
                'items'
            ]
            ?? null
        )
            ? array_values(
                $source[
                    'items'
                ]
            )
            : [];
    }


    /**
     * Cull one raw playlist slide to exactly the fields currently
     * declared by the YouTube Creator contract.
     */
    private function prepareSlide(
        array $item
    ): array {
        $prepared = [
            'item_type' =>
                strtolower(
                    trim(
                        (string)(
                            $item[
                                'item_type'
                            ]
                            ?? ''
                        )
                    )
                ),
        ];


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
                : null;


        if ($photo !== null) {
            $filePath =
                trim(
                    (string)(
                        $photo[
                            'file_path'
                        ]
                        ?? ''
                    )
                );

            $imageUrl =
                trim(
                    (string)(
                        $photo[
                            'image_url'
                        ]
                        ?? ''
                    )
                );


            if (
                $filePath !== ''
                && $imageUrl !== ''
            ) {
                $prepared[
                    'photo'
                ] = [
                    'file_path' =>
                        $filePath,

                    'image_url' =>
                        $imageUrl,
                ];
            }
        }


        foreach (
            [
                'title',
                'subtitle',
                'body',
            ]
            as $field
        ) {
            $value =
                trim(
                    (string)(
                        $item[
                            $field
                        ]
                        ?? ''
                    )
                );


            if ($value !== '') {
                $prepared[
                    $field
                ] =
                    $value;
            }
        }


        return $prepared;
    }
}