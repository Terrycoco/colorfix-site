<?php
declare(strict_types=1);

namespace App\PUB\Create\Video\Support;

/**
 * Shared tools for compiling Creator-owned video recipes into
 * the generic render-plan language consumed by the Remotion oven.
 *
 * This class makes NO product decisions.
 *
 * It does not know:
 * - Pinterest
 * - YouTube
 * - before/after videos
 * - titles
 * - captions
 * - end slides
 * - default timing
 * - default styling
 *
 * Every value must be supplied by the calling Creator/Recipe.
 */
final class VideoCreatorTools
{
    /**
     * Build the top-level generic render plan.
     *
     * @param array<int, array<string, mixed>> $layers
     * @param array<int, array<string, mixed>> $audio
     */
    public function videoPlan(
        int $width,
        int $height,
        int $fps,
        int $durationInFrames,
        string $codec,
        array $layers,
        array $audio = []
    ): array {
        $this->requirePositiveInt(
            $width,
            'width'
        );

        $this->requirePositiveInt(
            $height,
            'height'
        );

        $this->requirePositiveInt(
            $fps,
            'fps'
        );

        $this->requirePositiveInt(
            $durationInFrames,
            'durationInFrames'
        );

        $codec =
            $this->requireString(
                $codec,
                'codec'
            );

        return [
            'video' => [
                'width' =>
                    $width,

                'height' =>
                    $height,

                'fps' =>
                    $fps,

                'duration_in_frames' =>
                    $durationInFrames,
            ],

            'render' => [
                'codec' =>
                    $codec,
            ],

            'layers' =>
                array_values(
                    $layers
                ),

            'audio' =>
                array_values(
                    $audio
                ),
        ];
    }


    /**
     * Generic rectangle/background layer.
     */
    public function rectLayer(
        string $id,
        int $startFrame,
        int $endFrame,
        array $box,
        array $style = [],
        array $animations = []
    ): array {
        return $this->baseLayer(
            type:
                'rect',

            id:
                $id,

            startFrame:
                $startFrame,

            endFrame:
                $endFrame,

            box:
                $box,

            style:
                $style,

            animations:
                $animations
        );
    }


    /**
     * Generic image layer.
     */
    public function imageLayer(
        string $id,
        string $src,
        string $fit,
        int $startFrame,
        int $endFrame,
        array $box,
        array $style = [],
        array $animations = []
    ): array {
        $src =
            $this->requireString(
                $src,
                'src'
            );

        $fit =
            $this->requireString(
                $fit,
                'fit'
            );

        return [
            ...$this->baseLayer(
                type:
                    'image',

                id:
                    $id,

                startFrame:
                    $startFrame,

                endFrame:
                    $endFrame,

                box:
                    $box,

                style:
                    $style,

                animations:
                    $animations
            ),

            'src' =>
                $src,

            'fit' =>
                $fit,
        ];
    }


    /**
     * Generic text layer.
     */
    public function textLayer(
        string $id,
        string $text,
        int $startFrame,
        int $endFrame,
        array $box,
        array $style = [],
        array $animations = []
    ): array {
        $id =
            $this->requireString(
                $id,
                'id'
            );

        return [
            ...$this->baseLayer(
                type:
                    'text',

                id:
                    $id,

                startFrame:
                    $startFrame,

                endFrame:
                    $endFrame,

                box:
                    $box,

                style:
                    $style,

                animations:
                    $animations
            ),

            'text' =>
                $text,
        ];
    }


    /**
     * Generic registered-component layer.
     *
     * Used only when a reusable visual primitive cannot be represented
     * by rect/image/text alone.
     */
    public function componentLayer(
        string $id,
        string $component,
        int $startFrame,
        int $endFrame,
        array $box,
        array $props = [],
        array $style = [],
        array $animations = []
    ): array {
        $component =
            $this->requireString(
                $component,
                'component'
            );

        return [
            ...$this->baseLayer(
                type:
                    'component',

                id:
                    $id,

                startFrame:
                    $startFrame,

                endFrame:
                    $endFrame,

                box:
                    $box,

                style:
                    $style,

                animations:
                    $animations
            ),

            'component' =>
                $component,

            'props' =>
                $props,
        ];
    }


