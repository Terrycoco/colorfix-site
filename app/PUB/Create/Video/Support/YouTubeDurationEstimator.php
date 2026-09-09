<?php
declare(strict_types=1);

namespace App\PUB\Create\Video\Support;

use App\PUB\Create\YouTube\PlaylistVideoRecipe;
use RuntimeException;

/**
 * YOUTUBE VIDEO DURATION ESTIMATOR
 *
 * Shared timing authority for the YouTube Playlist Video product.
 *
 * Receives the exact prepared playable slides that ANALYZE will hand to
 * CREATE and calculates the timeline CREATE will use:
 *
 *   - per-slide duration
 *   - photo overlap
 *   - through-black scene boundaries
 *   - dynamic hue-wheel duration
 *   - final frame-rounded video duration
 *
 * It does not:
 *   - inspect raw playlist source rows
 *   - prepare ingredients
 *   - build visual layers
 *   - render video
 *   - know PUB lifecycle
 *
 * All product timing values come from PlaylistVideoRecipe.
 */
final class YouTubeDurationEstimator
{
    private const SUPPORTED_ITEM_TYPES = [
        'intro',
        'text',
        'palette',
        'non-palette',
        'normal',
        'hue-wheel',
        'brand-bumper',
    ];

    /**
     * @return array{
     *   blueprint_duration_ms:int,
     *   duration_frames:int,
     *   duration_ms:int,
     *   slide_count:int,
     *   timeline:array<int, array{
     *     index:int,
     *     item_type:string,
     *     start_ms:int,
     *     duration_ms:int
     *   }>
     * }
     */
    public function estimate(array $slides): array
    {
        if ($slides === []) {
            throw new RuntimeException(
                'YouTube duration estimate requires at least one playable slide.'
            );
        }

        $slides = array_values($slides);
        $timeline = [];
        $cursorMs = 0;

        foreach ($slides as $index => $slide) {
            if (!is_array($slide)) {
                throw new RuntimeException(
                    'YouTube duration estimate received an invalid slide at index '
                    . $index
                    . '.'
                );
            }

            $itemType = strtolower(
                trim(
                    (string)(
                        $slide['item_type']
                        ?? ''
                    )
                )
            );

            if ($itemType === '') {
                throw new RuntimeException(
                    'YouTube duration estimate received a slide with no item_type at index '
                    . $index
                    . '.'
                );
            }

            if (!in_array($itemType, self::SUPPORTED_ITEM_TYPES, true)) {
                throw new RuntimeException(
                    "YouTube duration estimate does not support item_type '{$itemType}'."
                );
            }

            $durationMs = $this->durationMsForSlide(
                $slide,
                $itemType
            );

            $previousItemType =
                $index > 0
                    ? strtolower(
                        trim(
                            (string)(
                                $slides[$index - 1]['item_type']
                                ?? ''
                            )
                        )
                    )
                    : '';

            $waitForPreviousExit =
                $index > 0
                && (
                    $this->needsCleanEntry($itemType)
                    || $this->ownsCleanExit($previousItemType)
                );

            $blackHoldMs = $this->blackHoldMs(
                currentItemType: $itemType,
                previousItemType: $previousItemType
            );

            $startMs =
                $index === 0
                    ? 0
                    : (
                        $waitForPreviousExit
                            ? $cursorMs + $blackHoldMs
                            : max(
                                0,
                                $cursorMs
                                - PlaylistVideoRecipe::DISSOLVE_MS
                            )
                    );

            $timeline[] = [
                'index' => $index,
                'item_type' => $itemType,
                'start_ms' => $startMs,
                'duration_ms' => $durationMs,
            ];

            $cursorMs = $startMs + $durationMs;
        }

        if ($cursorMs <= 0) {
            throw new RuntimeException(
                'YouTube duration estimate calculated an invalid video duration.'
            );
        }

        $durationFrames = max(
            1,
            $this->msToFrames(
                $cursorMs,
                PlaylistVideoRecipe::FPS
            )
        );

        /*
         * CREATE ultimately persists duration from the saved render-plan
         * frame count, not directly from blueprint milliseconds.
         */
        $durationMs = (int)round(
            (
                $durationFrames
                / PlaylistVideoRecipe::FPS
            )
            * 1000
        );

        return [
            'blueprint_duration_ms' => $cursorMs,
            'duration_frames' => $durationFrames,
            'duration_ms' => $durationMs,
            'slide_count' => count($timeline),
            'timeline' => $timeline,
        ];
    }

