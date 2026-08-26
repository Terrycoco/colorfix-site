<?php
declare(strict_types=1);

namespace App\PUB\Create\Video\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * VIDEO LAYER BUILDER / CURRENT RENDERER TRANSLATOR
 *
 * Converts one complete renderer-neutral video blueprint into the
 * generic layer language consumed by today's Remotion oven.
 *
 * The Creator/Chef owns WHAT the finished video is:
 *   - scene order
 *   - milliseconds
 *   - visual intent
 *   - product layout/style values
 *
 * This translator owns HOW those neutral instructions are expressed
 * for the current renderer:
 *   - milliseconds -> frames
 *   - neutral photo/text/brand-bumper scenes -> generic layers
 *   - neutral opacity/signature behavior -> oven animation targets
 *   - renderer recipe/composition key
 *
 * A future renderer should be able to consume the same Chef blueprint
 * through a different translator without changing the Creator.
 */
final class VideoLayerBuilder
{
    /**
     * Current generic Remotion composition understood by the worker.
     * This is renderer knowledge, so it lives here rather than in a
     * product Recipe or Creator.
     */
    private const VIDEO_RECIPE_KEY =
        'colorfix-generic-video';


    /**
     * Registered generic-oven component used to realize the standard
     * ColorFix brand visual.
     */
    private const COLORFIX_BRAND_COMPONENT =
        'brand_bumper_logo';


    /**
     * Registered Remotion support component for deterministic hue-wheel
     * rendering.
     */
    private const HUE_WHEEL_COMPONENT =
        'hue_wheel';


    /**
     * Translate one complete neutral blueprint in one pass.
     *
     * @return array{
     *   video_recipe_key: string,
     *   render_plan: array<string, mixed>
     * }
     */
    public function translate(
        array $blueprint,
        int $fps,
        string $codec
    ): array {
        if ($fps <= 0) {
            throw new InvalidArgumentException(
                'Video translator fps must be greater than zero.'
            );
        }


        $codec =
            trim(
                $codec
            );


        if ($codec === '') {
            throw new InvalidArgumentException(
                'Video translator codec is required.'
            );
        }


        $canvas =
            $this->requireArray(
                $blueprint[
                    'canvas'
                ]
                ?? null,
                'blueprint.canvas'
            );


        $width =
            $this->positiveInt(
                $canvas[
                    'width'
                ]
                ?? null,
                'blueprint.canvas.width'
            );

        $height =
            $this->positiveInt(
                $canvas[
                    'height'
                ]
                ?? null,
                'blueprint.canvas.height'
            );

        $backgroundColor =
            $this->requiredString(
                $canvas[
                    'background_color'
                ]
                ?? '#000000',
                'blueprint.canvas.background_color'
            );


        $durationMs =
            $this->positiveInt(
                $blueprint[
                    'duration_ms'
                ]
                ?? null,
                'blueprint.duration_ms'
            );

        $totalFrames =
            max(
                1,
                $this->msToFrames(
                    $durationMs,
                    $fps
                )
            );


        $scenes =
            $this->requireArray(
                $blueprint[
                    'scenes'
                ]
                ?? null,
                'blueprint.scenes'
            );


        if ($scenes === []) {
            throw new RuntimeException(
                'Video translator received a blueprint with no scenes.'
            );
        }


        $tools =
            new VideoCreatorTools();

        $layers = [];


        /*
         * One renderer-level canvas behind the whole movie.
         * Individual scenes therefore do not need to manufacture their
         * own black canvases just to survive dissolves/letterboxing.
         */
        $layers[] =
            $tools->rectLayer(
                id:
                    'video-background',

                startFrame:
                    0,

                endFrame:
                    $totalFrames,

                box:
                    $tools->box(
                        0,
                        0,
                        $width,
                        $height,
                        0
                    ),

                style: [
                    'backgroundColor' =>
                        $backgroundColor,
                ]
            );


        foreach (
            array_values(
                $scenes
            )
            as $sceneIndex => $scene
        ) {
            if (!is_array($scene)) {
                throw new RuntimeException(
                    "Video blueprint scene {$sceneIndex} must be an array."
                );
            }


            foreach (
                $this->translateScene(
                    tools:
                        $tools,

                    scene:
                        $scene,

                    sceneIndex:
                        $sceneIndex,

                    width:
                        $width,

                    height:
                        $height,

                    fps:
                        $fps,

                    totalFrames:
                        $totalFrames
                )
                as $layer
            ) {
                $layers[] =
                    $layer;
            }
        }


        $this->appendFinalFade(
            tools:
                $tools,

            layers:
                $layers,

            finalFade:
                is_array(
                    $blueprint[
                        'final_fade'
                    ]
                    ?? null
                )
                    ? $blueprint[
                        'final_fade'
                    ]
                    : [],

            width:
                $width,

            height:
                $height,

            fps:
                $fps,

            totalFrames:
                $totalFrames,

            defaultColor:
                $backgroundColor
        );


        $audio =
            $this->translateAudio(
                blueprintAudio:
                    is_array(
                        $blueprint[
                            'audio'
                        ]
                        ?? null
                    )
                        ? $blueprint[
                            'audio'
                        ]
                        : [],

                fps:
                    $fps,

                totalFrames:
                    $totalFrames
            );


        return [
            'video_recipe_key' =>
                self::VIDEO_RECIPE_KEY,

            'render_plan' =>
                $tools->videoPlan(
                    width:
                        $width,

                    height:
                        $height,

                    fps:
                        $fps,

                    durationInFrames:
                        $totalFrames,

                    codec:
                        $codec,

                    layers:
                        $layers,

                    audio:
                        $audio
                ),
        ];
    }


