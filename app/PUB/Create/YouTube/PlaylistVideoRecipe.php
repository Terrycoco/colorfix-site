<?php
declare(strict_types=1);

namespace App\PUB\Create\YouTube;

use App\PUB\Create\Video\Support\VideoCreatorTools;
use RuntimeException;

/**
 * YOUTUBE PLAYLIST VIDEO RECIPE
 *
 * Written recipe beside the YouTube Chef.
 *
 * This class owns YouTube-product decisions:
 *   - canvas / codec
 *   - slide timing
 *   - first-slide treatment
 *   - photo treatment
 *   - text-only treatment
 *   - caption treatment
 *   - dissolve timing
 *   - final fade
 *   - typography / placement
 *
 * It compiles those decisions into the generic render-plan language
 * understood by colorfix-generic-video.
 *
 * It does NOT:
 *   - fetch ingredients
 *   - know PUB lifecycle
 *   - reserve assets
 *   - queue jobs
 *   - schedule or publish
 *
 * CURRENT FIRST-PASS PRODUCT:
 *   - first slide receives the YouTube intro timing/treatment
 *   - later slides with photos use the photo treatment
 *   - later slides without photos use the text-only treatment
 *   - current source item_type values are palette/non-palette/normal
 *   - item_type is preserved as a product input; current photo timing
 *     happens to be the same for all three and can diverge here later
 */
final class PlaylistVideoRecipe
{
    public const COMPOSITION_ID =
        'colorfix-generic-video';

    public const OUTPUT_MIME_TYPE =
        'video/mp4';

    public const CODEC =
        'h264';


    /* VIDEO */
    public const WIDTH = 1920;
    public const HEIGHT = 1080;
    public const FPS = 30;


    /* TIMING — seconds */
    public const INTRO_SECONDS = 3.6;
    public const PALETTE_PHOTO_SECONDS = 8.5;
    public const NON_PALETTE_PHOTO_SECONDS = 8.5;
    public const NORMAL_PHOTO_SECONDS = 8.5;
    public const TEXT_ONLY_SECONDS = 7.6;

    public const DISSOLVE_SECONDS = 2.0;
    public const CAPTION_DELAY_SECONDS = 0.12;
    public const CAPTION_FADE_SECONDS = 2.0;
    public const FINAL_FADE_SECONDS = 1.4;


    /* VISUALS */
    public const BACKGROUND_COLOR = '#000000';
    public const TEXT_COLOR = '#ffffff';
    public const FONT_FAMILY = 'Helvetica, Arial, sans-serif';

    public const PHOTO_OBJECT_FIT = 'contain';

    public const CAPTION_LEFT = 67;
    public const CAPTION_BOTTOM = 38;
    public const CAPTION_MAX_WIDTH = 1114;
    public const CAPTION_PADDING_X = 20;
    public const CAPTION_PADDING_Y = 14;
    public const CAPTION_BACKGROUND = 'rgba(0, 0, 0, 0.45)';

    public const CAPTION_TITLE_FONT_SIZE = 34;
    public const CAPTION_TITLE_FONT_WEIGHT = 500;
    public const CAPTION_TITLE_LINE_HEIGHT = 1.25;

    public const CAPTION_SUBTITLE_FONT_SIZE = 23;
    public const CAPTION_SUBTITLE_LINE_HEIGHT = 1.3;

    public const CAPTION_BODY_FONT_SIZE = 23;
    public const CAPTION_BODY_LINE_HEIGHT = 1.35;

    public const TEXT_SCREEN_PADDING_X = 154;
    public const TEXT_SCREEN_PADDING_Y = 86;

    public const INTRO_TITLE_FONT_SIZE = 68;
    public const INTRO_SUBTITLE_FONT_SIZE = 42;
    public const INTRO_BODY_FONT_SIZE = 32;

    public const TEXT_TITLE_FONT_SIZE = 68;
    public const TEXT_SUBTITLE_FONT_SIZE = 42;
    public const TEXT_BODY_FONT_SIZE = 32;


