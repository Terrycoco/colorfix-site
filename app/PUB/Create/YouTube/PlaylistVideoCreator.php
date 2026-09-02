<?php
declare(strict_types=1);

namespace App\PUB\Create\YouTube;

use App\PUB\Create\Video\PdoVideoJobRepository;
use App\PUB\Create\Video\Support\VideoLayerBuilder;
use App\PUB\Create\Video\VideoWorkerHealthService;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use App\PUB\Repos\PdoPubAssetRepository;
use RuntimeException;
use Throwable;

/**
 * YOUTUBE PLAYLIST VIDEO CREATOR
 *
 * Product Chef for one complete YouTube playlist video.
 *
 * The Chef receives only the prepared ingredients declared in PubContract.
 * It uses PlaylistVideoRecipe as a passive reference notebook and mixes one
 * complete renderer-neutral video blueprint.
 *
 * The complete blueprint is then handed to ONE shared video translator.
 * The Chef never writes Remotion syntax, frame numbers, component registry
 * names, or Remotion animation target paths.
 *
 * Rendering remains asynchronous.
 */
final class PlaylistVideoCreator implements PubComWorkerContract
{
    private const CREATOR_KEY =
        'youtube.playlist_video';


    private const SUPPORTED_ITEM_TYPES = [
        'intro',
        'text',
        'palette',
        'non-palette',
        'normal',
        'hue-wheel',
        'brand-bumper',
    ];


    private ?PubComChannel $pubComChannel = null;


    public function __construct(
        private PdoPubAssetRepository $assets,
        private PdoVideoJobRepository $videoJobs,
        private VideoWorkerHealthService $videoWorkerHealth,
        private string $projectRoot,
        private string $publicBaseUrl,
    ) {}


    /**
     * PUBCOM ONBOARDING
     */
    public function connectPubCom(
        PubComChannel $channel
    ): void {
        $this->pubComChannel =
            $channel;
    }


    /**
     * Can this kitchen station cook right now?
     */
    public function readiness(): PubComSignal
    {
        if (
            trim(
                $this->projectRoot
            ) === ''
        ) {
            return PubComSignal::unavailable(
                'youtube_playlist_video_project_root_unavailable',
                'YouTube Playlist Video Creator has no project root.',
                [
                    'worker' =>
                        self::class,
                ]
            );
        }


        if (
            trim(
                $this->publicBaseUrl
            ) === ''
        ) {
            return PubComSignal::unavailable(
                'youtube_playlist_video_public_base_url_unavailable',
                'YouTube Playlist Video Creator has no public base URL.',
                [
                    'worker' =>
                        self::class,
                ]
            );
        }


        if (
            !extension_loaded(
                'gd'
            )
            || !function_exists(
                'imagecreatetruecolor'
            )
            || !function_exists(
                'imagettftext'
            )
            || !function_exists(
                'imagettfbbox'
            )
        ) {
            return PubComSignal::unavailable(
                'youtube_thumbnail_gd_unavailable',
                'YouTube Playlist Video Creator cannot render thumbnail typography because PHP GD/FreeType support is unavailable.',
                [
                    'worker' =>
                        self::class,
                ]
            );
        }


        $thumbnailFont =
            $this->thumbnailFontPath();


        if (!is_file($thumbnailFont)) {
            return PubComSignal::unavailable(
                'youtube_thumbnail_font_unavailable',
                'YouTube Playlist Video Creator cannot render thumbnails because the Recipe font file is unavailable.',
                [
                    'worker' =>
                        self::class,

                    'font_file' =>
                        PlaylistVideoRecipe::THUMBNAIL_FONT_FILE,
                ]
            );
        }


        $workerHealth =
            $this->videoWorkerHealth
                ->status();


        if (
            empty(
                $workerHealth[
                    'alive'
                ]
            )
        ) {
            return PubComSignal::unavailable(
                'youtube_playlist_video_worker_unavailable',
                'Video worker is not running. On the Mac, from the ColorFix project root, run: ./scripts/start-pub-video-worker.sh',
                [
                    'worker' =>
                        self::class,

                    'video_worker' =>
                        $workerHealth,

                    'start_command' =>
                        './scripts/start-pub-video-worker.sh',
                ]
            );
        }


        return PubComSignal::ready(
            'YouTube Playlist Video Creator is ready.',
            [
                'worker' =>
                    self::class,

                'video_worker' =>
                    $workerHealth,
            ]
        );
    }


    /**
     * Defensive inspection of the prepared ingredient box.
     *
     * ANALYZE is responsible for handing the Chef exactly what it ordered.
     * The Chef still verifies that the promised ingredients actually arrived.
     */
    public function preflight(
        array $ingredients
    ): PubComSignal {
        try {
            $this->assertCreateIngredients(
                $ingredients
            );

        } catch (RuntimeException $e) {
            return PubComSignal::ineligible(
                'youtube_playlist_video_invalid_ingredients',
                'YouTube Playlist Video cannot be created because its prepared ingredients are incomplete or invalid.',
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

                'slide_count' =>
                    count(
                        $ingredients[
                            'slides'
                        ]
                    ),
            ]
        );
    }