    /**
     * Translate one neutral scene.
     *
     * The neutral vocabulary is intentionally small. Product-specific
     * item types have already been interpreted by the Chef before this
     * point.
     *
     * @return array<int, array<string, mixed>>
     */
    private function translateScene(
        VideoCreatorTools $tools,
        array $scene,
        int $sceneIndex,
        int $width,
        int $height,
        int $fps,
        int $totalFrames
    ): array {
        $sceneType =
            strtolower(
                $this->requiredString(
                    $scene[
                        'type'
                    ]
                    ?? '',
                    "blueprint.scenes[{$sceneIndex}].type"
                )
            );


        $startMs =
            $this->nonNegativeInt(
                $scene[
                    'start_ms'
                ]
                ?? null,
                "blueprint.scenes[{$sceneIndex}].start_ms"
            );

        $durationMs =
            $this->positiveInt(
                $scene[
                    'duration_ms'
                ]
                ?? null,
                "blueprint.scenes[{$sceneIndex}].duration_ms"
            );


        $startFrame =
            min(
                $totalFrames - 1,
                $this->msToFrames(
                    $startMs,
                    $fps
                )
            );

        $endFrame =
            min(
                $totalFrames,
                max(
                    $startFrame + 1,
                    $this->msToFrames(
                        $startMs
                        + $durationMs,
                        $fps
                    )
                )
            );


        if ($endFrame <= $startFrame) {
            throw new RuntimeException(
                "Video blueprint scene {$sceneIndex} falls outside the video duration."
            );
        }


        $sceneId =
            trim(
                (string)(
                    $scene[
                        'id'
                    ]
                    ?? ''
                )
            );


        if ($sceneId === '') {
            $sceneId =
                'scene-'
                . (
                    $sceneIndex
                    + 1
                );
        }


        $zBase =
            100
            + (
                $sceneIndex
                * 20
            );


        $layers =
            match ($sceneType) {
            'photo' =>
                $this->translatePhotoScene(
                    tools:
                        $tools,

                    scene:
                        $scene,

                    sceneIndex:
                        $sceneIndex,

                    sceneId:
                        $sceneId,

                    startFrame:
                        $startFrame,

                    endFrame:
                        $endFrame,

                    width:
                        $width,

                    height:
                        $height,

                    zBase:
                        $zBase,

                    fps:
                        $fps
                ),

            'text' =>
                $this->translateTextScene(
                    tools:
                        $tools,

                    scene:
                        $scene,

                    sceneIndex:
                        $sceneIndex,

                    sceneId:
                        $sceneId,

                    startFrame:
                        $startFrame,

                    endFrame:
                        $endFrame,

                    width:
                        $width,

                    height:
                        $height,

                    zBase:
                        $zBase,

                    fps:
                        $fps
                ),

            'hue-wheel' =>
                $this->translateHueWheelScene(
                    tools:
                        $tools,

                    scene:
                        $scene,

                    sceneIndex:
                        $sceneIndex,

                    sceneId:
                        $sceneId,

                    startFrame:
                        $startFrame,

                    endFrame:
                        $endFrame,

                    width:
                        $width,

                    height:
                        $height,

                    zBase:
                        $zBase,

                    fps:
                        $fps
                ),

            'brand-bumper' =>
                $this->translateBrandBumperScene(
                    tools:
                        $tools,

                    scene:
                        $scene,

                    sceneIndex:
                        $sceneIndex,

                    sceneId:
                        $sceneId,

                    startFrame:
                        $startFrame,

                    endFrame:
                        $endFrame,

                    width:
                        $width,

                    height:
                        $height,

                    zBase:
                        $zBase,

                    fps:
                        $fps
                ),

            default =>
                throw new RuntimeException(
                    "Video translator does not support neutral scene type '{$sceneType}'."
                ),
        };


        $exitFade =
            $this->translateSceneExitFade(
                tools:
                    $tools,

                scene:
                    $scene,

                sceneIndex:
                    $sceneIndex,

                startFrame:
                    $startFrame,

                endFrame:
                    $endFrame,

                width:
                    $width,

                height:
                    $height,

                zBase:
                    $zBase,

                fps:
                    $fps
            );


        if ($exitFade !== null) {
            $layers[] =
                $exitFade;
        }


        return $layers;
    }