    /**
     * Compile one complete ordered playlist into generic oven instructions.
     *
     * @param array<int, array<string, mixed>> $slides
     */
    public static function plan(
        array $slides
    ): array {
        if (
            count(
                $slides
            ) === 0
        ) {
            throw new RuntimeException(
                'YouTube Playlist Video Recipe requires at least one slide.'
            );
        }


        $tools =
            new VideoCreatorTools();


        $dissolveFrames =
            $tools->secondsToFrames(
                self::DISSOLVE_SECONDS,
                self::FPS
            );


        $layers = [];
        $cursor = 0;


        foreach (
            $slides
            as $index => $slide
        ) {
            $slideNumber =
                $index + 1;

            $itemType =
                strtolower(
                    trim(
                        (string)(
                            $slide[
                                'item_type'
                            ]
                            ?? ''
                        )
                    )
                );

            $photo =
                is_array(
                    $slide[
                        'photo'
                    ]
                    ?? null
                )
                    ? $slide[
                        'photo'
                    ]
                    : null;

            $hasPhoto =
                $photo !== null
                && trim(
                    (string)(
                        $photo[
                            'image_url'
                        ]
                        ?? ''
                    )
                ) !== '';


            $durationFrames =
                $tools->secondsToFrames(
                    self::durationSecondsForSlide(
                        $slide,
                        $index
                    ),
                    self::FPS
                );


            $startFrame =
                $index === 0
                    ? 0
                    : max(
                        0,
                        $cursor
                        - $dissolveFrames
                    );

            $endFrame =
                $startFrame
                + $durationFrames;


            $slideLayers =
                $index === 0
                    ? self::introLayers(
                        $tools,
                        $slide,
                        $slideNumber,
                        $startFrame,
                        $endFrame,
                        $hasPhoto
                            ? (string)$photo[
                                'image_url'
                            ]
                            : null,
                        false
                    )
                    : (
                        $hasPhoto
                            ? self::photoLayers(
                                $tools,
                                $slide,
                                $slideNumber,
                                $startFrame,
                                $endFrame,
                                (string)$photo[
                                    'image_url'
                                ],
                                true
                            )
                            : self::textLayers(
                                $tools,
                                $slide,
                                $slideNumber,
                                $startFrame,
                                $endFrame,
                                true,
                                false
                            )
                    );


            foreach (
                $slideLayers
                as $layer
            ) {
                $layers[] =
                    $layer;
            }


            $cursor =
                $endFrame;
        }


        $totalFrames =
            $cursor;


        if ($totalFrames <= 0) {
            throw new RuntimeException(
                'YouTube Playlist Video Recipe calculated an invalid duration.'
            );
        }


        self::appendFinalFade(
            $tools,
            $layers,
            $totalFrames
        );


        return $tools->videoPlan(
            width:
                self::WIDTH,

            height:
                self::HEIGHT,

            fps:
                self::FPS,

            durationInFrames:
                $totalFrames,

            codec:
                self::CODEC,

            layers:
                $layers
        );
    }


    /**
     * The first slide is a YouTube intro by position.
     *
     * If it contains text, the intro is text-led. A supplied photo
     * may sit behind it. If it is photo-only, the recipe still shows
     * the photo rather than manufacturing a blank title screen.
     */
    private static function introLayers(
        VideoCreatorTools $tools,
        array $slide,
        int $slideNumber,
        int $startFrame,
        int $endFrame,
        ?string $imageUrl,
        bool $fadeIn
    ): array {
        if (
            self::hasText(
                $slide
            )
        ) {
            return self::textLayers(
                $tools,
                $slide,
                $slideNumber,
                $startFrame,
                $endFrame,
                $fadeIn,
                true,
                $imageUrl
            );
        }


        if (
            $imageUrl !== null
            && trim(
                $imageUrl
            ) !== ''
        ) {
            return self::photoLayers(
                $tools,
                $slide,
                $slideNumber,
                $startFrame,
                $endFrame,
                $imageUrl,
                $fadeIn
            );
        }


        throw new RuntimeException(
            "YouTube intro slide {$slideNumber} has no renderable content."
        );
    }


