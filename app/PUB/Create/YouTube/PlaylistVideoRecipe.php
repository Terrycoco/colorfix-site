<?php
declare(strict_types=1);

namespace App\PUB\Create\YouTube;

/**
 * YOUTUBE PLAYLIST VIDEO RECIPE
 *
 * Passive product settings for the YouTube Playlist Video Creator.
 *
 * This class contains tweakable product values only. It does NOT:
 *   - inspect ingredients
 *   - build scenes
 *   - build layers
 *   - translate to Remotion
 *   - queue jobs
 *   - know PUB lifecycle
 *
 * All timing values are expressed in milliseconds.
 */
final class PlaylistVideoRecipe
{
    /* ================================================================
     * OUTPUT
     * ================================================================ */

    public const OUTPUT_MIME_TYPE = 'video/mp4';
    public const CODEC = 'h264';


    /* ================================================================
     * VIDEO
     * ================================================================ */

    public const WIDTH = 1920;
    public const HEIGHT = 1080;
    public const FPS = 30;


    /* ================================================================
     * TIMING — SCENES
     * ================================================================ */

    public const INTRO_DURATION_MS = 3600;
    public const TEXT_DURATION_MS = 7600;

    public const PALETTE_PHOTO_DURATION_MS = 8500;
    public const NON_PALETTE_PHOTO_DURATION_MS = 8500;
    public const NORMAL_PHOTO_DURATION_MS = 8500;

    public const HUE_WHEEL_MIN_DURATION_MS = 6200;
    public const BRAND_BUMPER_DURATION_MS = 5200;


    /* ================================================================
     * TIMING — PHOTO TO PHOTO
     *
     * Timeline for a normal captioned photo:
     *
     *   picture dissolves in
     *   -> short visual breath
     *   -> caption fades in
     *   -> caption stays readable
     *   -> caption fades out
     *   -> CLEAN PHOTO HOLD (no caption)
     *   -> next picture begins dissolving in
     *
     * PHOTO_CLEAN_HOLD_AFTER_CAPTION_MS is the knob Terry wanted:
     * how long the viewer gets to look again after reading the caption.
     * ================================================================ */

    public const PHOTO_DISSOLVE_MS = 2000;

    public const PHOTO_CAPTION_DELAY_AFTER_DISSOLVE_MS = 250;
    public const PHOTO_CAPTION_FADE_IN_MS = 2000;
    public const PHOTO_CAPTION_READ_HOLD_MS = 3500;
    public const PHOTO_CAPTION_FADE_OUT_MS = 800;

    public const PHOTO_CLEAN_HOLD_AFTER_CAPTION_MS = 1500;

    /**
     * Caption starts only after the incoming photo has completed its dissolve,
     * plus the small visual-breath delay.
     */
    public const PHOTO_CAPTION_START_MS =
        self::PHOTO_DISSOLVE_MS
        + self::PHOTO_CAPTION_DELAY_AFTER_DISSOLVE_MS;

    /**
     * The caption must be completely gone this long before the current
     * photo scene ends. That window contains:
     *   1) clean-photo hold
     *   2) outgoing photo dissolve into the next scene
     */
    public const PHOTO_CAPTION_GONE_BEFORE_SCENE_END_MS =
        self::PHOTO_CLEAN_HOLD_AFTER_CAPTION_MS
        + self::PHOTO_DISSOLVE_MS;


    /* ================================================================
     * LEGACY PHOTO-TIMING ALIASES
     *
     * Keep these so the existing Chef continues to work while the clearer
     * names above become the canonical Recipe vocabulary.
     * ================================================================ */

    public const DISSOLVE_MS = self::PHOTO_DISSOLVE_MS;

    public const CAPTION_AFTER_DISSOLVE_DELAY_MS =
        self::PHOTO_CAPTION_DELAY_AFTER_DISSOLVE_MS;

    public const CAPTION_DELAY_MS =
        self::PHOTO_CAPTION_START_MS;

    public const CAPTION_FADE_MS =
        self::PHOTO_CAPTION_FADE_IN_MS;