    /**
     * Full-frame photo with optional delayed caption.
     *
     * @return array<int, array<string, mixed>>
     */
    private function translatePhotoScene(
        VideoCreatorTools $tools,
        array $scene,
        int $sceneIndex,
        string $sceneId,
        int $startFrame,
        int $endFrame,
        int $width,
        int $height,
        int $zBase,
        int $fps
    ): array {
        $photo =
            $this->requireArray(
                $scene[
                    'photo'
                ]
                ?? null,
                "blueprint.scenes[{$sceneIndex}].photo"
            );


        $src =
            $this->requiredString(
                $photo[
                    'src'
                ]
                ?? '',
                "blueprint.scenes[{$sceneIndex}].photo.src"
            );

        $fit =
            $this->requiredString(
                $photo[
                    'fit'
                ]
                ?? 'contain',
                "blueprint.scenes[{$sceneIndex}].photo.fit"
            );


        $entry =
            $this->entryOpacity(
                tools:
                    $tools,

                transition:
                    is_array(
                        $scene[
                            'transition_in'
                        ]
                        ?? null
                    )
                        ? $scene[
                            'transition_in'
                        ]
                        : null,

                startFrame:
                    $startFrame,

                endFrame:
                    $endFrame,

                fps:
                    $fps,

                targetOpacity:
                    1
            );


        $layers = [
            $tools->imageLayer(
                id:
                    $sceneId
                    . '-photo',

                src:
                    $src,

                fit:
                    $fit,

                startFrame:
                    $startFrame,

                endFrame:
                    $endFrame,

                box:
                    $tools->box(
                        0,
                        0,
                        $width,
                        $height,
                        $zBase + 1
                    ),

                style: [
                    'opacity' =>
                        $entry[
                            'base_opacity'
                        ],
                ],

                animations:
                    $entry[
                        'animations'
                    ]
            ),
        ];


        $caption =
            is_array(
                $scene[
                    'caption'
                ]
                ?? null
            )
                ? $scene[
                    'caption'
                ]
                : null;


        if ($caption === null) {
            return $layers;
        }


        $captionText =
            $this->captionText(
                $caption
            );


        if ($captionText === '') {
            return $layers;
        }


        $placement =
            is_array(
                $caption[
                    'placement'
                ]
                ?? null
            )
                ? $caption[
                    'placement'
                ]
                : [];


        $left =
            $this->nonNegativeInt(
                $placement[
                    'left_px'
                ]
                ?? 0,
                'caption.placement.left_px'
            );

        $bottom =
            $this->nonNegativeInt(
                $placement[
                    'bottom_px'
                ]
                ?? 0,
                'caption.placement.bottom_px'
            );

        $maxWidth =
            $this->positiveInt(
                $placement[
                    'max_width_px'
                ]
                ?? max(
                    1,
                    $width
                    - $left
                ),
                'caption.placement.max_width_px'
            );

        $paddingX =
            $this->nonNegativeInt(
                $placement[
                    'padding_x_px'
                ]
                ?? 0,
                'caption.placement.padding_x_px'
            );

        $paddingY =
            $this->nonNegativeInt(
                $placement[
                    'padding_y_px'
                ]
                ?? 0,
                'caption.placement.padding_y_px'
            );


        $titleStyle =
            is_array(
                $caption[
                    'title_style'
                ]
                ?? null
            )
                ? $caption[
                    'title_style'
                ]
                : [];


        /*
         * GenericVideo currently has a primitive text layer rather than a
         * rich-text component. Keep the established first-pass caption
         * treatment: joined copy in one box, using the title typography.
         * The neutral blueprint retains the richer distinctions so a future
         * oven/translator can render them without changing the Chef.
         */
        $fontSize =
            $this->positiveNumber(
                $titleStyle[
                    'font_size_px'
                ]
                ?? 34,
                'caption.title_style.font_size_px'
            );

        $fontWeight =
            $this->positiveNumber(
                $titleStyle[
                    'font_weight'
                ]
                ?? 500,
                'caption.title_style.font_weight'
            );

        $lineHeight =
            $this->positiveNumber(
                $titleStyle[
                    'line_height'
                ]
                ?? 1.25,
                'caption.title_style.line_height'
            );


        $captionHeight =
            $this->estimatedCaptionHeight(
                text:
                    $captionText,

                fontSize:
                    $fontSize,

                lineHeight:
                    $lineHeight,

                maxWidth:
                    $maxWidth,

                paddingX:
                    $paddingX,

                paddingY:
                    $paddingY
            );


        $captionTop =
            max(
                0,
                $height
                - $bottom
                - $captionHeight
            );


        $delayMs =
            $this->nonNegativeInt(
                $caption[
                    'delay_ms'
                ]
                ?? 0,
                'caption.delay_ms'
            );

        $fadeMs =
            $this->nonNegativeInt(
                $caption[
                    'fade_ms'
                ]
                ?? 0,
                'caption.fade_ms'
            );

        $fadeOutMs =
            $this->nonNegativeInt(
                $caption[
                    'fade_out_ms'
                ]
                ?? 0,
                'caption.fade_out_ms'
            );

        $fadeOutEndBeforeSceneEndMs =
            $this->nonNegativeInt(
                $caption[
                    'fade_out_end_before_scene_end_ms'
                ]
                ?? 0,
                'caption.fade_out_end_before_scene_end_ms'
            );


        $captionAnimations = [];

        $captionFadeStart =
            min(
                $endFrame - 1,
                $startFrame
                + $this->msToFrames(
                    $delayMs,
                    $fps
                )
            );

        $captionFadeEnd =
            min(
                $endFrame - 1,
                $captionFadeStart
                + $this->msToFrames(
                    $fadeMs,
                    $fps
                )
            );


        if (
            $captionFadeEnd
            > $captionFadeStart
        ) {
            $captionAnimations[] =
                $tools->opacityAnimation(
                    0,
                    1,
                    $captionFadeStart,
                    $captionFadeEnd
                );
        }


        /*
         * Outgoing caption is completely gone BEFORE the next picture
         * begins its overlap dissolve.
         */
        if ($fadeOutMs > 0) {
            $captionFadeOutFrames =
                max(
                    1,
                    $this->msToFrames(
                        $fadeOutMs,
                        $fps
                    )
                );

            $fadeOutEndBeforeFrames =
                $this->msToFrames(
                    $fadeOutEndBeforeSceneEndMs,
                    $fps
                );

            $captionFadeOutEnd =
                max(
                    $startFrame + 1,
                    $endFrame
                    - $fadeOutEndBeforeFrames
                );

            $captionFadeOutStart =
                max(
                    $captionFadeEnd,
                    $startFrame,
                    $captionFadeOutEnd
                    - $captionFadeOutFrames
                );


            if (
                $captionFadeOutEnd
                > $captionFadeOutStart
            ) {
                $captionAnimations[] =
                    $tools->opacityAnimation(
                        1,
                        0,
                        $captionFadeOutStart,
                        $captionFadeOutEnd
                    );
            }
        }


        $layers[] =
            $tools->textLayer(
                id:
                    $sceneId
                    . '-caption',

                text:
                    $captionText,

                startFrame:
                    $startFrame,

                endFrame:
                    $endFrame,

                box:
                    $tools->box(
                        $left,
                        $captionTop,
                        min(
                            $maxWidth,
                            max(
                                1,
                                $width
                                - $left
                            )
                        ),
                        $captionHeight,
                        $zBase + 5
                    ),

                style: [
                    'display' =>
                        'flex',

                    'alignItems' =>
                        'center',

                    'padding' =>
                        $paddingY
                        . 'px '
                        . $paddingX
                        . 'px',

                    'backgroundColor' =>
                        (string)(
                            $caption[
                                'background_color'
                            ]
                            ?? 'rgba(0, 0, 0, 0.45)'
                        ),

                    'color' =>
                        (string)(
                            $caption[
                                'color'
                            ]
                            ?? '#ffffff'
                        ),

                    'fontFamily' =>
                        (string)(
                            $caption[
                                'font_family'
                            ]
                            ?? 'Arial, sans-serif'
                        ),

                    'fontSize' =>
                        $fontSize,

                    'fontWeight' =>
                        $fontWeight,

                    'lineHeight' =>
                        $lineHeight,

                    'whiteSpace' =>
                        'pre-wrap',

                    'opacity' =>
                        0,
                ],

                animations:
                    $captionAnimations
            );


        return $layers;
    }