    /**
     * Photo slide: full-frame contained photo with delayed caption.
     */
    private static function photoLayers(
        VideoCreatorTools $tools,
        array $slide,
        int $slideNumber,
        int $startFrame,
        int $endFrame,
        string $imageUrl,
        bool $fadeIn
    ): array {
        $zBase =
            $slideNumber
            * 20;


        $backgroundAnimations =
            self::entryAnimations(
                $tools,
                $startFrame,
                $fadeIn
            );

        $imageAnimations =
            self::entryAnimations(
                $tools,
                $startFrame,
                $fadeIn
            );


        $layers = [
            $tools->rectLayer(
                id:
                    "yt-slide-{$slideNumber}-background",

                startFrame:
                    $startFrame,

                endFrame:
                    $endFrame,

                box:
                    $tools->box(
                        0,
                        0,
                        self::WIDTH,
                        self::HEIGHT,
                        $zBase
                    ),

                style: [
                    'backgroundColor' =>
                        self::BACKGROUND_COLOR,

                    'opacity' =>
                        $fadeIn
                            ? 0
                            : 1,
                ],

                animations:
                    $backgroundAnimations
            ),

            $tools->imageLayer(
                id:
                    "yt-slide-{$slideNumber}-photo",

                src:
                    $imageUrl,

                fit:
                    self::PHOTO_OBJECT_FIT,

                startFrame:
                    $startFrame,

                endFrame:
                    $endFrame,

                box:
                    $tools->box(
                        0,
                        0,
                        self::WIDTH,
                        self::HEIGHT,
                        $zBase + 1
                    ),

                style: [
                    'opacity' =>
                        $fadeIn
                            ? 0
                            : 1,
                ],

                animations:
                    $imageAnimations
            ),
        ];


        $captionText =
            self::captionText(
                $slide
            );


        if ($captionText === '') {
            return $layers;
        }


        $captionDelay =
            $tools->secondsToFrames(
                self::CAPTION_DELAY_SECONDS,
                self::FPS
            );

        $captionFade =
            $tools->secondsToFrames(
                self::CAPTION_FADE_SECONDS,
                self::FPS
            );

        $captionFadeStart =
            min(
                $endFrame - 1,
                $startFrame
                + $captionDelay
            );

        $captionFadeEnd =
            min(
                $endFrame - 1,
                $captionFadeStart
                + $captionFade
            );


        $captionHeight =
            self::captionHeight(
                $slide
            );


        $captionTop =
            self::HEIGHT
            - self::CAPTION_BOTTOM
            - $captionHeight;


        $captionAnimation =
            [];


        if (
            $captionFadeEnd
            > $captionFadeStart
        ) {
            $captionAnimation[] =
                $tools->opacityAnimation(
                    0,
                    1,
                    $captionFadeStart,
                    $captionFadeEnd
                );
        }


        $layers[] =
            $tools->textLayer(
                id:
                    "yt-slide-{$slideNumber}-caption",

                text:
                    $captionText,

                startFrame:
                    $startFrame,

                endFrame:
                    $endFrame,

                box:
                    $tools->box(
                        self::CAPTION_LEFT,
                        $captionTop,
                        self::CAPTION_MAX_WIDTH,
                        $captionHeight,
                        $zBase + 5
                    ),

                style: [
                    'display' =>
                        'flex',

                    'alignItems' =>
                        'center',

                    'padding' =>
                        self::CAPTION_PADDING_Y
                        . 'px '
                        . self::CAPTION_PADDING_X
                        . 'px',

                    'backgroundColor' =>
                        self::CAPTION_BACKGROUND,

                    'color' =>
                        self::TEXT_COLOR,

                    'fontFamily' =>
                        self::FONT_FAMILY,

                    'fontSize' =>
                        self::CAPTION_TITLE_FONT_SIZE,

                    'fontWeight' =>
                        self::CAPTION_TITLE_FONT_WEIGHT,

                    'lineHeight' =>
                        self::CAPTION_TITLE_LINE_HEIGHT,

                    'whiteSpace' =>
                        'pre-wrap',

                    'opacity' =>
                        0,
                ],

                animations:
                    $captionAnimation
            );


        return $layers;
    }


