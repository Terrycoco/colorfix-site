<?php
declare(strict_types=1);

namespace App\PUB\Create\Pinterest;

use App\PUB\Create\Video\Support\VideoCreatorTools;

/**
 * PINTEREST BEFORE / AFTER VIDEO RECIPE
 *
 * Product authority for the Pinterest Before/After Video Creator.
 *
 * This class owns every BAV-specific product decision and compiles
 * those decisions into the generic render-plan language understood
 * by the shared Remotion oven.
 *
 * Remotion does not know:
 * - Pinterest
 * - before / after
 * - dissolve timing
 * - phase labels
 * - end screens
 * - BAV layout
 * - BAV typography
 *
 * It receives only generic layers, frame ranges, styles,
 * animations, and render settings.
 */
final class BeforeAfterVideoRecipe
{
    public const COMPOSITION_ID =
        'colorfix-generic-video';

    public const OUTPUT_MIME_TYPE =
        'video/mp4';

    public const CODEC =
        'h264';


    /* VIDEO */
    public const WIDTH = 1000;
    public const HEIGHT = 1500;
    public const FPS = 30;


    /* TIMING — seconds */
    public const BEFORE_SECONDS = 1.5;
    public const DISSOLVE_SECONDS = 2.0;
    public const AFTER_SECONDS = 3.0;
    public const END_SCREEN_SECONDS = 4.0;

    public const END_SCREEN_FADE_SECONDS = 0.5;
    public const FINAL_FADE_SECONDS = 0.5;

    public const LOGO_START_SECONDS = 0.35;
    public const LOGO_FINISH_SECONDS = 1.5;


    /* COPY */
    public const BEFORE_LABEL = 'BEFORE';
    public const AFTER_LABEL = 'AFTER';


    /* VISUAL CONSTANTS */
    public const BACKGROUND_COLOR = '#000000';
    public const TEXT_COLOR = '#ffffff';
    public const FONT_FAMILY = 'Arial, sans-serif';

    public const TITLE_HEIGHT = 130;
    public const IMAGE_HEIGHT = 700;
    public const PHASE_HEIGHT = 100;

    public const TITLE_PADDING = '10px 60px 12px';
    public const TITLE_FONT_SIZE = 48;
    public const TITLE_FONT_WEIGHT = 700;
    public const TITLE_LINE_HEIGHT = 1.08;

    public const IMAGE_BACKGROUND_COLOR = '#111';
    public const IMAGE_OBJECT_FIT = 'contain';

    public const PHASE_FONT_SIZE = 40;
    public const PHASE_FONT_WEIGHT = 800;
    public const PHASE_LETTER_SPACING = 5;

    public const END_SCREEN_PADDING = 80;

    public const END_SLIDE_FONT_SIZE = 68;
    public const END_SLIDE_FONT_WEIGHT = 700;
    public const END_SLIDE_LINE_HEIGHT = 1.08;

    public const LOGO_MARGIN_TOP = 55;
    public const LOGO_SIZE_PX = 94;


    public static function durationSeconds(): float
    {
        return
            self::BEFORE_SECONDS
            + self::DISSOLVE_SECONDS
            + self::AFTER_SECONDS
            + self::END_SCREEN_SECONDS;
    }


    public static function durationMs(): int
    {
        return (int)round(
            self::durationSeconds()
            * 1000
        );
    }


    public static function durationInFrames(): int
    {
        return (int)round(
            self::durationSeconds()
            * self::FPS
        );
    }