    /**
     * Text-led scene, optionally with a dimmed photo backdrop.
     *
     * @return array<int, array<string, mixed>>
     */
    private function translateTextScene(
        VideoCreatorTools $tools,
        array $scene,
        int $sceneIndex,
        string $sceneId,
        int $startFrame,
        int $endFrame,
        int $width,
        int $height,
        int $zBase,
        int $fps
    ): array {
        $text =
            $this->requireArray(
                $scene[
                    'text'
                ]
                ?? null,
                "blueprint.scenes[{$sceneIndex}].text"
            );


        $layers = [];


        $backgroundPhoto =
            is_array(
                $scene[
                    'background_photo'
                ]
                ?? null
            )
                ? $scene[
                    'background_photo'
                ]
                : null;


        if ($backgroundPhoto !== null) {
            $src =
                $this->requiredString(
                    $backgroundPhoto[
                        'src'
                    ]
                    ?? '',
                    "blueprint.scenes[{$sceneIndex}].background_photo.src"
                );

            $fit =
                $this->requiredString(
                    $backgroundPhoto[
                        'fit'
                    ]
                    ?? 'contain',
                    "blueprint.scenes[{$sceneIndex}].background_photo.fit"
                );

            $targetOpacity =
                $this->unitNumber(
                    $backgroundPhoto[
                        'opacity'
                    ]
                    ?? 1,
                    "blueprint.scenes[{$sceneIndex}].background_photo.opacity"
                );


            $entry =
                $this->entryOpacity(
                    tools:
                        $tools,

                    transition:
                        is_array(
                            $scene[
                                'transition_in'
                            ]
                            ?? null
                        )
                            ? $scene[
                                'transition_in'
                            ]
                            : null,

                    startFrame:
                        $startFrame,

                    endFrame:
                        $endFrame,

                    fps:
                        $fps,

                    targetOpacity:
                        $targetOpacity
                );


            $layers[] =
                $tools->imageLayer(
                    id:
                        $sceneId
                        . '-background-photo',

                    src:
                        $src,

                    fit:
                        $fit,

                    startFrame:
                        $startFrame,

                    endFrame:
                        $endFrame,

                    box:
                        $tools->box(
                            0,
                            0,
                            $width,
                            $height,
                            $zBase + 1
                        ),

                    style: [
                        'opacity' =>
                            $entry[
                                'base_opacity'
                            ],
                    ],

                    animations:
                        $entry[
                            'animations'
                        ]
                );
        }


        $title =
            trim(
                (string)(
                    $text[
                        'title'
                    ]
                    ?? ''
                )
            );

        $subtitle =
            trim(
                (string)(
                    $text[
                        'subtitle'
                    ]
                    ?? ''
                )
            );

        $body =
            trim(
                (string)(
                    $text[
                        'body'
                    ]
                    ?? ''
                )
            );


        $pieces = [];


        if ($title !== '') {
            $pieces[] = [
                'key' =>
                    'title',

                'text' =>
                    $title,

                'style' =>
                    is_array(
                        $text[
                            'title_style'
                        ]
                        ?? null
                    )
                        ? $text[
                            'title_style'
                        ]
                        : [],
            ];
        }


        if ($subtitle !== '') {
            $pieces[] = [
                'key' =>
                    'subtitle',

                'text' =>
                    $subtitle,

                'style' =>
                    is_array(
                        $text[
                            'subtitle_style'
                        ]
                        ?? null
                    )
                        ? $text[
                            'subtitle_style'
                        ]
                        : [],
            ];
        }


        if ($body !== '') {
            $pieces[] = [
                'key' =>
                    'body',

                'text' =>
                    $body,

                'style' =>
                    is_array(
                        $text[
                            'body_style'
                        ]
                        ?? null
                    )
                        ? $text[
                            'body_style'
                        ]
                        : [],
            ];
        }


        if ($pieces === []) {
            throw new RuntimeException(
                "Video text scene {$sceneIndex} contains no text."
            );
        }


        $paddingX =
            $this->nonNegativeInt(
                $text[
                    'padding_x_px'
                ]
                ?? 0,
                'text.padding_x_px'
            );

        $paddingY =
            $this->nonNegativeInt(
                $text[
                    'padding_y_px'
                ]
                ?? 0,
                'text.padding_y_px'
            );


        $availableWidth =
            max(
                1,
                $width
                - (
                    $paddingX
                    * 2
                )
            );

        $availableHeight =
            max(
                1,
                $height
                - (
                    $paddingY
                    * 2
                )
            );


        $lineHeight =
            $this->positiveNumber(
                $text[
                    'line_height'
                ]
                ?? 1.2,
                'text.line_height'
            );


        $pieceBoxes =
            $this->centeredTextPieceBoxes(
                pieces:
                    $pieces,

                availableWidth:
                    $availableWidth,

                availableHeight:
                    $availableHeight,

                top:
                    $paddingY,

                lineHeight:
                    $lineHeight
            );


        $fadeMs =
            $this->nonNegativeInt(
                $text[
                    'fade_ms'
                ]
                ?? 0,
                'text.fade_ms'
            );

        $fadeEndFrame =
            min(
                $endFrame - 1,
                $startFrame
                + $this->msToFrames(
                    $fadeMs,
                    $fps
                )
            );


        foreach (
            $pieces
            as $pieceIndex => $piece
        ) {
            $pieceStyle =
                $piece[
                    'style'
                ];


            $fontSize =
                $this->positiveNumber(
                    $pieceStyle[
                        'font_size_px'
                    ]
                    ?? 42,
                    'text piece font_size_px'
                );

            $fontWeight =
                $this->positiveNumber(
                    $pieceStyle[
                        'font_weight'
                    ]
                    ?? 400,
                    'text piece font_weight'
                );


            $animations = [];


            if (
                $fadeEndFrame
                > $startFrame
            ) {
                $animations[] =
                    $tools->opacityAnimation(
                        0,
                        1,
                        $startFrame,
                        $fadeEndFrame
                    );
            }


            $pieceBox =
                $pieceBoxes[
                    $pieceIndex
                ];


            $layers[] =
                $tools->textLayer(
                    id:
                        $sceneId
                        . '-'
                        . $piece[
                            'key'
                        ],

                    text:
                        $piece[
                            'text'
                        ],

                    startFrame:
                        $startFrame,

                    endFrame:
                        $endFrame,

                    box:
                        $tools->box(
                            $paddingX,
                            $pieceBox[
                                'top'
                            ],
                            $availableWidth,
                            $pieceBox[
                                'height'
                            ],
                            $zBase
                            + 5
                            + $pieceIndex
                        ),

                    style: [
                        'display' =>
                            'flex',

                        'alignItems' =>
                            'center',

                        'justifyContent' =>
                            'center',

                        'color' =>
                            (string)(
                                $text[
                                    'color'
                                ]
                                ?? '#ffffff'
                            ),

                        'fontFamily' =>
                            (string)(
                                $text[
                                    'font_family'
                                ]
                                ?? 'Arial, sans-serif'
                            ),

                        'fontSize' =>
                            $fontSize,

                        'fontWeight' =>
                            $fontWeight,

                        'lineHeight' =>
                            $lineHeight,

                        'textAlign' =>
                            (string)(
                                $text[
                                    'align'
                                ]
                                ?? 'center'
                            ),

                        'whiteSpace' =>
                            'pre-wrap',

                        'opacity' =>
                            0,
                    ],

                    animations:
                        $animations
                );
        }


        return $layers;
    }


