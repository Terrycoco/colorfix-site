<?php
declare(strict_types=1);

namespace App\PUB\Create\YouTube;

/**
 * YOUTUBE PLAYLIST VIDEO RECIPE
 *
 * Passive reference notebook beside the YouTube Playlist Video Creator.
 *
 * This class contains product settings only.
 *
 * The Chef decides how to use these settings while building one complete
 * renderer-neutral video blueprint. The Recipe does NOT:
 *   - inspect ingredients
 *   - build scenes
 *   - build layers
 *   - translate to Remotion
 *   - queue jobs
 *   - know PUB lifecycle
 *
 * All tweakable product timing is expressed in milliseconds here so there
 * is one obvious place to tune the YouTube video.
 */
final class PlaylistVideoRecipe
{
    /*
     * OUTPUT
     *
     * CODEC is an output preference supplied to the current video
     * translator. It is not part of the neutral visual blueprint.
     */
    public const OUTPUT_MIME_TYPE =
        'video/mp4';

    public const CODEC =
        'h264';


    /*
     * VIDEO
     */
    public const WIDTH = 1920;
    public const HEIGHT = 1080;
    public const FPS = 30;


    /*
     * ================================================================
     * TIMING — milliseconds
     * ================================================================
     */

    public const INTRO_DURATION_MS = 3600;

    /*
     * Intro scene entry/exit.
     *
     * Intro never overlaps another scene. Previous content reaches
     * black first; intro fades in from black; intro fades back to
     * black before the following scene begins.
     */
    public const INTRO_PRE_FADE_TO_BLACK_MS = 900;
    public const INTRO_BLACK_HOLD_MS = 0;
    public const INTRO_FADE_IN_MS = 900;
    public const INTRO_FADE_OUT_TO_BLACK_MS = 900;

    public const PALETTE_PHOTO_DURATION_MS = 8500;
    public const NON_PALETTE_PHOTO_DURATION_MS = 8500;
    public const NORMAL_PHOTO_DURATION_MS = 8500;

    public const TEXT_DURATION_MS = 7600;

    /*
     * Text scene entry/exit.
     *
     * Text slides also travel through black rather than overlapping
     * neighboring scenes.
     */
    public const TEXT_PRE_FADE_TO_BLACK_MS = 1200;
    public const TEXT_BLACK_HOLD_MS = 0;
    public const TEXT_FADE_IN_MS = 2000;
    public const TEXT_FADE_OUT_TO_BLACK_MS = 1200;

    /*
     * Hue-wheel slide.
     *
     * The Chef may extend this minimum when many spokes require more
     * animation time so the final spoke is never clipped.
     */
    /*
     * Hue-wheel entry.
     *
     * Do not dissolve the wheel over the previous scene.
     * Let the previous scene fade completely to black first, then
     * optionally hold black before the wheel/title begin fading in.
     */
    public const HUE_WHEEL_PRE_FADE_TO_BLACK_MS = 2000;
    public const HUE_WHEEL_BLACK_HOLD_MS = 0;

    public const HUE_WHEEL_MIN_DURATION_MS = 6200;
    public const HUE_WHEEL_FADE_IN_MS = 420;
    public const HUE_WHEEL_SPOKE_DELAY_MS = 420;
    public const HUE_WHEEL_SPOKE_STAGGER_MS = 260;
    public const HUE_WHEEL_SPOKE_DURATION_MS = 800;
    public const HUE_WHEEL_HOLD_AFTER_SPOKES_MS = 900;

    public const BRAND_BUMPER_DURATION_MS = 5200;


    /*
     * Scene-to-scene dissolve.
     */
    public const DISSOLVE_MS = 2000;


    /*
     * Photo caption.
     *
     * Photo-to-photo scenes may overlap during the normal dissolve, but
     * their captions should not. Wait until the incoming picture has
     * finished its dissolve, then pause briefly before fading its copy in.
     *
     * Tune CAPTION_AFTER_DISSOLVE_DELAY_MS for the little visual breath.
     */
    public const CAPTION_AFTER_DISSOLVE_DELAY_MS = 250;

    public const CAPTION_DELAY_MS =
        self::DISSOLVE_MS
        + self::CAPTION_AFTER_DISSOLVE_DELAY_MS;

    public const CAPTION_FADE_MS = 2000;

    /*
     * Readable hold after the caption has fully faded in.
     *
     * Photo slides with copy are automatically extended as needed so
     * this full-opacity reading time is never squeezed by the outgoing
     * caption fade and the following picture dissolve.
     */
    public const CAPTION_HOLD_MS = 3500;

    /*
     * Outgoing photo caption.
     *
     * The old caption must be completely gone BEFORE the next picture
     * begins its dissolve. This keeps two captions from ever sharing
     * the screen and prevents text fragments from hanging in letterbox
     * or empty image areas.
     */
    public const CAPTION_FADE_OUT_MS = 500;