    /**
     * Convenience wrapper for a style.opacity animation.
     */
    public function opacityAnimation(
        float|int $from,
        float|int $to,
        int $startFrame,
        int $endFrame
    ): array {
        return $this->numberAnimation(
            target:
                'style.opacity',

            from:
                $from,

            to:
                $to,

            startFrame:
                $startFrame,

            endFrame:
                $endFrame
        );
    }


    /**
     * Generic numeric animation.
     *
     * Target must identify exactly what the oven should animate,
     * for example:
     *
     *   style.opacity
     *   props.signatureProgress
     */
    public function numberAnimation(
        string $target,
        float|int $from,
        float|int $to,
        int $startFrame,
        int $endFrame
    ): array {
        $target =
            $this->requireString(
                $target,
                'target'
            );

        $this->requireFrameRange(
            $startFrame,
            $endFrame
        );

        return [
            'target' =>
                $target,

            'from' =>
                $from,

            'to' =>
                $to,

            'start_frame' =>
                $startFrame,

            'end_frame' =>
                $endFrame,
        ];
    }


    /**
     * Convert seconds to an exact frame number using the supplied FPS.
     */
    public function secondsToFrames(
        float $seconds,
        int $fps
    ): int {
        if ($seconds < 0) {
            throw new \InvalidArgumentException(
                'seconds must be zero or greater.'
            );
        }

        $this->requirePositiveInt(
            $fps,
            'fps'
        );

        return
            (int)round(
                $seconds
                *
                $fps
            );
    }


    /**
     * Standard generic absolute-position box.
     */
    public function box(
        int|float $x,
        int|float $y,
        int|float $width,
        int|float $height,
        int $z = 0
    ): array {
        if ($width < 0) {
            throw new \InvalidArgumentException(
                'box width must be zero or greater.'
            );
        }

        if ($height < 0) {
            throw new \InvalidArgumentException(
                'box height must be zero or greater.'
            );
        }

        return [
            'x' =>
                $x,

            'y' =>
                $y,

            'width' =>
                $width,

            'height' =>
                $height,

            'z' =>
                $z,
        ];
    }


    private function baseLayer(
        string $type,
        string $id,
        int $startFrame,
        int $endFrame,
        array $box,
        array $style,
        array $animations
    ): array {
        $type =
            $this->requireString(
                $type,
                'type'
            );

        $id =
            $this->requireString(
                $id,
                'id'
            );

        $this->requireFrameRange(
            $startFrame,
            $endFrame
        );

        return [
            'id' =>
                $id,

            'type' =>
                $type,

            'start_frame' =>
                $startFrame,

            'end_frame' =>
                $endFrame,

            'box' =>
                $box,

            'style' =>
                $style,

            'animations' =>
                array_values(
                    $animations
                ),
        ];
    }


    private function requireFrameRange(
        int $startFrame,
        int $endFrame
    ): void {
        if ($startFrame < 0) {
            throw new \InvalidArgumentException(
                'startFrame must be zero or greater.'
            );
        }

        if ($endFrame <= $startFrame) {
            throw new \InvalidArgumentException(
                'endFrame must be greater than startFrame.'
            );
        }
    }


    private function requirePositiveInt(
        int $value,
        string $name
    ): void {
        if ($value <= 0) {
            throw new \InvalidArgumentException(
                "{$name} must be greater than zero."
            );
        }
    }


    private function requireString(
        string $value,
        string $name
    ): string {
        $value =
            trim(
                $value
            );

        if ($value === '') {
            throw new \InvalidArgumentException(
                "{$name} is required."
            );
        }

        return $value;
    }
}