    /**
     * Neutral hue-wheel scene -> deterministic Remotion support component.
     *
     * The Chef supplies product semantics in milliseconds. This translator
     * converts those timings to frame offsets and registers the renderer
     * component key understood by GenericVideo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function translateHueWheelScene(
        VideoCreatorTools $tools,
        array $scene,
        int $sceneIndex,
        string $sceneId,
        int $startFrame,
        int $endFrame,
        int $width,
        int $height,
        int $zBase,
        int $fps
    ): array {
        $wheel =
            $this->requireArray(
                $scene[
                    'wheel'
                ]
                ?? null,
                "blueprint.scenes[{$sceneIndex}].wheel"
            );


        $text =
            is_array(
                $scene[
                    'text'
                ]
                ?? null
            )
                ? $scene[
                    'text'
                ]
                : [];


        $spokes =
            is_array(
                $wheel[
                    'spokes'
                ]
                ?? null
            )
                ? array_values(
                    $wheel[
                        'spokes'
                    ]
                )
                : [];


        if ($spokes === []) {
            throw new RuntimeException(
                "blueprint.scenes[{$sceneIndex}].wheel.spokes requires at least one spoke."
            );
        }


        $translatedSpokes = [];


        foreach (
            $spokes
            as $spokeIndex => $spoke
        ) {
            $spoke =
                $this->requireArray(
                    $spoke,
                    "blueprint.scenes[{$sceneIndex}].wheel.spokes[{$spokeIndex}]"
                );


            $translatedSpokes[] = [
                'hue' =>
                    (float)$this->nonNegativeNumber(
                        $spoke[
                            'hue'
                        ]
                        ?? null,
                        "wheel.spokes[{$spokeIndex}].hue"
                    ),

                'color' =>
                    $this->requiredString(
                        $spoke[
                            'color'
                        ]
                        ?? '',
                        "wheel.spokes[{$spokeIndex}].color"
                    ),

                'animate' =>
                    (
                        $spoke[
                            'animate'
                        ]
                        ?? true
                    ) !== false,

                'start_radius' =>
                    (float)$this->nonNegativeNumber(
                        $spoke[
                            'start_radius'
                        ]
                        ?? 0,
                        "wheel.spokes[{$spokeIndex}].start_radius"
                    ),

                'end_radius' =>
                    (float)$this->nonNegativeNumber(
                        $spoke[
                            'end_radius'
                        ]
                        ?? 0,
                        "wheel.spokes[{$spokeIndex}].end_radius"
                    ),

                'start_frame_offset' =>
                    $this->msToFrames(
                        $this->nonNegativeInt(
                            $spoke[
                                'start_offset_ms'
                            ]
                            ?? 0,
                            "wheel.spokes[{$spokeIndex}].start_offset_ms"
                        ),
                        $fps
                    ),

                'duration_frames' =>
                    max(
                        0,
                        $this->msToFrames(
                            $this->nonNegativeInt(
                                $spoke[
                                    'duration_ms'
                                ]
                                ?? 0,
                                "wheel.spokes[{$spokeIndex}].duration_ms"
                            ),
                            $fps
                        )
                    ),
            ];
        }


        $entry =
            $this->entryOpacity(
                tools:
                    $tools,

                transition:
                    is_array(
                        $scene[
                            'transition_in'
                        ]
                        ?? null
                    )
                        ? $scene[
                            'transition_in'
                        ]
                        : null,

                startFrame:
                    $startFrame,

                endFrame:
                    $endFrame,

                fps:
                    $fps,

                targetOpacity:
                    1
            );


        $titleStyle =
            is_array(
                $text[
                    'title_style'
                ]
                ?? null
            )
                ? $text[
                    'title_style'
                ]
                : [];

        $subtitleStyle =
            is_array(
                $text[
                    'subtitle_style'
                ]
                ?? null
            )
                ? $text[
                    'subtitle_style'
                ]
                : [];


        return [
            $tools->componentLayer(
                id:
                    $sceneId
                    . '-hue-wheel',

                component:
                    self::HUE_WHEEL_COMPONENT,

                startFrame:
                    $startFrame,

                endFrame:
                    $endFrame,

                box:
                    $tools->box(
                        0,
                        0,
                        $width,
                        $height,
                        $zBase + 5
                    ),

                props: [
                    'sceneStartFrame' =>
                        $startFrame,

                    'wheelFadeFrames' =>
                        $this->msToFrames(
                            $this->nonNegativeInt(
                                $wheel[
                                    'fade_in_ms'
                                ]
                                ?? 0,
                                'wheel.fade_in_ms'
                            ),
                            $fps
                        ),

                    'size' =>
                        $this->positiveNumber(
                            $wheel[
                                'size_px'
                            ]
                            ?? null,
                            'wheel.size_px'
                        ),

                    'spokeWidth' =>
                        $this->positiveNumber(
                            $wheel[
                                'spoke_width_px'
                            ]
                            ?? null,
                            'wheel.spoke_width_px'
                        ),

                    'spokes' =>
                        $translatedSpokes,

                    'title' =>
                        trim(
                            (string)(
                                $scene[
                                    'title'
                                ]
                                ?? ''
                            )
                        ),

                    'subtitle' =>
                        trim(
                            (string)(
                                $scene[
                                    'subtitle'
                                ]
                                ?? ''
                            )
                        ),

                    'textColor' =>
                        (string)(
                            $text[
                                'color'
                            ]
                            ?? '#ffffff'
                        ),

                    'fontFamily' =>
                        (string)(
                            $text[
                                'font_family'
                            ]
                            ?? 'Helvetica, Arial, sans-serif'
                        ),

                    'textMaxWidth' =>
                        $this->positiveNumber(
                            $text[
                                'max_width_px'
                            ]
                            ?? 1400,
                            'text.max_width_px'
                        ),

                    'textGap' =>
                        $this->nonNegativeNumber(
                            $text[
                                'text_gap_px'
                            ]
                            ?? 0,
                            'text.text_gap_px'
                        ),

                    'contentGap' =>
                        $this->nonNegativeNumber(
                            $text[
                                'content_gap_px'
                            ]
                            ?? 0,
                            'text.content_gap_px'
                        ),

                    'titleFontSize' =>
                        $this->positiveNumber(
                            $titleStyle[
                                'font_size_px'
                            ]
                            ?? 64,
                            'text.title_style.font_size_px'
                        ),

                    'titleFontWeight' =>
                        $this->positiveNumber(
                            $titleStyle[
                                'font_weight'
                            ]
                            ?? 600,
                            'text.title_style.font_weight'
                        ),

                    'subtitleFontSize' =>
                        $this->positiveNumber(
                            $subtitleStyle[
                                'font_size_px'
                            ]
                            ?? 38,
                            'text.subtitle_style.font_size_px'
                        ),

                    'subtitleFontWeight' =>
                        $this->positiveNumber(
                            $subtitleStyle[
                                'font_weight'
                            ]
                            ?? 400,
                            'text.subtitle_style.font_weight'
                        ),
                ],

                style: [
                    'opacity' =>
                        $entry[
                            'base_opacity'
                        ],
                ],

                animations:
                    $entry[
                        'animations'
                    ]
            ),
        ];
    }


    /**
     * Standard ColorFix bumper -> current oven component registration.
     *
     * This is deliberately renderer translation. The Chef says only
     * "ColorFix brand bumper, centered, these timings." It never knows
     * React component names or props.signatureProgress.
     *
     * @return array<int, array<string, mixed>>
     */
    private function translateBrandBumperScene(
        VideoCreatorTools $tools,
        array $scene,
        int $sceneIndex,
        string $sceneId,
        int $startFrame,
        int $endFrame,
        int $width,
        int $height,
        int $zBase,
        int $fps
    ): array {
        $brand =
            $this->requireArray(
                $scene[
                    'brand'
                ]
                ?? null,
                "blueprint.scenes[{$sceneIndex}].brand"
            );


        $brandKey =
            strtolower(
                $this->requiredString(
                    $brand[
                        'key'
                    ]
                    ?? '',
                    "blueprint.scenes[{$sceneIndex}].brand.key"
                )
            );


        if ($brandKey !== 'colorfix') {
            throw new RuntimeException(
                "Video translator has no registered visual for brand '{$brandKey}'."
            );
        }


        $logoSize =
            $this->positiveNumber(
                $brand[
                    'logo_size_px'
                ]
                ?? null,
                'brand.logo_size_px'
            );

        $fadeInMs =
            $this->nonNegativeInt(
                $brand[
                    'fade_in_ms'
                ]
                ?? 0,
                'brand.fade_in_ms'
            );

        $fadeOutMs =
            $this->nonNegativeInt(
                $brand[
                    'fade_out_ms'
                ]
                ?? 0,
                'brand.fade_out_ms'
            );


        $signature =
            is_array(
                $brand[
                    'signature'
                ]
                ?? null
            )
                ? $brand[
                    'signature'
                ]
                : [];


        $signatureDelayMs =
            $this->nonNegativeInt(
                $signature[
                    'delay_ms'
                ]
                ?? 0,
                'brand.signature.delay_ms'
            );

        $signatureDurationMs =
            $this->nonNegativeInt(
                $signature[
                    'duration_ms'
                ]
                ?? 0,
                'brand.signature.duration_ms'
            );


        $animations = [];


        $fadeInEnd =
            min(
                $endFrame - 1,
                $startFrame
                + $this->msToFrames(
                    $fadeInMs,
                    $fps
                )
            );


        if ($fadeInEnd > $startFrame) {
            $animations[] =
                $tools->opacityAnimation(
                    0,
                    1,
                    $startFrame,
                    $fadeInEnd
                );
        }


        $fadeOutStart =
            max(
                $startFrame,
                $endFrame
                - $this->msToFrames(
                    $fadeOutMs,
                    $fps
                )
            );

        $fadeOutEnd =
            $endFrame - 1;


        if ($fadeOutEnd > $fadeOutStart) {
            $animations[] =
                $tools->opacityAnimation(
                    1,
                    0,
                    $fadeOutStart,
                    $fadeOutEnd
                );
        }


        $signatureStart =
            min(
                $endFrame - 1,
                $startFrame
                + $this->msToFrames(
                    $signatureDelayMs,
                    $fps
                )
            );

        $signatureEnd =
            min(
                $endFrame - 1,
                $signatureStart
                + $this->msToFrames(
                    $signatureDurationMs,
                    $fps
                )
            );


        if ($signatureEnd > $signatureStart) {
            $animations[] =
                $tools->numberAnimation(
                    target:
                        'props.signatureProgress',

                    from:
                        0,

                    to:
                        1,

                    startFrame:
                        $signatureStart,

                    endFrame:
                        $signatureEnd
                );
        }


        return [
            $tools->componentLayer(
                id:
                    $sceneId
                    . '-brand',

                component:
                    self::COLORFIX_BRAND_COMPONENT,

                startFrame:
                    $startFrame,

                endFrame:
                    $endFrame,

                /*
                 * Full-canvas wrapper lets the current HTML component be
                 * centered without teaching the Chef about React/CSS layout.
                 */
                box:
                    $tools->box(
                        0,
                        0,
                        $width,
                        $height,
                        $zBase + 5
                    ),

                props: [
                    'signatureProgress' =>
                        0,

                    'style' => [
                        '--brand-bumper-logo-size' =>
                            $logoSize
                            . 'px',
                    ],
                ],

                style: [
                    'display' =>
                        'flex',

                    'alignItems' =>
                        'center',

                    'justifyContent' =>
                        'center',

                    'opacity' =>
                        $fadeInMs > 0
                            ? 0
                            : 1,
                ],

                animations:
                    $animations
            ),
        ];
    }