    /**
     * Compile the complete BAV recipe into generic oven instructions.
     */
    public static function plan(
        string $beforeImageUrl,
        string $afterImageUrl,
        string $searchTitle,
        string $endSlideText
    ): array {
        $tools =
            new VideoCreatorTools();


        $totalFrames =
            self::durationInFrames();

        $dissolveStart =
            $tools->secondsToFrames(
                self::BEFORE_SECONDS,
                self::FPS
            );

        $dissolveEnd =
            $tools->secondsToFrames(
                self::BEFORE_SECONDS
                + self::DISSOLVE_SECONDS,
                self::FPS
            );

        $endScreenStart =
            $tools->secondsToFrames(
                self::BEFORE_SECONDS
                + self::DISSOLVE_SECONDS
                + self::AFTER_SECONDS,
                self::FPS
            );

        $endScreenFadeEnd =
            $endScreenStart
            + $tools->secondsToFrames(
                self::END_SCREEN_FADE_SECONDS,
                self::FPS
            );

        $finalFadeStart =
            $totalFrames
            - $tools->secondsToFrames(
                self::FINAL_FADE_SECONDS,
                self::FPS
            );

        $logoRevealStart =
            $endScreenStart
            + $tools->secondsToFrames(
                self::LOGO_START_SECONDS,
                self::FPS
            );

        $logoRevealEnd =
            $endScreenStart
            + $tools->secondsToFrames(
                self::LOGO_FINISH_SECONDS,
                self::FPS
            );


        /*
         * The old Remotion layout was a centered 930px-high grid:
         *
         * 130 title
         * 700 image
         * 100 phase label
         *
         * The Recipe now computes those exact positions itself.
         */
        $mainHeight =
            self::TITLE_HEIGHT
            + self::IMAGE_HEIGHT
            + self::PHASE_HEIGHT;

        $mainTop =
            (int)round(
                (
                    self::HEIGHT
                    - $mainHeight
                )
                / 2
            );

        $titleTop =
            $mainTop;

        $imageTop =
            $titleTop
            + self::TITLE_HEIGHT;

        $phaseTop =
            $imageTop
            + self::IMAGE_HEIGHT;


        $mainFade =
            $tools->opacityAnimation(
                1,
                0,
                $endScreenStart,
                $endScreenFadeEnd
            );

        $endFade =
            $tools->opacityAnimation(
                0,
                1,
                $endScreenStart,
                $endScreenFadeEnd
            );


        $layers = [

            /*
             * Canvas.
             */
            $tools->rectLayer(
                id:
                    'background',

                startFrame:
                    0,

                endFrame:
                    $totalFrames,

                box:
                    $tools->box(
                        0,
                        0,
                        self::WIDTH,
                        self::HEIGHT,
                        0
                    ),

                style: [
                    'backgroundColor' =>
                        self::BACKGROUND_COLOR,
                ]
            ),


            /*
             * Main title.
             */
            $tools->textLayer(
                id:
                    'search-title',

                text:
                    $searchTitle,

                startFrame:
                    0,

                endFrame:
                    $endScreenFadeEnd,

                box:
                    $tools->box(
                        0,
                        $titleTop,
                        self::WIDTH,
                        self::TITLE_HEIGHT,
                        20
                    ),

                style: [
                    'display' =>
                        'flex',

                    'alignItems' =>
                        'center',

                    'justifyContent' =>
                        'center',

                    'padding' =>
                        self::TITLE_PADDING,

                    'color' =>
                        self::TEXT_COLOR,

                    'fontFamily' =>
                        self::FONT_FAMILY,

                    'fontSize' =>
                        self::TITLE_FONT_SIZE,

                    'fontWeight' =>
                        self::TITLE_FONT_WEIGHT,

                    'lineHeight' =>
                        self::TITLE_LINE_HEIGHT,

                    'textAlign' =>
                        'center',
                ],

                animations: [
                    $mainFade,
                ]
            ),


            /*
             * Image-area background.
             */
            $tools->rectLayer(
                id:
                    'image-background',

                startFrame:
                    0,

                endFrame:
                    $endScreenFadeEnd,

                box:
                    $tools->box(
                        0,
                        $imageTop,
                        self::WIDTH,
                        self::IMAGE_HEIGHT,
                        5
                    ),

                style: [
                    'backgroundColor' =>
                        self::IMAGE_BACKGROUND_COLOR,
                ],

                animations: [
                    $mainFade,
                ]
            ),


            /*
             * Before image.
             */
            $tools->imageLayer(
                id:
                    'before-image',

                src:
                    $beforeImageUrl,

                fit:
                    self::IMAGE_OBJECT_FIT,

                startFrame:
                    0,

                endFrame:
                    $endScreenFadeEnd,

                box:
                    $tools->box(
                        0,
                        $imageTop,
                        self::WIDTH,
                        self::IMAGE_HEIGHT,
                        10
                    ),

                animations: [
                    $mainFade,
                ]
            ),


            /*
             * After image.
             *
             * It sits above the before image and the Chef tells
             * the oven exactly when to dissolve its opacity.
             */
            $tools->imageLayer(
                id:
                    'after-image',

                src:
                    $afterImageUrl,

                fit:
                    self::IMAGE_OBJECT_FIT,

                startFrame:
                    0,

                endFrame:
                    $endScreenFadeEnd,

                box:
                    $tools->box(
                        0,
                        $imageTop,
                        self::WIDTH,
                        self::IMAGE_HEIGHT,
                        11
                    ),

                style: [
                    'opacity' =>
                        0,
                ],

                animations: [
                    $tools->opacityAnimation(
                        0,
                        1,
                        $dissolveStart,
                        $dissolveEnd
                    ),

                    $mainFade,
                ]
            ),


            /*
             * BEFORE label.
             */
            $tools->textLayer(
                id:
                    'before-label',

                text:
                    self::BEFORE_LABEL,

                startFrame:
                    0,

                endFrame:
                    $dissolveEnd,

                box:
                    $tools->box(
                        0,
                        $phaseTop,
                        self::WIDTH,
                        self::PHASE_HEIGHT,
                        20
                    ),

                style:
                    self::phaseLabelStyle()
            ),


            /*
             * AFTER label.
             */
            $tools->textLayer(
                id:
                    'after-label',

                text:
                    self::AFTER_LABEL,

                startFrame:
                    $dissolveEnd,

                endFrame:
                    $endScreenFadeEnd,

                box:
                    $tools->box(
                        0,
                        $phaseTop,
                        self::WIDTH,
                        self::PHASE_HEIGHT,
                        20
                    ),

                style:
                    self::phaseLabelStyle(),

                animations: [
                    $mainFade,
                ]
            ),


            /*
             * End-screen copy.
             *
             * The Recipe owns the copy and placement.
             */
            $tools->textLayer(
                id:
                    'end-slide-text',

                text:
                    $endSlideText,

                startFrame:
                    $endScreenStart,

                endFrame:
                    $totalFrames,

                box:
                    $tools->box(
                        self::END_SCREEN_PADDING,
                        0,
                        self::WIDTH
                        - (
                            self::END_SCREEN_PADDING
                            * 2
                        ),
                        self::HEIGHT,
                        30
                    ),

                style: [
                    'display' =>
                        'flex',

                    'alignItems' =>
                        'center',

                    'justifyContent' =>
                        'center',

                    'paddingBottom' =>
                        self::LOGO_MARGIN_TOP
                        + self::LOGO_SIZE_PX,

                    'color' =>
                        self::TEXT_COLOR,

                    'fontFamily' =>
                        self::FONT_FAMILY,

                    'fontSize' =>
                        self::END_SLIDE_FONT_SIZE,

                    'fontWeight' =>
                        self::END_SLIDE_FONT_WEIGHT,

                    'lineHeight' =>
                        self::END_SLIDE_LINE_HEIGHT,

                    'textAlign' =>
                        'center',

                    'opacity' =>
                        0,
                ],

                animations: [
                    $endFade,
                ]
            ),


            /*
             * Reusable brand-bumper primitive.
             *
             * The generic oven knows how to draw this component,
             * but this Recipe decides whether to use it, where it
             * appears, its size, and its reveal timing.
             */
            $tools->componentLayer(
                id:
                    'brand-bumper',

                component:
                    'brand_bumper_logo',

                startFrame:
                    $endScreenStart,

                endFrame:
                    $totalFrames,

                box:
                    $tools->box(
                        (
                            self::WIDTH
                            - self::LOGO_SIZE_PX
                        )
                        / 2,
                        (
                            self::HEIGHT
                            / 2
                        )
                        + self::LOGO_MARGIN_TOP,
                        self::LOGO_SIZE_PX,
                        self::LOGO_SIZE_PX,
                        31
                    ),

                props: [
                    'signatureProgress' =>
                        0,

                    'style' => [
                        '--brand-bumper-logo-size' =>
                            self::LOGO_SIZE_PX
                            . 'px',
                    ],
                ],

                style: [
                    'opacity' =>
                        0,
                ],

                animations: [
                    $endFade,

                    $tools->numberAnimation(
                        target:
                            'props.signatureProgress',

                        from:
                            0,

                        to:
                            1,

                        startFrame:
                            $logoRevealStart,

                        endFrame:
                            $logoRevealEnd
                    ),
                ]
            ),


            /*
             * Final fade to black.
             */
            $tools->rectLayer(
                id:
                    'final-fade',

                startFrame:
                    $finalFadeStart,

                endFrame:
                    $totalFrames,

                box:
                    $tools->box(
                        0,
                        0,
                        self::WIDTH,
                        self::HEIGHT,
                        100
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
                        $finalFadeStart,
                        $totalFrames - 1
                    ),
                ]
            ),
        ];


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


    private static function phaseLabelStyle(): array
    {
        return [
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
                self::PHASE_FONT_SIZE,

            'fontWeight' =>
                self::PHASE_FONT_WEIGHT,

            'letterSpacing' =>
                self::PHASE_LETTER_SPACING,
        ];
    }
}