    public const CAPTION_HOLD_MS =
        self::PHOTO_CAPTION_READ_HOLD_MS;

    public const CAPTION_FADE_OUT_MS =
        self::PHOTO_CAPTION_FADE_OUT_MS;

    public const CAPTION_POST_FADE_HOLD_MS =
        self::PHOTO_CLEAN_HOLD_AFTER_CAPTION_MS;

    public const CAPTION_FADE_OUT_END_BEFORE_SCENE_END_MS =
        self::PHOTO_CAPTION_GONE_BEFORE_SCENE_END_MS;


    /* ================================================================
     * TIMING — INTRO
     * ================================================================ */

    public const INTRO_PRE_FADE_TO_BLACK_MS = 900;
    public const INTRO_BLACK_HOLD_MS = 0;
    public const INTRO_FADE_IN_MS = 900;
    public const INTRO_FADE_OUT_TO_BLACK_MS = 900;

    public const INTRO_TEXT_FADE_MS =
        self::INTRO_FADE_IN_MS;


    /* ================================================================
     * TIMING — TEXT SLIDES
     * ================================================================ */

    public const TEXT_PRE_FADE_TO_BLACK_MS = 1200;
    public const TEXT_BLACK_HOLD_MS = 0;
    public const TEXT_FADE_IN_MS = 2000;
    public const TEXT_FADE_OUT_TO_BLACK_MS = 1200;

    public const TEXT_FADE_MS =
        self::TEXT_FADE_IN_MS;


    /* ================================================================
     * TIMING — HUE WHEEL
     * ================================================================ */

    public const HUE_WHEEL_PRE_FADE_TO_BLACK_MS = 2000;
    public const HUE_WHEEL_BLACK_HOLD_MS = 0;

    public const HUE_WHEEL_FADE_IN_MS = 420;
    public const HUE_WHEEL_SPOKE_DELAY_MS = 420;
    public const HUE_WHEEL_SPOKE_STAGGER_MS = 260;
    public const HUE_WHEEL_SPOKE_DURATION_MS = 800;
    public const HUE_WHEEL_HOLD_AFTER_SPOKES_MS = 900;


    /* ================================================================
     * TIMING — BRAND BUMPER
     * ================================================================ */

    public const BRAND_BUMPER_PRE_FADE_TO_BLACK_MS = 2000;
    public const BRAND_BUMPER_BLACK_HOLD_MS = 0;

    public const BRAND_BUMPER_FADE_IN_MS = 420;
    public const BRAND_BUMPER_FADE_OUT_MS = 420;

    public const BRAND_BUMPER_SIGNATURE_DELAY_MS = 760;
    public const BRAND_BUMPER_SIGNATURE_DURATION_MS = 1750;


    /* ================================================================
     * TIMING — WHOLE VIDEO / MUSIC
     * ================================================================ */

    public const FINAL_FADE_MS = 1400;
    public const MUSIC_FADE_OUT_MS = 3000;
    public const DEFAULT_MUSIC_VOLUME = 0.20;


    /* ================================================================
     * VISUALS — GENERAL
     * ================================================================ */

    public const BACKGROUND_COLOR = '#000000';
    public const TEXT_COLOR = '#ffffff';
    public const FONT_FAMILY = 'Helvetica, Arial, sans-serif';

    public const PHOTO_OBJECT_FIT = 'contain';


    /* ================================================================
     * VISUALS — INTRO
     * ================================================================ */

    public const INTRO_PHOTO_OPACITY = 0.36;

    public const INTRO_TITLE_FONT_SIZE = 68;
    public const INTRO_TITLE_FONT_WEIGHT = 600;

    public const INTRO_SUBTITLE_FONT_SIZE = 42;
    public const INTRO_SUBTITLE_FONT_WEIGHT = 400;

    public const INTRO_BODY_FONT_SIZE = 32;
    public const INTRO_BODY_FONT_WEIGHT = 400;

    public const INTRO_LINE_HEIGHT = 1.20;


    /* ================================================================
     * VISUALS — PHOTO CAPTIONS
     * ================================================================ */