    /**
     * Translate a neutral scene exit into a renderer-level black overlay.
     *
     * Used by the Chef for the special content -> black -> house bumper
     * handoff. The black overlay sits above the outgoing scene and reaches
     * full opacity before that scene ends.
     */
    private function translateSceneExitFade(
        VideoCreatorTools $tools,
        array $scene,
        int $sceneIndex,
        int $startFrame,
        int $endFrame,
        int $width,
        int $height,
        int $zBase,
        int $fps
    ): ?array {
        $transition =
            is_array(
                $scene[
                    'transition_out'
                ]
                ?? null
            )
                ? $scene[
                    'transition_out'
                ]
                : null;


        if ($transition === null) {
            return null;
        }


        $type =
            strtolower(
                trim(
                    (string)(
                        $transition[
                            'type'
                        ]
                        ?? ''
                    )
                )
            );


        if ($type !== 'fade-to-black') {
            throw new RuntimeException(
                "Video translator does not support neutral scene exit '{$type}'."
            );
        }


        $durationMs =
            $this->nonNegativeInt(
                $transition[
                    'duration_ms'
                ]
                ?? 0,
                "blueprint.scenes[{$sceneIndex}].transition_out.duration_ms"
            );


        if ($durationMs <= 0) {
            return null;
        }


        $fadeFrames =
            max(
                1,
                $this->msToFrames(
                    $durationMs,
                    $fps
                )
            );

        $fadeStart =
            max(
                $startFrame,
                $endFrame
                - $fadeFrames
            );

        $fadeEnd =
            $endFrame - 1;


        if ($fadeEnd <= $fadeStart) {
            return null;
        }


        $color =
            trim(
                (string)(
                    $transition[
                        'to_color'
                    ]
                    ?? '#000000'
                )
            );


        if ($color === '') {
            $color =
                '#000000';
        }


        return $tools->rectLayer(
            id:
                'scene-'
                . (
                    $sceneIndex
                    + 1
                )
                . '-exit-fade',

            startFrame:
                $fadeStart,

            endFrame:
                $endFrame,

            box:
                $tools->box(
                    0,
                    0,
                    $width,
                    $height,
                    $zBase + 19
                ),

            style: [
                'backgroundColor' =>
                    $color,

                'opacity' =>
                    0,
            ],

            animations: [
                $tools->opacityAnimation(
                    0,
                    1,
                    $fadeStart,
                    $fadeEnd
                ),
            ]
        );
    }


