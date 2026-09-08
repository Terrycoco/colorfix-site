<?php
declare(strict_types=1);

namespace App\PUB\Analyze\YouTube;

use App\PUB\Analyze\Support\DefaultPantry;
use App\PUB\Contracts\PubContract;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;

/**
 * YOUTUBE PLAYLIST VIDEO ANALYZER
 *
 * Receives the complete neutral Market source from AnalyzeManager.
 * This Analyzer performs its own yt = 1 channel cull.
 *
 * The Analyzer prepares ONE ingredient box for ONE YouTube video.
 * It does not pass raw playlist slide rows to CREATE.
 *
 * Current Chef order:
 *
 *   ingredients {
 *     cover {
 *       file_path
 *       image_url
 *       title
 *     }
 *
 *     music {
 *       file_path
 *       audio_url
 *       volume
 *     }
 *
 *     slides[] {
 *       item_type
 *       optional photo {
 *         file_path
 *         image_url
 *       }
 *       optional title
 *       optional subtitle
 *       optional body
 *       optional hue_wheel {
 *         spokes[] { hue, color, optional animation overrides }
 *       }
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

    private ?array $preparedMusic = null;


    public function __construct(
        private DefaultPantry $defaultPantry
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
            'YouTube Playlist Video Analyzer is ready.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * Confirm that the YouTube-eligible portion of the Market haul contains at least one slide
     * and that every slide can satisfy the Chef's current baseline order.
     */
    public function preflight(
        array $source
    ): PubComSignal {
        $items =
            $this->sourceItems(
                $source
            );


        $coverItems =
            $this->coverImageItems(
                $items
            );


        if (count($coverItems) !== 1) {
            return PubComSignal::ineligible(
                'youtube_playlist_video_cover_image_count',
                'YouTube Playlist Video requires exactly one YouTube-eligible cover-image slide.',
                [
                    'worker' =>
                        self::class,

                    'cover_image_count' =>
                        count($coverItems),
                ]
            );
        }


        try {
            $this->prepareCoverIngredient(
                $coverItems[0]
            );

        } catch (\RuntimeException $e) {
            return PubComSignal::ineligible(
                'youtube_playlist_video_invalid_cover_image',
                'YouTube Playlist Video cannot be analyzed because its cover-image slide is incomplete or invalid.',
                [
                    'worker' =>
                        self::class,

                    'reason' =>
                        $e->getMessage(),
                ]
            );
        }


        $playableItems =
            $this->playableItems(
                $items
            );


        if ($playableItems === []) {
            return PubComSignal::ineligible(
                'youtube_playlist_video_no_slides',
                'YouTube Playlist Video cannot be analyzed because no playable YouTube slides were found.',
                [
                    'worker' =>
                        self::class,

                    'youtube_item_count' =>
                        count($items),

                    'playable_slide_count' =>
                        0,
                ]
            );
        }


        foreach (
            $playableItems
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


            if ($itemType === 'hue-wheel') {
                try {
                    $this->prepareHueWheelIngredient(
                        $item
                    );

                } catch (\RuntimeException $e) {
                    return PubComSignal::ineligible(
                        'youtube_playlist_video_invalid_hue_wheel',
                        'YouTube Playlist Video cannot be analyzed because a hue-wheel slide is invalid.',
                        [
                            'worker' =>
                                self::class,

                            'slide_index' =>
                                $index,

                            'reason' =>
                                $e->getMessage(),
                        ]
                    );
                }
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


        try {
            $this->preparedMusic =
                $this->prepareMusicIngredient(
                    $source
                );

        } catch (\RuntimeException $e) {
            return PubComSignal::ineligible(
                'youtube_playlist_video_music_unavailable',
                'YouTube Playlist Video cannot be analyzed because its required music ingredient could not be prepared.',
                [
                    'worker' =>
                        self::class,

                    'reason' =>
                        $e->getMessage(),
                ]
            );
        }


        return PubComSignal::ready(
            'YouTube Playlist Video assignment is eligible.',
            [
                'worker' =>
                    self::class,

                'youtube_item_count' =>
                    count($items),

                'playable_slide_count' =>
                    count($playableItems),

                'cover_image_count' =>
                    1,
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


    $coverItems =
        $this->coverImageItems(
            $items
        );


    if (count($coverItems) !== 1) {
        return [
            'proposals' =>
                [],
        ];
    }


    $cover =
        $this->prepareCoverIngredient(
            $coverItems[0]
        );


    $slides = [];

    $searchTitle = '';

    $music =
        $this->preparedMusic
        ?? $this->prepareMusicIngredient(
            $source
        );


    foreach (
        $this->playableItems(
            $items
        )
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
                    'cover' =>
                        $cover,

                    'music' =>
                        $music,

                    'slides' =>
                        $slides,
                ],
            ],
        ],
    ];
}


    /**
     * Prepare the Chef's required music ingredient.
     *
     * If source already contains a complete prepared music ingredient,
     * preserve it. Otherwise use the raw default pantry reference declared
     * by PubContract and ask the shared ANALYZE pantry to prepare it.
     */
    private function prepareMusicIngredient(
        array $source
    ): array {
        $sourceMusic =
            is_array(
                $source[
                    'music'
                ]
                ?? null
            )
                ? $source[
                    'music'
                ]
                : null;


        if (
            $sourceMusic !== null
            && $this->isPreparedMusic(
                $sourceMusic
            )
        ) {
            return $this->normalizePreparedMusic(
                $sourceMusic
            );
        }


        $default =
            PubContract::defaultIngredient(
                'youtube_video',
                'music'
            );


        if ($default === null) {
            throw new \RuntimeException(
                'YouTube music is required and no default pantry reference is declared.'
            );
        }


        return $this->defaultPantry
            ->prepareAudio(
                $default
            );
    }


    private function isPreparedMusic(
        array $music
    ): bool {
        return
            trim(
                (string)(
                    $music[
                        'file_path'
                    ]
                    ?? ''
                )
            ) !== ''
            &&
            trim(
                (string)(
                    $music[
                        'audio_url'
                    ]
                    ?? ''
                )
            ) !== ''
            &&
            is_numeric(
                $music[
                    'volume'
                ]
                ?? null
            );
    }


    private function normalizePreparedMusic(
        array $music
    ): array {
        $volume =
            (float)$music[
                'volume'
            ];


        if (
            $volume < 0
            || $volume > 1
        ) {
            throw new \RuntimeException(
                'YouTube prepared music volume must be between 0 and 1.'
            );
        }


        return [
            'file_path' =>
                trim(
                    (string)$music[
                        'file_path'
                    ]
                ),

            'audio_url' =>
                trim(
                    (string)$music[
                        'audio_url'
                    ]
                ),

            'volume' =>
                $volume,
        ];
    }


    /**
     * cover-image is authored companion source material.
     *
     * It is deliberately not part of slides[] because it never enters
     * the playable video timeline.
     *
     * @return array<int, array<string, mixed>>
     */
    private function coverImageItems(
        array $items
    ): array {
        return array_values(
            array_filter(
                $items,

                static fn (
                    mixed $item
                ): bool =>
                    is_array($item)
                    && strtolower(
                        trim(
                            (string)(
                                $item[
                                    'item_type'
                                ]
                                ?? ''
                            )
                        )
                    ) === 'cover-image'
            )
        );
    }


    /**
     * @return array<int, mixed>
     */
    private function playableItems(
        array $items
    ): array {
        return array_values(
            array_filter(
                $items,

                static fn (
                    mixed $item
                ): bool =>
                    !(
                        is_array($item)
                        && strtolower(
                            trim(
                                (string)(
                                    $item[
                                        'item_type'
                                    ]
                                    ?? ''
                                )
                            )
                        ) === 'cover-image'
                    )
            )
        );
    }


    /**
     * Convert the authored cover-image slide into the exact thumbnail
     * source ordered by the YouTube Chef.
     */
    private function prepareCoverIngredient(
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

        $title =
            trim(
                (string)(
                    $item[
                        'title'
                    ]
                    ?? ''
                )
            );


        if ($filePath === '') {
            throw new \RuntimeException(
                'YouTube cover-image requires photo.file_path.'
            );
        }


        if ($imageUrl === '') {
            throw new \RuntimeException(
                'YouTube cover-image requires photo.image_url.'
            );
        }


        if ($title === '') {
            throw new \RuntimeException(
                'YouTube cover-image requires title.'
            );
        }


        if (!is_file($filePath)) {
            throw new \RuntimeException(
                'YouTube cover-image source file does not exist.'
            );
        }


        return [
            'file_path' =>
                $filePath,

            'image_url' =>
                $imageUrl,

            'title' =>
                $title,
        ];
    }


    /**
     * YouTube channel participation belongs to the YouTube specialist,
     * not AnalyzeManager.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sourceItems(
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
                    && !empty($item['yt'])
            )
        );
    }


    /**
     * Cull one raw playlist slide to exactly the fields currently
     * declared by the YouTube Creator contract.
     */
    private function prepareSlide(
        array $item
    ): array {
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


        $prepared = [
            'item_type' =>
                $itemType,
        ];


        /*
         * Standard house bumper is an instruction only.
         * Do not leak authored bumper body/title/subtitle into CREATE.
         */
        if ($itemType === 'brand-bumper') {
            return $prepared;
        }


        /*
         * Hue wheel is also specialized, but unlike the bumper its spokes
         * are authored content. Parse the source body JSON and hand CREATE
         * only the exact spoke ingredients it needs.
         */
        if ($itemType === 'hue-wheel') {
            foreach (
                [
                    'title',
                    'subtitle',
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


            $prepared[
                'hue_wheel'
            ] =
                $this->prepareHueWheelIngredient(
                    $item
                );


            return $prepared;
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


    /**
     * Parse one authored hue-wheel body into the exact Creator ingredient.
     *
     * The authored editor stores more presentation settings than YouTube
     * needs. CREATE owns YouTube wheel size, standard timing, typography,
     * and default radii through PlaylistVideoRecipe.
     *
     * Per-spoke overrides are preserved when the author explicitly set them.
     */
    private function prepareHueWheelIngredient(
        array $item
    ): array {
        $body =
            trim(
                (string)(
                    $item[
                        'body'
                    ]
                    ?? ''
                )
            );


        if ($body === '') {
            throw new \RuntimeException(
                'Hue-wheel slide has no body JSON.'
            );
        }


        try {
            $decoded =
                json_decode(
                    $body,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

        } catch (\JsonException $e) {
            throw new \RuntimeException(
                'Hue-wheel slide body is not valid JSON.',
                0,
                $e
            );
        }


        if (
            is_array(
                $decoded
            )
            && array_is_list(
                $decoded
            )
        ) {
            $rawSpokes =
                $decoded;

        } elseif (is_array($decoded)) {
            $rawSpokes =
                is_array(
                    $decoded[
                        'items'
                    ]
                    ?? null
                )
                    ? $decoded[
                        'items'
                    ]
                    : [];

        } else {
            $rawSpokes = [];
        }


        if ($rawSpokes === []) {
            throw new \RuntimeException(
                'Hue-wheel slide requires at least one spoke.'
            );
        }


        $spokes = [];


        foreach (
            $rawSpokes
            as $index => $rawSpoke
        ) {
            if (!is_array($rawSpoke)) {
                throw new \RuntimeException(
                    "Hue-wheel spoke "
                    . (
                        $index + 1
                    )
                    . ' is invalid.'
                );
            }


            if (
                !array_key_exists(
                    'hue',
                    $rawSpoke
                )
                || !is_numeric(
                    $rawSpoke[
                        'hue'
                    ]
                )
            ) {
                throw new \RuntimeException(
                    "Hue-wheel spoke "
                    . (
                        $index + 1
                    )
                    . ' requires a numeric hue.'
                );
            }


            $hue =
                fmod(
                    (float)$rawSpoke[
                        'hue'
                    ],
                    360.0
                );


            if ($hue < 0) {
                $hue +=
                    360.0;
            }


            $color =
                strtoupper(
                    trim(
                        (string)(
                            $rawSpoke[
                                'color'
                            ]
                            ?? ''
                        )
                    )
                );


            if (
                preg_match(
                    '/^#[0-9A-F]{6}$/',
                    $color
                ) !== 1
            ) {
                throw new \RuntimeException(
                    "Hue-wheel spoke "
                    . (
                        $index + 1
                    )
                    . ' requires a six-digit hex color.'
                );
            }


            $spoke = [
                'hue' =>
                    $hue,

                'color' =>
                    $color,

                'animate' =>
                    (
                        $rawSpoke[
                            'animate'
                        ]
                        ?? true
                    ) !== false,
            ];


            foreach (
                [
                    'delayMs' =>
                        'delay_ms',

                    'durationMs' =>
                        'duration_ms',

                    'startRadius' =>
                        'start_radius',

                    'endRadius' =>
                        'end_radius',
                ]
                as $sourceKey => $ingredientKey
            ) {
                if (
                    !array_key_exists(
                        $sourceKey,
                        $rawSpoke
                    )
                    || $rawSpoke[
                        $sourceKey
                    ] === ''
                    || $rawSpoke[
                        $sourceKey
                    ] === null
                ) {
                    continue;
                }


                if (
                    !is_numeric(
                        $rawSpoke[
                            $sourceKey
                        ]
                    )
                    || (float)$rawSpoke[
                        $sourceKey
                    ] < 0
                ) {
                    throw new \RuntimeException(
                        "Hue-wheel spoke "
                        . (
                            $index + 1
                        )
                        . " has invalid {$sourceKey}."
                    );
                }


                $spoke[
                    $ingredientKey
                ] =
                    (float)$rawSpoke[
                        $sourceKey
                    ];
            }


            $spokes[] =
                $spoke;
        }


        return [
            'spokes' =>
                $spokes,
        ];
    }

}