    /**
     * Text-only screen. Used for the first-slide intro treatment and
     * later slides that intentionally contain text but no photo.
     *
     * When $backgroundImageUrl is supplied for an intro, the image is
     * used as an optional visual backdrop while the text treatment
     * remains the dominant YouTube intro presentation.
     */
    private static function textLayers(
        VideoCreatorTools $tools,
        array $slide,
        int $slideNumber,
        int $startFrame,
        int $endFrame,
        bool $fadeIn,
        bool $intro,
        ?string $backgroundImageUrl = null
    ): array {
        $zBase =
            $slideNumber
            * 20;


        $entryAnimations =
            self::entryAnimations(
                $tools,
                $startFrame,
                $fadeIn
            );


        $layers = [
            $tools->rectLayer(
                id:
                    "yt-slide-{$slideNumber}-text-background",

                startFrame:
                    $startFrame,

                endFrame:
                    $endFrame,

                box:
                    $tools->box(
                        0,
                        0,
                        self::WIDTH,
                        self::HEIGHT,
                        $zBase
                    ),

                style: [
                    'backgroundColor' =>
                        self::BACKGROUND_COLOR,

                    'opacity' =>
                        $fadeIn
                            ? 0
                            : 1,
                ],

                animations:
                    $entryAnimations
            ),
        ];


        if (
            $backgroundImageUrl !== null
            && trim(
                $backgroundImageUrl
            ) !== ''
        ) {
            $layers[] =
                $tools->imageLayer(
                    id:
                        "yt-slide-{$slideNumber}-intro-photo",

                    src:
                        $backgroundImageUrl,

                    fit:
                        self::PHOTO_OBJECT_FIT,

                    startFrame:
                        $startFrame,

                    endFrame:
                        $endFrame,

                    box:
                        $tools->box(
                            0,
                            0,
                            self::WIDTH,
                            self::HEIGHT,
                            $zBase + 1
                        ),

                    style: [
                        'opacity' =>
                            0.36,
                    ],

                    animations:
                        $entryAnimations
                );
        }


        $title =
            trim(
                (string)(
                    $slide[
                        'title'
                    ]
                    ?? ''
                )
            );

        $subtitle =
            trim(
                (string)(
                    $slide[
                        'subtitle'
                    ]
                    ?? ''
                )
            );

        $body =
            trim(
                (string)(
                    $slide[
                        'body'
                    ]
                    ?? ''
                )
            );


        $titleSize =
            $intro
                ? self::INTRO_TITLE_FONT_SIZE
                : self::TEXT_TITLE_FONT_SIZE;

        $subtitleSize =
            $intro
                ? self::INTRO_SUBTITLE_FONT_SIZE
                : self::TEXT_SUBTITLE_FONT_SIZE;

        $bodySize =
            $intro
                ? self::INTRO_BODY_FONT_SIZE
                : self::TEXT_BODY_FONT_SIZE;


        $availableWidth =
            self::WIDTH
            - (
                self::TEXT_SCREEN_PADDING_X
                * 2
            );

        $availableHeight =
            self::HEIGHT
            - (
                self::TEXT_SCREEN_PADDING_Y
                * 2
            );


        $textStartFrame =
            $startFrame;

        $textFadeEnd =
            min(
                $endFrame - 1,
                $startFrame
                + $tools->secondsToFrames(
                    $intro
                        ? 0.9
                        : self::CAPTION_FADE_SECONDS,
                    self::FPS
                )
            );


        $textAnimation =
            [];


        if (
            $fadeIn
            || $textFadeEnd
                > $textStartFrame
        ) {
            if (
                $textFadeEnd
                > $textStartFrame
            ) {
                $textAnimation[] =
                    $tools->opacityAnimation(
                        0,
                        1,
                        $textStartFrame,
                        $textFadeEnd
                    );
            }
        }


        $textPieces = [];


        if ($title !== '') {
            $textPieces[] =
                $title;
        }

        if ($subtitle !== '') {
            $textPieces[] =
                $subtitle;
        }

        if ($body !== '') {
            $textPieces[] =
                $body;
        }


        $text =
            implode(
                "\n\n",
                $textPieces
            );


        if ($text === '') {
            return $layers;
        }


        /*
         * GenericVideo currently gives one text layer one typography
         * style. Until we add a reusable rich-text/helper primitive,
         * choose a size based on the strongest text field present.
         * The Recipe owns this compromise and can replace it later.
         */
        $fontSize =
            $title !== ''
                ? $titleSize
                : (
                    $subtitle !== ''
                        ? $subtitleSize
                        : $bodySize
                );


        $layers[] =
            $tools->textLayer(
                id:
                    "yt-slide-{$slideNumber}-text",

                text:
                    $text,

                startFrame:
                    $startFrame,

                endFrame:
                    $endFrame,

                box:
                    $tools->box(
                        self::TEXT_SCREEN_PADDING_X,
                        self::TEXT_SCREEN_PADDING_Y,
                        $availableWidth,
                        $availableHeight,
                        $zBase + 5
                    ),

                style: [
                    'display' =>
                        'flex',

                    'alignItems' =>
                        'center',

                    'justifyContent' =>
                        'center',

                    'color' =>
                        self::TEXT_COLOR,

                    'fontFamily' =>
                        self::FONT_FAMILY,

                    'fontSize' =>
                        $fontSize,

                    'fontWeight' =>
                        $title !== ''
                            ? 600
                            : 400,

                    'lineHeight' =>
                        1.2,

                    'textAlign' =>
                        'center',

                    'whiteSpace' =>
                        'pre-wrap',

                    'opacity' =>
                        0,
                ],

                animations:
                    $textAnimation
            );


        return $layers;
    }