    /**
     * Translate a neutral dissolve into the current oven's opacity target.
     *
     * @return array{base_opacity: float|int, animations: array<int, array<string, mixed>>}
     */
    private function entryOpacity(
        VideoCreatorTools $tools,
        ?array $transition,
        int $startFrame,
        int $endFrame,
        int $fps,
        float|int $targetOpacity
    ): array {
        if ($transition === null) {
            return [
                'base_opacity' =>
                    $targetOpacity,

                'animations' =>
                    [],
            ];
        }


        $type =
            strtolower(
                trim(
                    (string)(
                        $transition[
                            'type'
                        ]
                        ?? ''
                    )
                )
            );


        if ($type === '') {
            return [
                'base_opacity' =>
                    $targetOpacity,

                'animations' =>
                    [],
            ];
        }


        if ($type !== 'dissolve') {
            throw new RuntimeException(
                "Video translator does not support neutral transition '{$type}'."
            );
        }


        $durationMs =
            $this->nonNegativeInt(
                $transition[
                    'duration_ms'
                ]
                ?? 0,
                'transition.duration_ms'
            );


        $fadeEnd =
            min(
                $endFrame - 1,
                $startFrame
                + $this->msToFrames(
                    $durationMs,
                    $fps
                )
            );


        if ($fadeEnd <= $startFrame) {
            return [
                'base_opacity' =>
                    $targetOpacity,

                'animations' =>
                    [],
            ];
        }


        return [
            'base_opacity' =>
                0,

            'animations' => [
                $tools->opacityAnimation(
                    0,
                    $targetOpacity,
                    $startFrame,
                    $fadeEnd
                ),
            ],
        ];
    }


    private function appendFinalFade(
        VideoCreatorTools $tools,
        array &$layers,
        array $finalFade,
        int $width,
        int $height,
        int $fps,
        int $totalFrames,
        string $defaultColor
    ): void {
        if ($finalFade === []) {
            return;
        }


        $durationMs =
            $this->nonNegativeInt(
                $finalFade[
                    'duration_ms'
                ]
                ?? 0,
                'blueprint.final_fade.duration_ms'
            );


        if ($durationMs <= 0) {
            return;
        }


        $fadeFrames =
            max(
                1,
                $this->msToFrames(
                    $durationMs,
                    $fps
                )
            );

        $startFrame =
            max(
                0,
                $totalFrames
                - $fadeFrames
            );

        $endAnimationFrame =
            $totalFrames - 1;


        if ($endAnimationFrame <= $startFrame) {
            return;
        }


        $layers[] =
            $tools->rectLayer(
                id:
                    'video-final-fade',

                startFrame:
                    $startFrame,

                endFrame:
                    $totalFrames,

                box:
                    $tools->box(
                        0,
                        0,
                        $width,
                        $height,
                        100000
                    ),

                style: [
                    'backgroundColor' =>
                        trim(
                            (string)(
                                $finalFade[
                                    'to_color'
                                ]
                                ?? $defaultColor
                            )
                        )
                            ?: $defaultColor,

                    'opacity' =>
                        0,
                ],

                animations: [
                    $tools->opacityAnimation(
                        0,
                        1,
                        $startFrame,
                        $endAnimationFrame
                    ),
                ]
            );
    }


    /**
     * Audio is not used by the current YouTube blueprint yet, but this
     * keeps the translator boundary ready without teaching the Chef the
     * renderer's frame vocabulary.
     *
     * Neutral audio entries may contain:
     *   src, start_ms, duration_ms, volume
     *
     * @return array<int, array<string, mixed>>
     */
    private function translateAudio(
        array $blueprintAudio,
        int $fps,
        int $totalFrames
    ): array {
        $translated = [];


        foreach (
            array_values(
                $blueprintAudio
            )
            as $index => $audio
        ) {
            if (!is_array($audio)) {
                throw new RuntimeException(
                    "Video blueprint audio {$index} must be an array."
                );
            }


            $src =
                $this->requiredString(
                    $audio[
                        'src'
                    ]
                    ?? '',
                    "blueprint.audio[{$index}].src"
                );

            $startMs =
                $this->nonNegativeInt(
                    $audio[
                        'start_ms'
                    ]
                    ?? 0,
                    "blueprint.audio[{$index}].start_ms"
                );

            $durationMs =
                isset(
                    $audio[
                        'duration_ms'
                    ]
                )
                    ? $this->positiveInt(
                        $audio[
                            'duration_ms'
                        ],
                        "blueprint.audio[{$index}].duration_ms"
                    )
                    : null;


            $startFrame =
                min(
                    $totalFrames - 1,
                    $this->msToFrames(
                        $startMs,
                        $fps
                    )
                );

            $endFrame =
                $durationMs === null
                    ? $totalFrames
                    : min(
                        $totalFrames,
                        max(
                            $startFrame + 1,
                            $this->msToFrames(
                                $startMs
                                + $durationMs,
                                $fps
                            )
                        )
                    );


            $translated[] = [
                'src' =>
                    $src,

                'start_frame' =>
                    $startFrame,

                'end_frame' =>
                    $endFrame,

                'volume' =>
                    $this->unitNumber(
                        $audio[
                            'volume'
                        ]
                        ?? 1,
                        "blueprint.audio[{$index}].volume"
                    ),
            ];
        }


        return $translated;
    }