    public const CAPTION_FADE_OUT_END_BEFORE_SCENE_END_MS =
        self::DISSOLVE_MS;


    /*
     * Text screen.
     *
     * The old first-pass Recipe used 900 ms directly in code for intro
     * copy. It is now exposed here with every other timing knob.
     */
    public const INTRO_TEXT_FADE_MS =
        self::INTRO_FADE_IN_MS;

    public const TEXT_FADE_MS =
        self::TEXT_FADE_IN_MS;


    /*
     * Whole-video ending.
     */
    public const FINAL_FADE_MS = 1400;


    /*
     * Standard ColorFix brand bumper.
     *
     * Content must finish fading completely to black before the bumper
     * is allowed to begin. BLACK_HOLD may be zero.
     *
     * The authored playlist slide supplies only item_type=brand-bumper.
     * These production settings determine the standardized video bumper.
     */
    public const BRAND_BUMPER_PRE_FADE_TO_BLACK_MS = 2000;
    public const BRAND_BUMPER_BLACK_HOLD_MS = 0;

    public const BRAND_BUMPER_FADE_IN_MS = 420;
    public const BRAND_BUMPER_FADE_OUT_MS = 420;

    public const BRAND_BUMPER_SIGNATURE_DELAY_MS = 760;
    public const BRAND_BUMPER_SIGNATURE_DURATION_MS = 1750;


    /*
     * ================================================================
     * VISUALS
     * ================================================================
     */

    public const BACKGROUND_COLOR = '#000000';
    public const TEXT_COLOR = '#ffffff';
    public const FONT_FAMILY = 'Helvetica, Arial, sans-serif';

    public const PHOTO_OBJECT_FIT = 'contain';


    /*
     * Intro/photo-backed text treatment.
     */
    public const INTRO_PHOTO_OPACITY = 0.36;


    /*
     * Caption placement.
     */
    public const CAPTION_LEFT = 67;
    public const CAPTION_BOTTOM = 38;
    public const CAPTION_MAX_WIDTH = 1114;

    public const CAPTION_PADDING_X = 20;
    public const CAPTION_PADDING_Y = 14;

    public const CAPTION_BACKGROUND =
        'rgba(0, 0, 0, 0.45)';


    /*
     * Caption typography.
     *
     * Title/subtitle/body remain separate recipe knobs even while the
     * current generic oven may need to realize them as separate layers.
     */
    public const CAPTION_TITLE_FONT_SIZE = 34;
    public const CAPTION_TITLE_FONT_WEIGHT = 500;
    public const CAPTION_TITLE_LINE_HEIGHT = 1.25;

    public const CAPTION_SUBTITLE_FONT_SIZE = 23;
    public const CAPTION_SUBTITLE_FONT_WEIGHT = 400;
    public const CAPTION_SUBTITLE_LINE_HEIGHT = 1.30;

    public const CAPTION_BODY_FONT_SIZE = 23;
    public const CAPTION_BODY_FONT_WEIGHT = 400;
    public const CAPTION_BODY_LINE_HEIGHT = 1.35;


    /*
     * Text-screen placement.
     */
    public const TEXT_SCREEN_PADDING_X = 154;
    public const TEXT_SCREEN_PADDING_Y = 86;


    /*
     * Intro typography.
     */
    public const INTRO_TITLE_FONT_SIZE = 68;
    public const INTRO_TITLE_FONT_WEIGHT = 600;

    public const INTRO_SUBTITLE_FONT_SIZE = 42;
    public const INTRO_SUBTITLE_FONT_WEIGHT = 400;

    public const INTRO_BODY_FONT_SIZE = 32;
    public const INTRO_BODY_FONT_WEIGHT = 400;

    public const INTRO_LINE_HEIGHT = 1.20;


    /*
     * Standard text-slide typography.
     */
    public const TEXT_TITLE_FONT_SIZE = 68;
    public const TEXT_TITLE_FONT_WEIGHT = 600;

    public const TEXT_SUBTITLE_FONT_SIZE = 42;
    public const TEXT_SUBTITLE_FONT_WEIGHT = 400;

    public const TEXT_BODY_FONT_SIZE = 32;
    public const TEXT_BODY_FONT_WEIGHT = 400;

    public const TEXT_LINE_HEIGHT = 1.20;


    /*
     * Hue-wheel presentation.
     *
     * The static wheel artwork is renderer equipment. These values are
     * product presentation settings owned by the YouTube Recipe.
     *
     * Radii use the established 300 x 300 hue-wheel coordinate system.
     */
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


    /*
     * Standard ColorFix brand bumper.
     *
     * The shared brand-logo primitive owns the actual logo artwork.
     * The Recipe controls only product presentation.
     */
    public const BRAND_BUMPER_LOGO_SIZE_PX = 88;
}