    private static function appendFinalFade(
        VideoCreatorTools $tools,
        array &$layers,
        int $totalFrames
    ): void {
        $fadeFrames =
            $tools->secondsToFrames(
                self::FINAL_FADE_SECONDS,
                self::FPS
            );


        $startFrame =
            max(
                0,
                $totalFrames
                - $fadeFrames
            );


        if (
            $totalFrames
            <= $startFrame
        ) {
            return;
        }


        $layers[] =
            $tools->rectLayer(
                id:
                    'yt-final-fade',

                startFrame:
                    $startFrame,

                endFrame:
                    $totalFrames,

                box:
                    $tools->box(
                        0,
                        0,
                        self::WIDTH,
                        self::HEIGHT,
                        100000
                    ),

                style: [
                    'backgroundColor' =>
                        self::BACKGROUND_COLOR,

                    'opacity' =>
                        0,
                ],

                animations: [
                    $tools->opacityAnimation(
                        0,
                        1,
                        $startFrame,
                        $totalFrames - 1
                    ),
                ]
            );
    }


    private static function durationSecondsForSlide(
        array $slide,
        int $index
    ): float {
        if ($index === 0) {
            return self::INTRO_SECONDS;
        }


        $hasPhoto =
            is_array(
                $slide[
                    'photo'
                ]
                ?? null
            )
            && trim(
                (string)(
                    $slide[
                        'photo'
                    ][
                        'image_url'
                    ]
                    ?? ''
                )
            ) !== '';


        if (!$hasPhoto) {
            return self::TEXT_ONLY_SECONDS;
        }


        $itemType =
            strtolower(
                trim(
                    (string)(
                        $slide[
                            'item_type'
                        ]
                        ?? ''
                    )
                )
            );


        return match (
            $itemType
        ) {
            'palette' =>
                self::PALETTE_PHOTO_SECONDS,

            'non-palette' =>
                self::NON_PALETTE_PHOTO_SECONDS,

            'normal' =>
                self::NORMAL_PHOTO_SECONDS,

            default =>
                throw new RuntimeException(
                    "YouTube Playlist Video Recipe does not support item_type '{$itemType}'."
                ),
        };
    }


    private static function entryAnimations(
        VideoCreatorTools $tools,
        int $startFrame,
        bool $fadeIn
    ): array {
        if (!$fadeIn) {
            return [];
        }


        $fadeEnd =
            $startFrame
            + $tools->secondsToFrames(
                self::DISSOLVE_SECONDS,
                self::FPS
            );


        return [
            $tools->opacityAnimation(
                0,
                1,
                $startFrame,
                $fadeEnd
            ),
        ];
    }


    private static function hasText(
        array $slide
    ): bool {
        foreach (
            [
                'title',
                'subtitle',
                'body',
            ]
            as $field
        ) {
            if (
                trim(
                    (string)(
                        $slide[
                            $field
                        ]
                        ?? ''
                    )
                ) !== ''
            ) {
                return true;
            }
        }


        return false;
    }


    private static function captionText(
        array $slide
    ): string {
        $parts = [];


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
                        $slide[
                            $field
                        ]
                        ?? ''
                    )
                );


            if ($value !== '') {
                $parts[] =
                    $value;
            }
        }


        return implode(
            "\n",
            $parts
        );
    }


    private static function captionHeight(
        array $slide
    ): int {
        $lineCount = 0;


        foreach (
            [
                'title',
                'subtitle',
                'body',
            ]
            as $field
        ) {
            if (
                trim(
                    (string)(
                        $slide[
                            $field
                        ]
                        ?? ''
                    )
                ) !== ''
            ) {
                $lineCount++;
            }
        }


        return max(
            76,
            (
                self::CAPTION_PADDING_Y
                * 2
            )
            + (
                $lineCount
                * 45
            )
        );
    }
}