    private function captionText(
        array $caption
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
                        $caption[
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


    private function estimatedCaptionHeight(
        string $text,
        float $fontSize,
        float $lineHeight,
        int $maxWidth,
        int $paddingX,
        int $paddingY
    ): int {
        $usableWidth =
            max(
                1,
                $maxWidth
                - (
                    $paddingX
                    * 2
                )
            );


        /*
         * Approximate average glyph width at ~0.56em. The browser still
         * performs the actual line wrapping; this only reserves a sensible
         * absolute box for the generic primitive.
         */
        $charsPerLine =
            max(
                8,
                (int)floor(
                    $usableWidth
                    / max(
                        1,
                        $fontSize
                        * 0.56
                    )
                )
            );


        $logicalLines = 0;


        foreach (
            preg_split(
                '/\R/u',
                $text
            )
                ?: [
                    $text,
                ]
            as $line
        ) {
            $length =
                max(
                    1,
                    $this->textLength(
                        $line
                    )
                );

            $logicalLines +=
                max(
                    1,
                    (int)ceil(
                        $length
                        / $charsPerLine
                    )
                );
        }


        return max(
            1,
            (int)ceil(
                (
                    $logicalLines
                    * $fontSize
                    * $lineHeight
                )
                + (
                    $paddingY
                    * 2
                )
            )
        );
    }


    /**
     * Allocate neutral title/subtitle/body pieces as a centered vertical
     * stack while retaining independent font sizes.
     *
     * @param array<int, array<string, mixed>> $pieces
     * @return array<int, array{top: int, height: int}>
     */
    private function centeredTextPieceBoxes(
        array $pieces,
        int $availableWidth,
        int $availableHeight,
        int $top,
        float $lineHeight
    ): array {
        $heights = [];
        $gap = 22;


        foreach (
            $pieces
            as $piece
        ) {
            $style =
                is_array(
                    $piece[
                        'style'
                    ]
                    ?? null
                )
                    ? $piece[
                        'style'
                    ]
                    : [];


            $fontSize =
                $this->positiveNumber(
                    $style[
                        'font_size_px'
                    ]
                    ?? 42,
                    'text piece font_size_px'
                );

            $text =
                (string)(
                    $piece[
                        'text'
                    ]
                    ?? ''
                );


            $charsPerLine =
                max(
                    10,
                    (int)floor(
                        $availableWidth
                        / max(
                            1,
                            $fontSize
                            * 0.56
                        )
                    )
                );

            $lineCount =
                max(
                    1,
                    (int)ceil(
                        max(
                            1,
                            $this->textLength(
                                $text
                            )
                        )
                        / $charsPerLine
                    )
                );


            $heights[] =
                max(
                    1,
                    (int)ceil(
                        $lineCount
                        * $fontSize
                        * $lineHeight
                        * 1.10
                    )
                );
        }


        $totalHeight =
            array_sum(
                $heights
            )
            + max(
                0,
                count(
                    $heights
                )
                - 1
            )
            * $gap;


        if ($totalHeight > $availableHeight) {
            /*
             * Do not invent font scaling in the translator. Let the boxes
             * consume the available vertical room; typography remains the
             * Chef/Recipe decision.
             */
            $gap = 8;

            $totalHeight =
                array_sum(
                    $heights
                )
                + max(
                    0,
                    count(
                        $heights
                    )
                    - 1
                )
                * $gap;
        }


        $cursor =
            $top
            + max(
                0,
                (int)floor(
                    (
                        $availableHeight
                        - $totalHeight
                    )
                    / 2
                )
            );


        $boxes = [];


        foreach (
            $heights
            as $height
        ) {
            $boxes[] = [
                'top' =>
                    $cursor,

                'height' =>
                    min(
                        $height,
                        max(
                            1,
                            $top
                            + $availableHeight
                            - $cursor
                        )
                    ),
            ];


            $cursor +=
                $height
                + $gap;
        }


        return $boxes;
    }


    private function textLength(
        string $value
    ): int {
        if (function_exists('mb_strlen')) {
            return \mb_strlen(
                $value
            );
        }


        return strlen(
            $value
        );
    }


    /**
     * The ONE renderer timing conversion point.
     */
    private function msToFrames(
        int $milliseconds,
        int $fps
    ): int {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException(
                'milliseconds must be zero or greater.'
            );
        }


        if ($fps <= 0) {
            throw new InvalidArgumentException(
                'fps must be greater than zero.'
            );
        }


        return (int)round(
            (
                $milliseconds
                / 1000
            )
            * $fps
        );
    }


    private function requireArray(
        mixed $value,
        string $name
    ): array {
        if (!is_array($value)) {
            throw new InvalidArgumentException(
                "{$name} must be an array."
            );
        }


        return $value;
    }


    private function requiredString(
        mixed $value,
        string $name
    ): string {
        $value =
            trim(
                (string)$value
            );


        if ($value === '') {
            throw new InvalidArgumentException(
                "{$name} is required."
            );
        }


        return $value;
    }


    private function positiveInt(
        mixed $value,
        string $name
    ): int {
        if (
            !is_numeric(
                $value
            )
        ) {
            throw new InvalidArgumentException(
                "{$name} must be numeric."
            );
        }


        $value =
            (int)$value;


        if ($value <= 0) {
            throw new InvalidArgumentException(
                "{$name} must be greater than zero."
            );
        }


        return $value;
    }


    private function nonNegativeInt(
        mixed $value,
        string $name
    ): int {
        if (
            !is_numeric(
                $value
            )
        ) {
            throw new InvalidArgumentException(
                "{$name} must be numeric."
            );
        }


        $value =
            (int)$value;


        if ($value < 0) {
            throw new InvalidArgumentException(
                "{$name} must be zero or greater."
            );
        }


        return $value;
    }


    private function nonNegativeNumber(
        mixed $value,
        string $name
    ): float {
        if (
            !is_numeric(
                $value
            )
        ) {
            throw new InvalidArgumentException(
                "{$name} must be numeric."
            );
        }


        $value =
            (float)$value;


        if ($value < 0) {
            throw new InvalidArgumentException(
                "{$name} must be zero or greater."
            );
        }


        return $value;
    }


    private function positiveNumber(
        mixed $value,
        string $name
    ): float {
        if (
            !is_numeric(
                $value
            )
        ) {
            throw new InvalidArgumentException(
                "{$name} must be numeric."
            );
        }


        $value =
            (float)$value;


        if ($value <= 0) {
            throw new InvalidArgumentException(
                "{$name} must be greater than zero."
            );
        }


        return $value;
    }


    private function unitNumber(
        mixed $value,
        string $name
    ): float {
        if (
            !is_numeric(
                $value
            )
        ) {
            throw new InvalidArgumentException(
                "{$name} must be numeric."
            );
        }


        $value =
            (float)$value;


        if (
            $value < 0
            || $value > 1
        ) {
            throw new InvalidArgumentException(
                "{$name} must be between zero and one."
            );
        }


        return $value;
    }
}