    /**
     * Cook one complete YouTube video.
     *
     *  ingredients
     *      ↓
     *  complete neutral blueprint
     *      ↓
     *  one shared translator pass
     *      ↓
     *  renderer-specific job props
     *      ↓
     *  asynchronous video oven
     */
    public function create(
        int $pubAssetId,
        array $ingredients
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'YouTube Playlist Video Creator requires a valid pub_asset_id.'
            );
        }


        $this->assertCreateIngredients(
            $ingredients
        );


        try {
            /*
             * REDO SAFETY.
             *
             * If this asset already has unfinished video work,
             * supersede it before queueing the replacement.
             */
            $this->videoJobs
                ->failActiveJobsForAsset(
                    $pubAssetId,
                    'Superseded by a newer CREATE request.'
                );


            $slides =
                $this->prepareSlides(
                    $ingredients[
                        'slides'
                    ]
                );


            $music =
                $this->prepareMusic(
                    $ingredients[
                        'music'
                    ]
                );


            /*
             * CHEF'S FINISHED BATTER.
             *
             * This structure is renderer-neutral and uses milliseconds.
             */
            $blueprint =
                $this->buildVideoBlueprint(
                    $slides,
                    $music
                );


            /*
             * ONE TRANSLATION PASS.
             *
             * VideoLayerBuilder is the current renderer adapter. It returns:
             *
             *   [
             *     'video_recipe_key' => string,
             *     'render_plan'      => array,
             *   ]
             *
             * The Chef does not know the renderer's composition name or
             * internal layer/animation syntax.
             */
            $translated =
                (new VideoLayerBuilder())
                    ->translate(
                        blueprint:
                            $blueprint,

                        fps:
                            PlaylistVideoRecipe::FPS,

                        codec:
                            PlaylistVideoRecipe::CODEC
                    );


            $videoRecipeKey =
                trim(
                    (string)(
                        $translated[
                            'video_recipe_key'
                        ]
                        ?? ''
                    )
                );

            $renderPlan =
                $translated[
                    'render_plan'
                ]
                ?? null;


            if (
                $videoRecipeKey === ''
                || !is_array(
                    $renderPlan
                )
            ) {
                throw new RuntimeException(
                    'Video translator did not return a valid renderer recipe key and render plan.'
                );
            }


            $job =
                $this->videoJobs
                    ->createJob(
                        $pubAssetId,
                        self::CREATOR_KEY,
                        $videoRecipeKey,
                        $renderPlan,
                        PlaylistVideoRecipe::OUTPUT_MIME_TYPE
                    );


            return [
                'pub_asset_id' =>
                    $pubAssetId,

                'asset_type' =>
                    'youtube_video',

                'create_status' =>
                    'queued',

                'video_job' =>
                    $job,
            ];

        } catch (Throwable $e) {
            $this->assets
                ->markError(
                    $pubAssetId,
                    'create',
                    'youtube_playlist_video_queue_failed',
                    $e->getMessage()
                );

            throw $e;
        }
    }


    /**
     * Promote the worker's private MP4 into the permanent YouTube
     * PUB asset location.
     *
     * $renderPlan is the exact translated plan saved on pub_video_jobs
     * when this render was queued. Duration is derived from that saved
     * render plan, not from the current editable order.
     */
    public function promoteCompletedVideo(
        int $pubAssetId,
        string $outputRelPath,
        array $renderPlan,
        array $cover,
        ?int $reportedFileSizeBytes = null
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Completed YouTube Playlist Video requires a valid pub_asset_id.'
            );
        }


        $workingFile =
            $this->resolveWorkingFile(
                $outputRelPath
            );


        if (!is_file($workingFile)) {
            throw new RuntimeException(
                'Completed YouTube Playlist Video working file was not found.'
            );
        }


        $video =
            is_array(
                $renderPlan[
                    'video'
                ]
                ?? null
            )
                ? $renderPlan[
                    'video'
                ]
                : [];


        $durationInFrames =
            (int)(
                $video[
                    'duration_in_frames'
                ]
                ?? 0
            );

        $fps =
            (int)(
                $video[
                    'fps'
                ]
                ?? 0
            );


        if (
            $durationInFrames <= 0
            || $fps <= 0
        ) {
            throw new RuntimeException(
                'Completed YouTube Playlist Video job has no valid saved render duration.'
            );
        }


        $durationMs =
            (int)round(
                (
                    $durationInFrames
                    / $fps
                )
                * 1000
            );


        $targetDir =
            rtrim(
                $this->projectRoot,
                DIRECTORY_SEPARATOR
            )
            . '/public/pub_assets/youtube';


        if (
            !is_dir(
                $targetDir
            )
            && !mkdir(
                $targetDir,
                0775,
                true
            )
            && !is_dir(
                $targetDir
            )
        ) {
            throw new RuntimeException(
                'Could not create YouTube PUB asset directory.'
            );
        }


        $targetPath =
            $targetDir
            . '/'
            . $pubAssetId
            . '.mp4';


        $temporaryTarget =
            $targetPath
            . '.tmp-'
            . bin2hex(
                random_bytes(6)
            );


        if (
            !copy(
                $workingFile,
                $temporaryTarget
            )
        ) {
            throw new RuntimeException(
                'Could not stage completed YouTube Playlist Video.'
            );
        }


        try {
            $fileSize =
                filesize(
                    $temporaryTarget
                );


            if (
                $fileSize === false
                || $fileSize <= 0
            ) {
                throw new RuntimeException(
                    'Completed YouTube Playlist Video file is empty.'
                );
            }


            if (
                $reportedFileSizeBytes !== null
                && $reportedFileSizeBytes > 0
                && $fileSize !== $reportedFileSizeBytes
            ) {
                throw new RuntimeException(
                    'Completed YouTube Playlist Video file size does not match the worker upload receipt.'
                );
            }


            /*
             * Same-filesystem atomic replacement.
             *
             * REDO therefore replaces the existing MP4 in place.
             */
            if (
                !rename(
                    $temporaryTarget,
                    $targetPath
                )
            ) {
                throw new RuntimeException(
                    'Could not promote completed YouTube Playlist Video.'
                );
            }


            $checksum =
                hash_file(
                    'sha256',
                    $targetPath
                );


            if ($checksum === false) {
                throw new RuntimeException(
                    'Could not checksum completed YouTube Playlist Video.'
                );
            }


            /*
             * The companion thumbnail is another physical output owned by
             * this Chef. It is created only when the completed MP4 is being
             * promoted, so CREATE persists both outputs together.
             */
            $thumbnail =
                $this->renderThumbnail(
                    $pubAssetId,
                    $cover,
                    $targetDir
                );


            return [
                'file_path' =>
                    $targetPath,

                'url' =>
                    '/public/pub_assets/youtube/'
                    . $pubAssetId
                    . '.mp4',

                'thumbnail_file_path' =>
                    $thumbnail[
                        'file_path'
                    ],

                'thumbnail_url' =>
                    $thumbnail[
                        'url'
                    ],

                'mime_type' =>
                    PlaylistVideoRecipe::OUTPUT_MIME_TYPE,

                'width' =>
                    PlaylistVideoRecipe::WIDTH,

                'height' =>
                    PlaylistVideoRecipe::HEIGHT,

                'duration_ms' =>
                    $durationMs,

                'file_size_bytes' =>
                    $fileSize,

                'checksum' =>
                    $checksum,
            ];

        } catch (Throwable $e) {
            if (
                is_file(
                    $temporaryTarget
                )
            ) {
                @unlink(
                    $temporaryTarget
                );
            }

            throw $e;
        }
    }


    public function recordVideoFailure(
        int $pubAssetId,
        string $message
    ): void {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Failed YouTube Playlist Video requires a valid pub_asset_id.'
            );
        }


        $message =
            trim(
                $message
            );


        if ($message === '') {
            $message =
                'YouTube Playlist Video rendering failed.';
        }


        $this->assets
            ->markError(
                $pubAssetId,
                'create',
                'youtube_playlist_video_render_failed',
                $message
            );
    }


    /**
     * Best-effort cleanup after the permanent asset and durable
     * video-job completion record have both succeeded.
     */
    public function discardWorkingFile(
        string $outputRelPath
    ): void {
        try {
            $workingFile =
                $this->resolveWorkingFile(
                    $outputRelPath
                );


            if (
                is_file(
                    $workingFile
                )
            ) {
                @unlink(
                    $workingFile
                );
            }

        } catch (Throwable) {
            /*
             * Cleanup cannot invalidate an otherwise completed asset.
             */
        }
    }


    /**
     * Resolve only files created inside private PUB video-job storage.
     */
    private function resolveWorkingFile(
        string $outputRelPath
    ): string {
        $outputRelPath =
            trim(
                str_replace(
                    '\\',
                    '/',
                    $outputRelPath
                )
            );


        if (
            $outputRelPath === ''
            || !str_starts_with(
                $outputRelPath,
                'storage/pub-video-jobs/'
            )
            || str_contains(
                $outputRelPath,
                '..'
            )
        ) {
            throw new RuntimeException(
                'Video job output path is outside PUB video-job storage.'
            );
        }


        return rtrim(
            $this->projectRoot,
            DIRECTORY_SEPARATOR
        )
            . '/'
            . $outputRelPath;
    }


    /*
     * ================================================================
     * CHEF — NEUTRAL BLUEPRINT
     * ================================================================
     */

    /**
     * Convert environment-relative image URLs into absolute URLs the
     * rendering service can load.
     *
     * This is operational preparation, not product layout logic.
     */
    private function prepareSlides(
        array $slides
    ): array {
        $prepared = [];


        foreach (
            $slides
            as $slide
        ) {
            $next =
                $slide;


            if (
                is_array(
                    $slide[
                        'photo'
                    ]
                    ?? null
                )
            ) {
                $next[
                    'photo'
                ][
                    'image_url'
                ] =
                    $this->absolutePublicUrl(
                        (string)(
                            $slide[
                                'photo'
                            ][
                                'image_url'
                            ]
                            ?? ''
                        )
                    );
            }


            $prepared[] =
                $next;
        }


        return $prepared;
    }


    /**
     * Operational URL preparation only.
     *
     * ANALYZE has already supplied the complete standard music ingredient.
     * The Chef never resolves Asset Library IDs or visits the pantry.
     */
    private function prepareMusic(
        array $music
    ): array {
        return [
            'file_path' =>
                trim(
                    (string)(
                        $music[
                            'file_path'
                        ]
                        ?? ''
                    )
                ),

            'audio_url' =>
                $this->absolutePublicUrl(
                    (string)(
                        $music[
                            'audio_url'
                        ]
                        ?? ''
                    )
                ),

            'volume' =>
                (float)(
                    $music[
                        'volume'
                    ]
                    ?? 0
                ),
        ];
    }


    /**
     * Mix the entire video before anything is handed to the renderer.
     *
     * The blueprint contains:
     *   - milliseconds, never frames
     *   - renderer-neutral scene types
     *   - product layout/treatment values from PlaylistVideoRecipe
     *   - no Remotion component names
     *   - no React prop paths
     *   - no Remotion style paths
     */
    private function buildVideoBlueprint(
        array $slides,
        array $music
    ): array {
        $scenes = [];
        $cursorMs = 0;


        foreach (
            $slides
            as $index => $slide
        ) {
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


            $durationMs =
                $this->durationMsForSlide(
                    $slide,
                    $itemType
                );


            $isBrandBumper =
                $itemType ===
                'brand-bumper';

            $isHueWheel =
                $itemType ===
                'hue-wheel';

            $isIntro =
                $itemType ===
                'intro';

            $isText =
                $itemType ===
                'text';


            $previousItemType =
                $index > 0
                    ? strtolower(
                        trim(
                            (string)(
                                $slides[
                                    $index - 1
                                ][
                                    'item_type'
                                ]
                                ?? ''
                            )
                        )
                    )
                    : '';


            $previousIsIntro =
                $previousItemType ===
                'intro';

            $previousIsText =
                $previousItemType ===
                'text';


            /*
             * THROUGH-BLACK SCENE POLICY.
             *
             * These self-contained compositions never collide visually
             * with neighboring scenes:
             *
             *   intro
             *   text
             *   hue-wheel
             *   brand-bumper
             *
             * Intro/text also OWN their exit to black, so even an ordinary
             * photo that follows them must wait until that exit completes.
             */
            $currentNeedsCleanEntry =
                $isBrandBumper
                || $isHueWheel
                || $isIntro
                || $isText;

            $previousOwnsCleanExit =
                $previousIsIntro
                || $previousIsText;

            $waitForPreviousExit =
                $index > 0
                && (
                    $currentNeedsCleanEntry
                    || $previousOwnsCleanExit
                );


            if (
                $waitForPreviousExit
                && $scenes !== []
            ) {
                $previousSceneIndex =
                    count(
                        $scenes
                    ) - 1;


                /*
                 * If the outgoing scene has its own exit policy, it wins.
                 * Otherwise the incoming special scene declares how the
                 * previous ordinary content should fade away.
                 */
                $preFadeToBlackMs =
                    $previousIsIntro
                        ? PlaylistVideoRecipe::INTRO_FADE_OUT_TO_BLACK_MS
                        : (
                            $previousIsText
                                ? PlaylistVideoRecipe::TEXT_FADE_OUT_TO_BLACK_MS
                                : (
                                    $isBrandBumper
                                        ? PlaylistVideoRecipe::BRAND_BUMPER_PRE_FADE_TO_BLACK_MS
                                        : (
                                            $isHueWheel
                                                ? PlaylistVideoRecipe::HUE_WHEEL_PRE_FADE_TO_BLACK_MS
                                                : (
                                                    $isIntro
                                                        ? PlaylistVideoRecipe::INTRO_PRE_FADE_TO_BLACK_MS
                                                        : PlaylistVideoRecipe::TEXT_PRE_FADE_TO_BLACK_MS
                                                )
                                        )
                                )
                        );


                $scenes[
                    $previousSceneIndex
                ][
                    'transition_out'
                ] = [
                    'type' =>
                        'fade-to-black',

                    'duration_ms' =>
                        $preFadeToBlackMs,

                    'to_color' =>
                        PlaylistVideoRecipe::BACKGROUND_COLOR,
                ];
            }


            /*
             * Optional black pause at the boundary.
             *
             * Prefer the incoming special scene's hold. When the incoming
             * scene is ordinary but follows intro/text, use the outgoing
             * intro/text hold instead.
             */
            $blackHoldMs =
                $isBrandBumper
                    ? PlaylistVideoRecipe::BRAND_BUMPER_BLACK_HOLD_MS
                    : (
                        $isHueWheel
                            ? PlaylistVideoRecipe::HUE_WHEEL_BLACK_HOLD_MS
                            : (
                                $isIntro
                                    ? PlaylistVideoRecipe::INTRO_BLACK_HOLD_MS
                                    : (
                                        $isText
                                            ? PlaylistVideoRecipe::TEXT_BLACK_HOLD_MS
                                            : (
                                                $previousIsIntro
                                                    ? PlaylistVideoRecipe::INTRO_BLACK_HOLD_MS
                                                    : (
                                                        $previousIsText
                                                            ? PlaylistVideoRecipe::TEXT_BLACK_HOLD_MS
                                                            : 0
                                                    )
                                            )
                                    )
                            )
                    );


            $startMs =
                $index === 0
                    ? 0
                    : (
                        $waitForPreviousExit
                            ? $cursorMs
                                + $blackHoldMs
                            : max(
                                0,
                                $cursorMs
                                - PlaylistVideoRecipe::DISSOLVE_MS
                            )
                    );


            /*
             * Intro/text fade in from the black canvas.
             *
             * Hue-wheel and bumper own their internal component fade-in.
             * Ordinary scenes use the standard dissolve; when they follow
             * intro/text there is no overlap, so that same opacity ramp is
             * simply a fade up from black.
             */
            $transitionIn =
                $isBrandBumper
                || $isHueWheel
                    ? null
                    : (
                        $isIntro
                            ? [
                                'type' =>
                                    'dissolve',

                                'duration_ms' =>
                                    PlaylistVideoRecipe::INTRO_FADE_IN_MS,
                            ]
                            : (
                                $isText
                                    ? [
                                        'type' =>
                                            'dissolve',

                                        'duration_ms' =>
                                            PlaylistVideoRecipe::TEXT_FADE_IN_MS,
                                    ]
                                    : (
                                        $index === 0
                                            ? null
                                            : [
                                                'type' =>
                                                    'dissolve',

                                                'duration_ms' =>
                                                    PlaylistVideoRecipe::DISSOLVE_MS,
                                            ]
                                    )
                            )
                    );


            $scene =
                match ($itemType) {
                    'intro' =>
                        $this->buildIntroScene(
                            slide:
                                $slide,

                            slideNumber:
                                $index + 1,

                            startMs:
                                $startMs,

                            durationMs:
                                $durationMs,

                            transitionIn:
                                $transitionIn
                        ),

                    'text' =>
                        $this->buildTextScene(
                            slide:
                                $slide,

                            slideNumber:
                                $index + 1,

                            startMs:
                                $startMs,

                            durationMs:
                                $durationMs,

                            transitionIn:
                                $transitionIn,

                            intro:
                                false
                        ),

                    'palette',
                    'non-palette' =>
                        $this->buildPhotoScene(
                            slide:
                                $slide,

                            slideNumber:
                                $index + 1,

                            startMs:
                                $startMs,

                            durationMs:
                                $durationMs,

                            transitionIn:
                                $transitionIn
                        ),

                    'normal' =>
                        $this->hasPhoto(
                            $slide
                        )
                            ? $this->buildPhotoScene(
                                slide:
                                    $slide,

                                slideNumber:
                                    $index + 1,

                                startMs:
                                    $startMs,

                                durationMs:
                                    $durationMs,

                                transitionIn:
                                    $transitionIn
                            )
                            : $this->buildTextScene(
                                slide:
                                    $slide,

                                slideNumber:
                                    $index + 1,

                                startMs:
                                    $startMs,

                                durationMs:
                                    $durationMs,

                                transitionIn:
                                    $transitionIn,

                                intro:
                                    false
                            ),

                    'hue-wheel' =>
                        $this->buildHueWheelScene(
                            slide:
                                $slide,

                            slideNumber:
                                $index + 1,

                            startMs:
                                $startMs,

                            durationMs:
                                $durationMs,

                            transitionIn:
                                $transitionIn
                        ),

                    'brand-bumper' =>
                        $this->buildBrandBumperScene(
                            slideNumber:
                                $index + 1,

                            startMs:
                                $startMs,

                            durationMs:
                                $durationMs,

                            transitionIn:
                                $transitionIn
                        ),

                    default =>
                        throw new RuntimeException(
                            "YouTube Playlist Video Creator cannot build item_type '{$itemType}'."
                        ),
                };


            $scenes[] =
                $scene;


            $cursorMs =
                $startMs
                + $durationMs;
        }


        if ($cursorMs <= 0) {
            throw new RuntimeException(
                'YouTube Playlist Video Creator calculated an invalid blueprint duration.'
            );
        }


        $endsWithBrandBumper =
            strtolower(
                trim(
                    (string)(
                        $scenes[
                            count(
                                $scenes
                            ) - 1
                        ][
                            'type'
                        ]
                        ?? ''
                    )
                )
            ) ===
            'brand-bumper';


        /*
         * MUSIC EXIT.
         *
         * Recipe owns how long the fade should last.
         * Chef owns timing it against this video's actual final duration.
         */
        $musicFadeOutMs =
            min(
                PlaylistVideoRecipe::MUSIC_FADE_OUT_MS,
                $cursorMs
            );

        $musicFadeOutStartMs =
            max(
                0,
                $cursorMs
                - $musicFadeOutMs
            );


        return [
            'type' =>
                'video',

            'canvas' => [
                'width' =>
                    PlaylistVideoRecipe::WIDTH,

                'height' =>
                    PlaylistVideoRecipe::HEIGHT,

                'background_color' =>
                    PlaylistVideoRecipe::BACKGROUND_COLOR,
            ],

            'duration_ms' =>
                $cursorMs,

            /*
             * The house bumper already owns its own fade to black.
             * Keep the generic whole-video fallback only when there
             * is no bumper at the end.
             */
            'final_fade' =>
                $endsWithBrandBumper
                    ? null
                    : [
                        'duration_ms' =>
                            PlaylistVideoRecipe::FINAL_FADE_MS,

                        'to_color' =>
                            PlaylistVideoRecipe::BACKGROUND_COLOR,
                    ],

            /*
             * Neutral audio instruction. VideoLayerBuilder translates
             * milliseconds into the current renderer's frame vocabulary.
             */
            'audio' => [
                [
                    'src' =>
                        $music[
                            'audio_url'
                        ],

                    'start_ms' =>
                        0,

                    'volume' =>
                        $music[
                            'volume'
                        ],

                    'fade_out' => [
                        'start_ms' =>
                            $musicFadeOutStartMs,

                        'duration_ms' =>
                            $musicFadeOutMs,
                    ],
                ],
            ],

            'scenes' =>
                $scenes,
        ];
    }


    /**
     * INTRO can be:
     *   - text only
     *   - photo + text
     *   - photo only
     */
    private function buildIntroScene(
        array $slide,
        int $slideNumber,
        int $startMs,
        int $durationMs,
        ?array $transitionIn
    ): array {
        if (
            $this->hasTitleOrSubtitle(
                $slide
            )
            || trim(
                (string)(
                    $slide[
                        'body'
                    ]
                    ?? ''
                )
            ) !== ''
        ) {
            return $this->buildTextScene(
                slide:
                    $slide,

                slideNumber:
                    $slideNumber,

                startMs:
                    $startMs,

                durationMs:
                    $durationMs,

                transitionIn:
                    $transitionIn,

                intro:
                    true
            );
        }


        if (
            $this->hasPhoto(
                $slide
            )
        ) {
            return $this->buildPhotoScene(
                slide:
                    $slide,

                slideNumber:
                    $slideNumber,

                startMs:
                    $startMs,

                durationMs:
                    $durationMs,

                transitionIn:
                    $transitionIn
            );
        }


        throw new RuntimeException(
            "YouTube intro slide {$slideNumber} has no renderable content."
        );
    }


    /**
     * Neutral full-frame photo scene with optional caption copy.
     */
    private function buildPhotoScene(
        array $slide,
        int $slideNumber,
        int $startMs,
        int $durationMs,
        ?array $transitionIn
    ): array {
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
                : [];


        $imageUrl =
            trim(
                (string)(
                    $photo[
                        'image_url'
                    ]
                    ?? ''
                )
            );


        if ($imageUrl === '') {
            throw new RuntimeException(
                "YouTube photo slide {$slideNumber} has no image_url."
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


        $caption =
            null;


        if (
            $title !== ''
            || $subtitle !== ''
            || $body !== ''
        ) {
            $caption = [
                'title' =>
                    $title,

                'subtitle' =>
                    $subtitle,

                'body' =>
                    $body,

                'placement' => [
                    'left_px' =>
                        PlaylistVideoRecipe::CAPTION_LEFT,

                    'bottom_px' =>
                        PlaylistVideoRecipe::CAPTION_BOTTOM,

                    'max_width_px' =>
                        PlaylistVideoRecipe::CAPTION_MAX_WIDTH,

                    'padding_x_px' =>
                        PlaylistVideoRecipe::CAPTION_PADDING_X,

                    'padding_y_px' =>
                        PlaylistVideoRecipe::CAPTION_PADDING_Y,
                ],

                'background_color' =>
                    PlaylistVideoRecipe::CAPTION_BACKGROUND,

                'color' =>
                    PlaylistVideoRecipe::TEXT_COLOR,

                'font_family' =>
                    PlaylistVideoRecipe::FONT_FAMILY,

                'title_style' => [
                    'font_size_px' =>
                        PlaylistVideoRecipe::CAPTION_TITLE_FONT_SIZE,

                    'font_weight' =>
                        PlaylistVideoRecipe::CAPTION_TITLE_FONT_WEIGHT,

                    'line_height' =>
                        PlaylistVideoRecipe::CAPTION_TITLE_LINE_HEIGHT,
                ],

                'subtitle_style' => [
                    'font_size_px' =>
                        PlaylistVideoRecipe::CAPTION_SUBTITLE_FONT_SIZE,

                    'font_weight' =>
                        PlaylistVideoRecipe::CAPTION_SUBTITLE_FONT_WEIGHT,

                    'line_height' =>
                        PlaylistVideoRecipe::CAPTION_SUBTITLE_LINE_HEIGHT,
                ],

                'body_style' => [
                    'font_size_px' =>
                        PlaylistVideoRecipe::CAPTION_BODY_FONT_SIZE,

                    'font_weight' =>
                        PlaylistVideoRecipe::CAPTION_BODY_FONT_WEIGHT,

                    'line_height' =>
                        PlaylistVideoRecipe::CAPTION_BODY_LINE_HEIGHT,
                ],

                'delay_ms' =>
                    PlaylistVideoRecipe::CAPTION_DELAY_MS,

                'fade_ms' =>
                    PlaylistVideoRecipe::CAPTION_FADE_MS,

                'fade_out_ms' =>
                    PlaylistVideoRecipe::CAPTION_FADE_OUT_MS,

                'fade_out_end_before_scene_end_ms' =>
                    PlaylistVideoRecipe::CAPTION_FADE_OUT_END_BEFORE_SCENE_END_MS,
            ];
        }


        return [
            'id' =>
                "yt-scene-{$slideNumber}",

            'type' =>
                'photo',

            'source_item_type' =>
                strtolower(
                    trim(
                        (string)(
                            $slide[
                                'item_type'
                            ]
                            ?? ''
                        )
                    )
                ),

            'start_ms' =>
                $startMs,

            'duration_ms' =>
                $durationMs,

            'transition_in' =>
                $transitionIn,

            'background_color' =>
                PlaylistVideoRecipe::BACKGROUND_COLOR,

            'photo' => [
                'src' =>
                    $imageUrl,

                'fit' =>
                    PlaylistVideoRecipe::PHOTO_OBJECT_FIT,
            ],

            'caption' =>
                $caption,
        ];
    }


    /**
     * Neutral text-led scene.
     *
     * An optional photo is a backdrop, not a separate product type.
     */
    private function buildTextScene(
        array $slide,
        int $slideNumber,
        int $startMs,
        int $durationMs,
        ?array $transitionIn,
        bool $intro
    ): array {
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


        if (
            $title === ''
            && $subtitle === ''
            && $body === ''
        ) {
            throw new RuntimeException(
                "YouTube text slide {$slideNumber} has no text."
            );
        }


        $backgroundPhoto =
            null;


        if (
            $this->hasPhoto(
                $slide
            )
        ) {
            $backgroundPhoto = [
                'src' =>
                    (string)$slide[
                        'photo'
                    ][
                        'image_url'
                    ],

                'fit' =>
                    PlaylistVideoRecipe::PHOTO_OBJECT_FIT,

                'opacity' =>
                    PlaylistVideoRecipe::INTRO_PHOTO_OPACITY,
            ];
        }


        return [
            'id' =>
                "yt-scene-{$slideNumber}",

            'type' =>
                'text',

            'source_item_type' =>
                strtolower(
                    trim(
                        (string)(
                            $slide[
                                'item_type'
                            ]
                            ?? ''
                        )
                    )
                ),

            'variant' =>
                $intro
                    ? 'intro'
                    : 'standard',

            'start_ms' =>
                $startMs,

            'duration_ms' =>
                $durationMs,

            'transition_in' =>
                $transitionIn,

            'background_color' =>
                PlaylistVideoRecipe::BACKGROUND_COLOR,

            'background_photo' =>
                $backgroundPhoto,

            'text' => [
                'title' =>
                    $title,

                'subtitle' =>
                    $subtitle,

                'body' =>
                    $body,

                'color' =>
                    PlaylistVideoRecipe::TEXT_COLOR,

                'font_family' =>
                    PlaylistVideoRecipe::FONT_FAMILY,

                'padding_x_px' =>
                    PlaylistVideoRecipe::TEXT_SCREEN_PADDING_X,

                'padding_y_px' =>
                    PlaylistVideoRecipe::TEXT_SCREEN_PADDING_Y,

                'align' =>
                    'center',

                'vertical_align' =>
                    'center',

                'title_style' => [
                    'font_size_px' =>
                        $intro
                            ? PlaylistVideoRecipe::INTRO_TITLE_FONT_SIZE
                            : PlaylistVideoRecipe::TEXT_TITLE_FONT_SIZE,

                    'font_weight' =>
                        $intro
                            ? PlaylistVideoRecipe::INTRO_TITLE_FONT_WEIGHT
                            : PlaylistVideoRecipe::TEXT_TITLE_FONT_WEIGHT,
                ],

                'subtitle_style' => [
                    'font_size_px' =>
                        $intro
                            ? PlaylistVideoRecipe::INTRO_SUBTITLE_FONT_SIZE
                            : PlaylistVideoRecipe::TEXT_SUBTITLE_FONT_SIZE,

                    'font_weight' =>
                        $intro
                            ? PlaylistVideoRecipe::INTRO_SUBTITLE_FONT_WEIGHT
                            : PlaylistVideoRecipe::TEXT_SUBTITLE_FONT_WEIGHT,
                ],

                'body_style' => [
                    'font_size_px' =>
                        $intro
                            ? PlaylistVideoRecipe::INTRO_BODY_FONT_SIZE
                            : PlaylistVideoRecipe::TEXT_BODY_FONT_SIZE,

                    'font_weight' =>
                        $intro
                            ? PlaylistVideoRecipe::INTRO_BODY_FONT_WEIGHT
                            : PlaylistVideoRecipe::TEXT_BODY_FONT_WEIGHT,
                ],

                'line_height' =>
                    $intro
                        ? PlaylistVideoRecipe::INTRO_LINE_HEIGHT
                        : PlaylistVideoRecipe::TEXT_LINE_HEIGHT,

                'fade_ms' =>
                    $intro
                        ? PlaylistVideoRecipe::INTRO_TEXT_FADE_MS
                        : PlaylistVideoRecipe::TEXT_FADE_MS,
            ],
        ];
    }


    /**
     * Neutral hue-wheel scene.
     *
     * The Chef knows the product treatment, but not React/Remotion.
     * It passes exact authored hue/hex spokes plus Recipe-owned
     * presentation and timing values to the renderer-neutral blueprint.
     */
    private function buildHueWheelScene(
        array $slide,
        int $slideNumber,
        int $startMs,
        int $durationMs,
        ?array $transitionIn
    ): array {
        $hueWheel =
            is_array(
                $slide[
                    'hue_wheel'
                ]
                ?? null
            )
                ? $slide[
                    'hue_wheel'
                ]
                : [];


        $sourceSpokes =
            is_array(
                $hueWheel[
                    'spokes'
                ]
                ?? null
            )
                ? array_values(
                    $hueWheel[
                        'spokes'
                    ]
                )
                : [];


        if ($sourceSpokes === []) {
            throw new RuntimeException(
                "YouTube hue-wheel slide {$slideNumber} has no prepared spokes."
            );
        }


        $spokes = [];


        foreach (
            $sourceSpokes
            as $index => $sourceSpoke
        ) {
            if (!is_array($sourceSpoke)) {
                throw new RuntimeException(
                    "YouTube hue-wheel slide {$slideNumber} spoke "
                    . (
                        $index + 1
                    )
                    . ' is invalid.'
                );
            }


            $animate =
                (
                    $sourceSpoke[
                        'animate'
                    ]
                    ?? true
                ) !== false;


            $baseDelayMs =
                array_key_exists(
                    'delay_ms',
                    $sourceSpoke
                )
                    ? max(
                        0,
                        (int)round(
                            (float)$sourceSpoke[
                                'delay_ms'
                            ]
                        )
                    )
                    : PlaylistVideoRecipe::HUE_WHEEL_SPOKE_DELAY_MS;


            $spokeDurationMs =
                array_key_exists(
                    'duration_ms',
                    $sourceSpoke
                )
                    ? max(
                        1,
                        (int)round(
                            (float)$sourceSpoke[
                                'duration_ms'
                            ]
                        )
                    )
                    : PlaylistVideoRecipe::HUE_WHEEL_SPOKE_DURATION_MS;


            $spokes[] = [
                'hue' =>
                    (float)$sourceSpoke[
                        'hue'
                    ],

                'color' =>
                    (string)$sourceSpoke[
                        'color'
                    ],

                'animate' =>
                    $animate,

                'start_radius' =>
                    array_key_exists(
                        'start_radius',
                        $sourceSpoke
                    )
                        ? (float)$sourceSpoke[
                            'start_radius'
                        ]
                        : PlaylistVideoRecipe::HUE_WHEEL_START_RADIUS,

                'end_radius' =>
                    array_key_exists(
                        'end_radius',
                        $sourceSpoke
                    )
                        ? (float)$sourceSpoke[
                            'end_radius'
                        ]
                        : PlaylistVideoRecipe::HUE_WHEEL_END_RADIUS,

                /*
                 * Relative to scene start. Static spokes simply fade in
                 * with the wheel; animated spokes wait until the wheel/title
                 * have appeared, then draw sequentially.
                 */
                'start_offset_ms' =>
                    $animate
                        ? PlaylistVideoRecipe::HUE_WHEEL_FADE_IN_MS
                            + $baseDelayMs
                            + (
                                $index
                                * PlaylistVideoRecipe::HUE_WHEEL_SPOKE_STAGGER_MS
                            )
                        : 0,

                'duration_ms' =>
                    $animate
                        ? $spokeDurationMs
                        : 0,
            ];
        }


        return [
            'id' =>
                "yt-scene-{$slideNumber}",

            'type' =>
                'hue-wheel',

            'source_item_type' =>
                'hue-wheel',

            'start_ms' =>
                $startMs,

            'duration_ms' =>
                $durationMs,

            'transition_in' =>
                $transitionIn,

            'background_color' =>
                PlaylistVideoRecipe::BACKGROUND_COLOR,

            'title' =>
                trim(
                    (string)(
                        $slide[
                            'title'
                        ]
                        ?? ''
                    )
                ),

            'subtitle' =>
                trim(
                    (string)(
                        $slide[
                            'subtitle'
                        ]
                        ?? ''
                    )
                ),

            'wheel' => [
                'size_px' =>
                    PlaylistVideoRecipe::HUE_WHEEL_SIZE_PX,

                'fade_in_ms' =>
                    PlaylistVideoRecipe::HUE_WHEEL_FADE_IN_MS,

                'spoke_width_px' =>
                    PlaylistVideoRecipe::HUE_WHEEL_SPOKE_WIDTH_PX,

                'spokes' =>
                    $spokes,
            ],

            'text' => [
                'color' =>
                    PlaylistVideoRecipe::TEXT_COLOR,

                'font_family' =>
                    PlaylistVideoRecipe::FONT_FAMILY,

                'max_width_px' =>
                    PlaylistVideoRecipe::HUE_WHEEL_TEXT_MAX_WIDTH_PX,

                'text_gap_px' =>
                    PlaylistVideoRecipe::HUE_WHEEL_TEXT_GAP_PX,

                'content_gap_px' =>
                    PlaylistVideoRecipe::HUE_WHEEL_CONTENT_GAP_PX,

                'title_style' => [
                    'font_size_px' =>
                        PlaylistVideoRecipe::HUE_WHEEL_TITLE_FONT_SIZE,

                    'font_weight' =>
                        PlaylistVideoRecipe::HUE_WHEEL_TITLE_FONT_WEIGHT,
                ],

                'subtitle_style' => [
                    'font_size_px' =>
                        PlaylistVideoRecipe::HUE_WHEEL_SUBTITLE_FONT_SIZE,

                    'font_weight' =>
                        PlaylistVideoRecipe::HUE_WHEEL_SUBTITLE_FONT_WEIGHT,
                ],
            ],
        ];
    }


    /**
     * STANDARDIZED HOUSE BUMPER.
     *
     * The Chef does not draw the logo, parse playlist bumper JSON,
     * know a React component name, or know signatureProgress.
     *
     * It simply places the standardized house-bumper instruction in
     * the neutral blueprint using product settings from the Recipe.
     * The translator/helper side owns how today's renderer realizes it.
     */
    private function buildBrandBumperScene(
        int $slideNumber,
        int $startMs,
        int $durationMs,
        ?array $transitionIn
    ): array {
        return [
            'id' =>
                "yt-scene-{$slideNumber}",

            'type' =>
                'brand-bumper',

            'start_ms' =>
                $startMs,

            'duration_ms' =>
                $durationMs,

            'transition_in' =>
                $transitionIn,

            'background_color' =>
                PlaylistVideoRecipe::BACKGROUND_COLOR,

            'brand' => [
                'key' =>
                    'colorfix',

                'position' =>
                    'center',

                'logo_size_px' =>
                    PlaylistVideoRecipe::BRAND_BUMPER_LOGO_SIZE_PX,

                'fade_in_ms' =>
                    PlaylistVideoRecipe::BRAND_BUMPER_FADE_IN_MS,

                'fade_out_ms' =>
                    PlaylistVideoRecipe::BRAND_BUMPER_FADE_OUT_MS,

                'signature' => [
                    'delay_ms' =>
                        PlaylistVideoRecipe::BRAND_BUMPER_SIGNATURE_DELAY_MS,

                    'duration_ms' =>
                        PlaylistVideoRecipe::BRAND_BUMPER_SIGNATURE_DURATION_MS,
                ],
            ],
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
                $this->hasPhoto(
                    $slide
                )
                    ? $this->photoDurationMs(
                        $slide,
                        PlaylistVideoRecipe::NORMAL_PHOTO_DURATION_MS
                    )
                    : PlaylistVideoRecipe::TEXT_DURATION_MS,

            'hue-wheel' =>
                $this->hueWheelDurationMs(
                    $slide
                ),

            'brand-bumper' =>
                PlaylistVideoRecipe::BRAND_BUMPER_DURATION_MS,

            default =>
                throw new RuntimeException(
                    "YouTube Playlist Video Creator has no duration for item_type '{$itemType}'."
                ),
        };
    }


    /**
     * Keep ordinary photo timing when there is no caption.
     *
     * When a photo carries title/subtitle/body copy, extend the scene
     * only as much as needed to guarantee the Recipe's full-opacity
     * reading hold before the caption exits ahead of the next dissolve.
     */
    private function photoDurationMs(
        array $slide,
        int $baseDurationMs
    ): int {
        if (
            !$this->hasTextContent(
                $slide
            )
        ) {
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


    /**
     * Keep the standard hue-wheel duration compact, but never clip
     * the final animated spoke.
     */
    private function hueWheelDurationMs(
        array $slide
    ): int {
        $spokes =
            is_array(
                $slide[
                    'hue_wheel'
                ][
                    'spokes'
                ]
                ?? null
            )
                ? array_values(
                    $slide[
                        'hue_wheel'
                    ][
                        'spokes'
                    ]
                )
                : [];


        $lastAnimationEndMs = 0;


        foreach (
            $spokes
            as $index => $spoke
        ) {
            if (!is_array($spoke)) {
                continue;
            }


            $animate =
                (
                    $spoke[
                        'animate'
                    ]
                    ?? true
                ) !== false;


            if (!$animate) {
                continue;
            }


            $baseDelayMs =
                array_key_exists(
                    'delay_ms',
                    $spoke
                )
                    ? max(
                        0,
                        (int)round(
                            (float)$spoke[
                                'delay_ms'
                            ]
                        )
                    )
                    : PlaylistVideoRecipe::HUE_WHEEL_SPOKE_DELAY_MS;


            $durationMs =
                array_key_exists(
                    'duration_ms',
                    $spoke
                )
                    ? max(
                        1,
                        (int)round(
                            (float)$spoke[
                                'duration_ms'
                            ]
                        )
                    )
                    : PlaylistVideoRecipe::HUE_WHEEL_SPOKE_DURATION_MS;


            $lastAnimationEndMs =
                max(
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


    /*
     * ================================================================
     * CHEF — INGREDIENT CONTRACT DEFENSE
     * ================================================================
     */

    private function assertCreateIngredients(
        array $ingredients
    ): void {
        $this->assertCoverIngredient(
            $ingredients[
                'cover'
            ]
            ?? null
        );


        $this->assertMusicIngredient(
            $ingredients[
                'music'
            ]
            ?? null
        );


        if (
            !array_key_exists(
                'slides',
                $ingredients
            )
            || !is_array(
                $ingredients[
                    'slides'
                ]
            )
        ) {
            throw new RuntimeException(
                'YouTube Playlist Video ingredients.slides must be an array.'
            );
        }


        $slides =
            $ingredients[
                'slides'
            ];


        if (
            count(
                $slides
            ) === 0
        ) {
            throw new RuntimeException(
                'YouTube Playlist Video requires at least one prepared slide.'
            );
        }


        foreach (
            $slides
            as $index => $slide
        ) {
            $slideNumber =
                $index + 1;


            if (!is_array($slide)) {
                throw new RuntimeException(
                    "YouTube slide {$slideNumber} must be an array."
                );
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


            if ($itemType === '') {
                throw new RuntimeException(
                    "YouTube slide {$slideNumber} is missing item_type."
                );
            }


            if (
                !in_array(
                    $itemType,
                    self::SUPPORTED_ITEM_TYPES,
                    true
                )
            ) {
                throw new RuntimeException(
                    "YouTube slide {$slideNumber} has unsupported item_type '{$itemType}'."
                );
            }


            $hasPhoto =
                $this->assertAndDetectPhoto(
                    $slide,
                    $slideNumber
                );


            $hasTitleOrSubtitle =
                $this->hasTitleOrSubtitle(
                    $slide
                );

            $hasAnyText =
                $this->hasTextContent(
                    $slide
                );


            match ($itemType) {
                /*
                 * Intro may be text-only, photo+text, or photo-only.
                 */
                'intro' =>
                    (
                        $hasPhoto
                        || $hasTitleOrSubtitle
                    )
                        ? true
                        : throw new RuntimeException(
                            "YouTube intro slide {$slideNumber} requires a photo, title, or subtitle."
                        ),

                /*
                 * Text must communicate with title and/or subtitle.
                 * Photo is optional.
                 */
                'text' =>
                    $hasTitleOrSubtitle
                        ? true
                        : throw new RuntimeException(
                            "YouTube text slide {$slideNumber} requires title or subtitle."
                        ),

                /*
                 * Photo is the actual ingredient; copy is optional.
                 */
                'palette',
                'non-palette' =>
                    $hasPhoto
                        ? true
                        : throw new RuntimeException(
                            "YouTube {$itemType} slide {$slideNumber} requires a prepared photo."
                        ),

                /*
                 * Hue-wheel requires at least one prepared spoke.
                 */
                'hue-wheel' =>
                    $this->assertHueWheelIngredient(
                        $slide,
                        $slideNumber
                    ),

                /*
                 * The item_type itself is the complete instruction.
                 */
                'brand-bumper' =>
                    true,

                /*
                 * Temporary compatibility rule until normal is tuned.
                 */
                'normal' =>
                    (
                        $hasPhoto
                        || $hasAnyText
                    )
                        ? true
                        : throw new RuntimeException(
                            "YouTube normal slide {$slideNumber} requires a photo or text."
                        ),

                default =>
                    throw new RuntimeException(
                        "YouTube slide {$slideNumber} has unsupported item_type '{$itemType}'."
                    ),
            };
        }
    }


    private function assertHueWheelIngredient(
        array $slide,
        int $slideNumber
    ): bool {
        $hueWheel =
            is_array(
                $slide[
                    'hue_wheel'
                ]
                ?? null
            )
                ? $slide[
                    'hue_wheel'
                ]
                : null;


        if ($hueWheel === null) {
            throw new RuntimeException(
                "YouTube hue-wheel slide {$slideNumber} requires hue_wheel."
            );
        }


        $spokes =
            is_array(
                $hueWheel[
                    'spokes'
                ]
                ?? null
            )
                ? array_values(
                    $hueWheel[
                        'spokes'
                    ]
                )
                : [];


        if ($spokes === []) {
            throw new RuntimeException(
                "YouTube hue-wheel slide {$slideNumber} requires at least one spoke."
            );
        }


        foreach (
            $spokes
            as $index => $spoke
        ) {
            $spokeNumber =
                $index + 1;


            if (!is_array($spoke)) {
                throw new RuntimeException(
                    "YouTube hue-wheel slide {$slideNumber} spoke {$spokeNumber} must be an array."
                );
            }


            if (
                !array_key_exists(
                    'hue',
                    $spoke
                )
                || !is_numeric(
                    $spoke[
                        'hue'
                    ]
                )
            ) {
                throw new RuntimeException(
                    "YouTube hue-wheel slide {$slideNumber} spoke {$spokeNumber} requires numeric hue."
                );
            }


            $color =
                strtoupper(
                    trim(
                        (string)(
                            $spoke[
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
                throw new RuntimeException(
                    "YouTube hue-wheel slide {$slideNumber} spoke {$spokeNumber} requires six-digit hex color."
                );
            }


            foreach (
                [
                    'delay_ms',
                    'duration_ms',
                    'start_radius',
                    'end_radius',
                ]
                as $field
            ) {
                if (
                    !array_key_exists(
                        $field,
                        $spoke
                    )
                ) {
                    continue;
                }


                if (
                    !is_numeric(
                        $spoke[
                            $field
                        ]
                    )
                    || (float)$spoke[
                        $field
                    ] < 0
                ) {
                    throw new RuntimeException(
                        "YouTube hue-wheel slide {$slideNumber} spoke {$spokeNumber} has invalid {$field}."
                    );
                }
            }
        }


        return true;
    }


    private function assertCoverIngredient(
        mixed $cover
    ): void {
        if (!is_array($cover)) {
            throw new RuntimeException(
                'YouTube Playlist Video ingredients.cover must be an object/array.'
            );
        }


        $filePath =
            trim(
                (string)(
                    $cover[
                        'file_path'
                    ]
                    ?? ''
                )
            );

        $imageUrl =
            trim(
                (string)(
                    $cover[
                        'image_url'
                    ]
                    ?? ''
                )
            );

        $title =
            trim(
                (string)(
                    $cover[
                        'title'
                    ]
                    ?? ''
                )
            );


        if ($filePath === '') {
            throw new RuntimeException(
                'YouTube Playlist Video ingredients.cover.file_path is required.'
            );
        }


        if ($imageUrl === '') {
            throw new RuntimeException(
                'YouTube Playlist Video ingredients.cover.image_url is required.'
            );
        }


        if ($title === '') {
            throw new RuntimeException(
                'YouTube Playlist Video ingredients.cover.title is required.'
            );
        }


        /*
         * text_color is optional for backward compatibility.
         * If absent, the Recipe's default is used.
         */
        $this->thumbnailTextColor(
            $cover
        );


        if (!is_file($filePath)) {
            throw new RuntimeException(
                'YouTube Playlist Video cover source file does not exist.'
            );
        }
    }


    /**
     * Resolve the saved per-asset thumbnail title color.
     *
     * Older orders omit cover.text_color and therefore inherit the
     * Recipe-owned default.
     */
    private function thumbnailTextColor(
        array $cover
    ): string {
        $value =
            strtoupper(
                trim(
                    (string)(
                        $cover[
                            'text_color'
                        ]
                        ?? ''
                    )
                )
            );


        if ($value === '') {
            return
                PlaylistVideoRecipe::THUMBNAIL_TEXT_COLOR;
        }


        if (
            preg_match(
                '/^#[0-9A-F]{6}$/',
                $value
            ) !== 1
        ) {
            throw new RuntimeException(
                'YouTube Playlist Video ingredients.cover.text_color must be a six-digit hex color.'
            );
        }


        return $value;
    }


    private function assertMusicIngredient(
        mixed $music
    ): void {
        if (!is_array($music)) {
            throw new RuntimeException(
                'YouTube Playlist Video ingredients.music must be an object/array.'
            );
        }


        $filePath =
            trim(
                (string)(
                    $music[
                        'file_path'
                    ]
                    ?? ''
                )
            );

        $audioUrl =
            trim(
                (string)(
                    $music[
                        'audio_url'
                    ]
                    ?? ''
                )
            );


        if ($filePath === '') {
            throw new RuntimeException(
                'YouTube Playlist Video ingredients.music.file_path is required.'
            );
        }


        if ($audioUrl === '') {
            throw new RuntimeException(
                'YouTube Playlist Video ingredients.music.audio_url is required.'
            );
        }


        if (
            !is_numeric(
                $music[
                    'volume'
                ]
                ?? null
            )
        ) {
            throw new RuntimeException(
                'YouTube Playlist Video ingredients.music.volume must be numeric.'
            );
        }


        $volume =
            (float)$music[
                'volume'
            ];


        if (
            $volume < 0
            || $volume > 1
        ) {
            throw new RuntimeException(
                'YouTube Playlist Video ingredients.music.volume must be between 0 and 1.'
            );
        }
    }


    private function assertAndDetectPhoto(
        array $slide,
        int $slideNumber
    ): bool {
        if (
            !array_key_exists(
                'photo',
                $slide
            )
            || $slide[
                'photo'
            ] === null
        ) {
            return false;
        }


        if (
            !is_array(
                $slide[
                    'photo'
                ]
            )
        ) {
            throw new RuntimeException(
                "YouTube slide {$slideNumber} photo must be an object/array when supplied."
            );
        }


        $filePath =
            trim(
                (string)(
                    $slide[
                        'photo'
                    ][
                        'file_path'
                    ]
                    ?? ''
                )
            );

        $imageUrl =
            trim(
                (string)(
                    $slide[
                        'photo'
                    ][
                        'image_url'
                    ]
                    ?? ''
                )
            );


        if (
            $filePath === ''
            || $imageUrl === ''
        ) {
            throw new RuntimeException(
                "YouTube slide {$slideNumber} supplied a photo without both file_path and image_url."
            );
        }


        return true;
    }


    private function hasPhoto(
        array $slide
    ): bool {
        return
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
    }


    private function hasTitleOrSubtitle(
        array $slide
    ): bool {
        foreach (
            [
                'title',
                'subtitle',
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


    private function hasTextContent(
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


    /**
     * FIRST-DRAFT YOUTUBE THUMBNAIL
     *
     * Full-bleed authored cover photo + direct-over-photo title.
     * All tweakable thumbnail presentation values live in
     * PlaylistVideoRecipe.
     *
     * @return array{file_path:string,url:string}
     */
    private function renderThumbnail(
        int $pubAssetId,
        array $cover,
        string $targetDir
    ): array {
        $this->assertCoverIngredient(
            $cover
        );


        $sourcePath =
            trim(
                (string)$cover[
                    'file_path'
                ]
            );

        $title =
            trim(
                (string)$cover[
                    'title'
                ]
            );

        $textColor =
            $this->thumbnailTextColor(
                $cover
            );


        $canvas =
            imagecreatetruecolor(
                PlaylistVideoRecipe::THUMBNAIL_WIDTH,
                PlaylistVideoRecipe::THUMBNAIL_HEIGHT
            );


        if (!$canvas instanceof \GdImage) {
            throw new RuntimeException(
                'Could not create YouTube thumbnail canvas.'
            );
        }


        try {
            $this->copyThumbnailImageCover(
                $canvas,
                $sourcePath
            );


            imagealphablending(
                $canvas,
                true
            );


            /*
             * Direct-over-photo title treatment.
             *
             * No banner or text box. The title sits on the image itself,
             * with a dark stroke so it remains readable over mixed photos.
             */
            $this->drawThumbnailTitle(
                $canvas,
                $title,
                PlaylistVideoRecipe::THUMBNAIL_TITLE_SIDE_PADDING,
                PlaylistVideoRecipe::THUMBNAIL_WIDTH
                    - (
                        PlaylistVideoRecipe::THUMBNAIL_TITLE_SIDE_PADDING
                        * 2
                    ),
                PlaylistVideoRecipe::THUMBNAIL_TITLE_AREA_HEIGHT,
                $textColor
            );


            $filePath =
                rtrim(
                    $targetDir,
                    DIRECTORY_SEPARATOR
                )
                . '/'
                . $pubAssetId
                . '-thumbnail.jpg';


            $temporaryPath =
                $filePath
                . '.tmp-'
                . bin2hex(
                    random_bytes(6)
                );


            if (
                !imagejpeg(
                    $canvas,
                    $temporaryPath,
                    PlaylistVideoRecipe::THUMBNAIL_JPEG_QUALITY
                )
            ) {
                throw new RuntimeException(
                    'Could not write YouTube thumbnail JPEG.'
                );
            }


            $fileSize =
                filesize(
                    $temporaryPath
                );


            if (
                $fileSize === false
                || $fileSize <= 0
            ) {
                @unlink(
                    $temporaryPath
                );

                throw new RuntimeException(
                    'YouTube thumbnail JPEG is empty.'
                );
            }


            if (
                !rename(
                    $temporaryPath,
                    $filePath
                )
            ) {
                @unlink(
                    $temporaryPath
                );

                throw new RuntimeException(
                    'Could not promote YouTube thumbnail JPEG.'
                );
            }


            return [
                'file_path' =>
                    $filePath,

                'url' =>
                    '/public/pub_assets/youtube/'
                    . $pubAssetId
                    . '-thumbnail.jpg',
            ];

        } finally {
            imagedestroy(
                $canvas
            );
        }
    }


    private function copyThumbnailImageCover(
        \GdImage $canvas,
        string $sourcePath
    ): void {
        $info =
            getimagesize(
                $sourcePath
            );


        if (!$info) {
            throw new RuntimeException(
                'YouTube thumbnail source image is invalid.'
            );
        }


        [
            $sourceWidth,
            $sourceHeight,
        ] =
            $info;


        $source =
            $this->openThumbnailSourceImage(
                $sourcePath,
                (string)(
                    $info[
                        'mime'
                    ]
                    ?? ''
                )
            );


        try {
            $scale =
                max(
                    PlaylistVideoRecipe::THUMBNAIL_WIDTH
                        / max(
                            1,
                            $sourceWidth
                        ),

                    PlaylistVideoRecipe::THUMBNAIL_HEIGHT
                        / max(
                            1,
                            $sourceHeight
                        )
                );


            $cropWidth =
                (int)round(
                    PlaylistVideoRecipe::THUMBNAIL_WIDTH
                    / $scale
                );

            $cropHeight =
                (int)round(
                    PlaylistVideoRecipe::THUMBNAIL_HEIGHT
                    / $scale
                );


            $sourceX =
                max(
                    0,
                    (int)floor(
                        (
                            $sourceWidth
                            - $cropWidth
                        ) / 2
                    )
                );

            $sourceY =
                max(
                    0,
                    (int)floor(
                        (
                            $sourceHeight
                            - $cropHeight
                        ) / 2
                    )
                );


            imagecopyresampled(
                $canvas,
                $source,
                0,
                0,
                $sourceX,
                $sourceY,
                PlaylistVideoRecipe::THUMBNAIL_WIDTH,
                PlaylistVideoRecipe::THUMBNAIL_HEIGHT,
                $cropWidth,
                $cropHeight
            );

        } finally {
            imagedestroy(
                $source
            );
        }
    }


    private function openThumbnailSourceImage(
        string $sourcePath,
        string $mime
    ): \GdImage {
        $source =
            match (
                strtolower(
                    $mime
                )
            ) {
                'image/jpeg' =>
                    @imagecreatefromjpeg(
                        $sourcePath
                    ),

                'image/png' =>
                    @imagecreatefrompng(
                        $sourcePath
                    ),

                'image/webp' =>
                    function_exists(
                        'imagecreatefromwebp'
                    )
                        ? @imagecreatefromwebp(
                            $sourcePath
                        )
                        : false,

                default =>
                    @imagecreatefromstring(
                        (string)@file_get_contents(
                            $sourcePath
                        )
                    ),
            };


        if (!$source instanceof \GdImage) {
            throw new RuntimeException(
                'YouTube thumbnail source image type is unsupported.'
            );
        }


        return $source;
    }


    private function drawThumbnailTitle(
        \GdImage $canvas,
        string $title,
        int $x,
        int $width,
        int $height,
        string $textColorHex
    ): void {
        $font =
            $this->thumbnailFontPath();


        if (!is_file($font)) {
            throw new RuntimeException(
                'YouTube thumbnail Recipe font file was not found: '
                . PlaylistVideoRecipe::THUMBNAIL_FONT_FILE
            );
        }


        $textColorHex =
            strtoupper(
                ltrim(
                    trim(
                        $textColorHex
                    ),
                    '#'
                )
            );


        $titleColor =
            imagecolorallocate(
                $canvas,
                hexdec(
                    substr(
                        $textColorHex,
                        0,
                        2
                    )
                ),
                hexdec(
                    substr(
                        $textColorHex,
                        2,
                        2
                    )
                ),
                hexdec(
                    substr(
                        $textColorHex,
                        4,
                        2
                    )
                )
            );

        $black =
            imagecolorallocate(
                $canvas,
                0,
                0,
                0
            );


        $fontSize =
            PlaylistVideoRecipe::THUMBNAIL_TITLE_FONT_SIZE;


        do {
            $lines =
                $this->wrapThumbnailText(
                    $title,
                    $font,
                    $fontSize,
                    $width
                );

            $lineHeight =
                $fontSize
                + PlaylistVideoRecipe::THUMBNAIL_LINE_GAP;


            $fits =
                count($lines)
                    <= PlaylistVideoRecipe::THUMBNAIL_TITLE_MAX_LINES
                && (
                    count($lines)
                    * $lineHeight
                ) <= $height;


            if (
                $fits
                || $fontSize
                    <= PlaylistVideoRecipe::THUMBNAIL_TITLE_MIN_FONT_SIZE
            ) {
                break;
            }


            $fontSize -=
                2;

        } while (true);


        if (
            count($lines)
            > PlaylistVideoRecipe::THUMBNAIL_TITLE_MAX_LINES
        ) {
            $lines =
                array_slice(
                    $lines,
                    0,
                    PlaylistVideoRecipe::THUMBNAIL_TITLE_MAX_LINES
                );
        }


        $lineHeight =
            $fontSize
            + PlaylistVideoRecipe::THUMBNAIL_LINE_GAP;


        /*
         * One-line titles look stranded when they use the same high
         * title region as a taller two/three-line block. Use the actual
         * wrapped line count to choose the Recipe-owned vertical position.
         */
        $titleTop =
            count($lines) === 1
                ? PlaylistVideoRecipe::THUMBNAIL_TITLE_TOP_SINGLE_LINE
                : PlaylistVideoRecipe::THUMBNAIL_TITLE_TOP_MULTI_LINE;


        $textY =
            $titleTop
            + (int)round(
                (
                    $height
                    - (
                        count($lines)
                        * $lineHeight
                    )
                ) / 2
            )
            + $fontSize;


        foreach (
            $lines
            as $line
        ) {
            $box =
                imagettfbbox(
                    $fontSize,
                    0,
                    $font,
                    $line
                );


            $textWidth =
                $box
                    ? abs(
                        (int)$box[4]
                        - (int)$box[0]
                    )
                    : 0;


            $textX =
                $x
                + (int)round(
                    (
                        $width
                        - $textWidth
                    ) / 2
                );


            /*
             * Black stroke + chosen fill, similar to traditional YouTube
             * thumbnail lettering. Draw the stroke by offsetting the same
             * glyphs around the final title text.
             */
            for (
                $offsetX =
                    -PlaylistVideoRecipe::THUMBNAIL_TEXT_STROKE_PX;
                $offsetX <=
                    PlaylistVideoRecipe::THUMBNAIL_TEXT_STROKE_PX;
                $offsetX++
            ) {
                for (
                    $offsetY =
                        -PlaylistVideoRecipe::THUMBNAIL_TEXT_STROKE_PX;
                    $offsetY <=
                        PlaylistVideoRecipe::THUMBNAIL_TEXT_STROKE_PX;
                    $offsetY++
                ) {
                    if (
                        $offsetX === 0
                        && $offsetY === 0
                    ) {
                        continue;
                    }


                    if (
                        (
                            $offsetX * $offsetX
                            + $offsetY * $offsetY
                        )
                        >
                        (
                            PlaylistVideoRecipe::THUMBNAIL_TEXT_STROKE_PX
                            * PlaylistVideoRecipe::THUMBNAIL_TEXT_STROKE_PX
                        )
                    ) {
                        continue;
                    }


                    imagettftext(
                        $canvas,
                        $fontSize,
                        0,
                        $textX + $offsetX,
                        $textY + $offsetY,
                        $black,
                        $font,
                        $line
                    );
                }
            }


            imagettftext(
                $canvas,
                $fontSize,
                0,
                $textX,
                $textY,
                $titleColor,
                $font,
                $line
            );


            $textY +=
                $lineHeight;
        }
    }


    private function wrapThumbnailText(
        string $text,
        string $font,
        int $fontSize,
        int $maxWidth
    ): array {
        /*
         * Preserve authored line breaks first. Within each authored line,
         * use the real Poppins/FreeType glyph measurements to decide
         * whether additional wrapping is necessary.
         *
         * This is more reliable than estimating by character count:
         * "WWW" and "iii" have very different widths even though they
         * contain the same number of characters.
         */
        $authoredLines =
            preg_split(
                '/\R/u',
                trim(
                    $text
                )
            )
            ?: [];


        $lines = [];


        foreach (
            $authoredLines
            as $authoredLine
        ) {
            $authoredLine =
                trim(
                    $authoredLine
                );


            if ($authoredLine === '') {
                continue;
            }


            $words =
                preg_split(
                    '/\s+/',
                    $authoredLine
                )
                ?: [];


            $line = '';


            foreach (
                $words
                as $word
            ) {
                $test =
                    trim(
                        $line
                        . ' '
                        . $word
                    );


                $box =
                    imagettfbbox(
                        $fontSize,
                        0,
                        $font,
                        $test
                    );


                $testWidth =
                    $box
                        ? abs(
                            (int)$box[4]
                            - (int)$box[0]
                        )
                        : 0;


                if (
                    $line !== ''
                    && $testWidth > $maxWidth
                ) {
                    $lines[] =
                        $line;

                    $line =
                        $word;

                } else {
                    $line =
                        $test;
                }
            }


            if ($line !== '') {
                $lines[] =
                    $line;
            }
        }


        return $lines;
    }


    /**
     * Resolve the exact thumbnail font declared by the YouTube Recipe.
     *
     * The Recipe owns the product font choice. The Chef only resolves
     * that project-relative supply path at runtime.
     */
    private function thumbnailFontPath(): string
    {
        return rtrim(
            $this->projectRoot,
            DIRECTORY_SEPARATOR
        )
            . '/'
            . ltrim(
                PlaylistVideoRecipe::THUMBNAIL_FONT_FILE,
                '/\\'
            );
    }


    private function absolutePublicUrl(
        string $value
    ): string {
        $value =
            trim(
                $value
            );


        if ($value === '') {
            throw new RuntimeException(
                'YouTube Playlist Video image URL is empty.'
            );
        }


        if (
            preg_match(
                '~^https?://~i',
                $value
            ) === 1
        ) {
            return $value;
        }


        return
            rtrim(
                $this->publicBaseUrl,
                '/'
            )
            . '/'
            . ltrim(
                $value,
                '/'
            );
    }
}