    public const CAPTION_LEFT = 67;
    public const CAPTION_BOTTOM = 38;
    public const CAPTION_MAX_WIDTH = 900;

    public const CAPTION_PADDING_X = 28;
    public const CAPTION_PADDING_Y = 16;

    public const CAPTION_BACKGROUND =
        'rgba(0, 0, 0, 0.45)';

    public const CAPTION_TITLE_FONT_SIZE = 64;
    public const CAPTION_TITLE_FONT_WEIGHT = 700;
    public const CAPTION_TITLE_LINE_HEIGHT = 1.25;

    public const CAPTION_SUBTITLE_FONT_SIZE = 52;
    public const CAPTION_SUBTITLE_FONT_WEIGHT = 400;
    public const CAPTION_SUBTITLE_LINE_HEIGHT = 1.30;

    public const CAPTION_BODY_FONT_SIZE = 52;
    public const CAPTION_BODY_FONT_WEIGHT = 400;
    public const CAPTION_BODY_LINE_HEIGHT = 1.35;


    /* ================================================================
     * VISUALS — TEXT SLIDES
     * ================================================================ */

    public const TEXT_SCREEN_PADDING_X = 154;
    public const TEXT_SCREEN_PADDING_Y = 86;

    public const TEXT_TITLE_FONT_SIZE = 68;
    public const TEXT_TITLE_FONT_WEIGHT = 600;

    public const TEXT_SUBTITLE_FONT_SIZE = 42;
    public const TEXT_SUBTITLE_FONT_WEIGHT = 400;

    public const TEXT_BODY_FONT_SIZE = 32;
    public const TEXT_BODY_FONT_WEIGHT = 400;

    public const TEXT_LINE_HEIGHT = 1.20;


    /* ================================================================
     * VISUALS — YOUTUBE THUMBNAIL
     * ================================================================ */

    public const THUMBNAIL_FONT_FILE =
        'public/fonts/Poppins-SemiBold.ttf';

    public const THUMBNAIL_WIDTH = 1280;
    public const THUMBNAIL_HEIGHT = 720;

    public const THUMBNAIL_TITLE_SIDE_PADDING = 72;
    public const THUMBNAIL_TITLE_FONT_SIZE = 68;
    public const THUMBNAIL_TITLE_MIN_FONT_SIZE = 48;
    public const THUMBNAIL_TITLE_MAX_LINES = 3;
    public const THUMBNAIL_LINE_GAP = 24;

    public const THUMBNAIL_TITLE_TOP_SINGLE_LINE = 200;
    public const THUMBNAIL_TITLE_TOP_MULTI_LINE = 70;
    public const THUMBNAIL_TITLE_AREA_HEIGHT = 250;

    public const THUMBNAIL_TEXT_COLOR = '#FFFFFF';
    public const THUMBNAIL_TEXT_STROKE_PX = 4;
    public const THUMBNAIL_JPEG_QUALITY = 90;


    /* ================================================================
     * VISUALS — HUE WHEEL
     * ================================================================ */

    public const HUE_WHEEL_SIZE_PX = 520;

    public const HUE_WHEEL_START_RADIUS = 0;
    public const HUE_WHEEL_END_RADIUS = 136;
    public const HUE_WHEEL_SPOKE_WIDTH_PX = 4.5;

    public const HUE_WHEEL_TITLE_FONT_SIZE = 64;
    public const HUE_WHEEL_TITLE_FONT_WEIGHT = 600;

    public const HUE_WHEEL_SUBTITLE_FONT_SIZE = 38;
    public const HUE_WHEEL_SUBTITLE_FONT_WEIGHT = 400;

    public const HUE_WHEEL_TEXT_GAP_PX = 12;
    public const HUE_WHEEL_CONTENT_GAP_PX = 28;
    public const HUE_WHEEL_TEXT_MAX_WIDTH_PX = 1400;


    /* ================================================================
     * VISUALS — BRAND BUMPER
     * ================================================================ */

    public const BRAND_BUMPER_LOGO_SIZE_PX = 120;
}