    private function durationMsForSlide(
        array $slide,
        string $itemType
    ): int {
        return match ($itemType) {
            'intro' =>
                PlaylistVideoRecipe::INTRO_DURATION_MS,

            'text' =>
                PlaylistVideoRecipe::TEXT_DURATION_MS,

            'palette' =>
                $this->photoDurationMs(
                    $slide,
                    PlaylistVideoRecipe::PALETTE_PHOTO_DURATION_MS
                ),

            'non-palette' =>
                $this->photoDurationMs(
                    $slide,
                    PlaylistVideoRecipe::NON_PALETTE_PHOTO_DURATION_MS
                ),

            'normal' =>
                $this->hasPhoto($slide)
                    ? $this->photoDurationMs(
                        $slide,
                        PlaylistVideoRecipe::NORMAL_PHOTO_DURATION_MS
                    )
                    : PlaylistVideoRecipe::TEXT_DURATION_MS,

            'hue-wheel' =>
                $this->hueWheelDurationMs($slide),

            'brand-bumper' =>
                PlaylistVideoRecipe::BRAND_BUMPER_DURATION_MS,

            default =>
                throw new RuntimeException(
                    "YouTube duration estimate has no duration for item_type '{$itemType}'."
                ),
        };
    }

    private function photoDurationMs(
        array $slide,
        int $baseDurationMs
    ): int {
        if (!$this->hasTextContent($slide)) {
            return $baseDurationMs;
        }

        $minimumForReadableCaption =
            PlaylistVideoRecipe::CAPTION_DELAY_MS
            + PlaylistVideoRecipe::CAPTION_FADE_MS
            + PlaylistVideoRecipe::CAPTION_HOLD_MS
            + PlaylistVideoRecipe::CAPTION_FADE_OUT_MS
            + PlaylistVideoRecipe::CAPTION_FADE_OUT_END_BEFORE_SCENE_END_MS;

        return max(
            $baseDurationMs,
            $minimumForReadableCaption
        );
    }

    private function hueWheelDurationMs(array $slide): int
    {
        $spokes =
            is_array(
                $slide['hue_wheel']['spokes']
                ?? null
            )
                ? array_values(
                    $slide['hue_wheel']['spokes']
                )
                : [];

        $lastAnimationEndMs = 0;

        foreach ($spokes as $index => $spoke) {
            if (!is_array($spoke)) {
                continue;
            }

            $animate =
                (
                    $spoke['animate']
                    ?? true
                ) !== false;

            if (!$animate) {
                continue;
            }

            $baseDelayMs =
                array_key_exists('delay_ms', $spoke)
                    ? max(
                        0,
                        (int)round(
                            (float)$spoke['delay_ms']
                        )
                    )
                    : PlaylistVideoRecipe::HUE_WHEEL_SPOKE_DELAY_MS;

            $durationMs =
                array_key_exists('duration_ms', $spoke)
                    ? max(
                        1,
                        (int)round(
                            (float)$spoke['duration_ms']
                        )
                    )
                    : PlaylistVideoRecipe::HUE_WHEEL_SPOKE_DURATION_MS;

            $lastAnimationEndMs = max(
                $lastAnimationEndMs,
                PlaylistVideoRecipe::HUE_WHEEL_FADE_IN_MS
                + $baseDelayMs
                + (
                    $index
                    * PlaylistVideoRecipe::HUE_WHEEL_SPOKE_STAGGER_MS
                )
                + $durationMs
            );
        }

        return max(
            PlaylistVideoRecipe::HUE_WHEEL_MIN_DURATION_MS,
            $lastAnimationEndMs
            + PlaylistVideoRecipe::HUE_WHEEL_HOLD_AFTER_SPOKES_MS
        );
    }

    private function needsCleanEntry(string $itemType): bool
    {
        return in_array(
            $itemType,
            [
                'intro',
                'text',
                'hue-wheel',
                'brand-bumper',
            ],
            true
        );
    }

    private function ownsCleanExit(string $itemType): bool
    {
        return in_array(
            $itemType,
            [
                'intro',
                'text',
            ],
            true
        );
    }

    private function blackHoldMs(
        string $currentItemType,
        string $previousItemType
    ): int {
        return match ($currentItemType) {
            'brand-bumper' =>
                PlaylistVideoRecipe::BRAND_BUMPER_BLACK_HOLD_MS,

            'hue-wheel' =>
                PlaylistVideoRecipe::HUE_WHEEL_BLACK_HOLD_MS,

            'intro' =>
                PlaylistVideoRecipe::INTRO_BLACK_HOLD_MS,

            'text' =>
                PlaylistVideoRecipe::TEXT_BLACK_HOLD_MS,

            default =>
                match ($previousItemType) {
                    'intro' =>
                        PlaylistVideoRecipe::INTRO_BLACK_HOLD_MS,

                    'text' =>
                        PlaylistVideoRecipe::TEXT_BLACK_HOLD_MS,

                    default =>
                        0,
                },
        };
    }

    private function hasPhoto(array $slide): bool
    {
        return
            is_array(
                $slide['photo']
                ?? null
            )
            && trim(
                (string)(
                    $slide['photo']['image_url']
                    ?? ''
                )
            ) !== '';
    }

    private function hasTextContent(array $slide): bool
    {
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
                        $slide[$field]
                        ?? ''
                    )
                ) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    private function msToFrames(
        int $milliseconds,
        int $fps
    ): int {
        if ($milliseconds < 0) {
            throw new RuntimeException(
                'YouTube duration estimate milliseconds must be zero or greater.'
            );
        }

        if ($fps <= 0) {
            throw new RuntimeException(
                'YouTube duration estimate fps must be greater than zero.'
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
}
