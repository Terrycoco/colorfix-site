<?php
declare(strict_types=1);

namespace App\PUB\Create\YouTube;

use App\PUB\Create\Video\PdoVideoJobRepository;
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
 * CHEF'S INGREDIENT ORDER:
 *
 *   ingredients {
 *     slides[] {
 *       item_type
 *
 *       optional photo {
 *         file_path
 *         image_url
 *       }
 *
 *       optional title
 *       optional subtitle
 *       optional body
 *     }
 *   }
 *
 * Array order is playback order.
 * One ingredient box produces ONE complete YouTube video.
 *
 * This Creator owns production of the YouTube video, but keeps
 * product decisions in PlaylistVideoRecipe.
 *
 * It may use any shared production tools/helpers. It does NOT:
 *   - acquire source ingredients
 *   - inspect the outer PUB Box
 *   - reserve pub_asset_id values
 *   - decide NEW vs REDO
 *   - file or recover orders
 *   - package
 *   - schedule
 *   - publish / dispatch
 *
 * Rendering is asynchronous. The Creator compiles its recipe and
 * submits the finished render plan to the shared pub_video_jobs oven.
 */
final class PlaylistVideoCreator implements PubComWorkerContract
{
    private const CREATOR_KEY =
        'youtube.playlist_video';

private const SUPPORTED_ITEM_TYPES = [
    'intro',
    'palette',
    'non-palette',
    'normal',
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
     * ANALYZE is responsible for handing the Chef clean ingredients.
     * The Chef still verifies that the promised box actually arrived.
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
     * Cook one complete YouTube video and submit the render plan
     * to the shared asynchronous video oven.
     *
     * CreateManager has already assigned pub_asset_id and owns the
     * durable order/lifecycle surrounding this production request.
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
                $this->prepareRecipeSlides(
                    $ingredients[
                        'slides'
                    ]
                );


            $recipe =
                PlaylistVideoRecipe::plan(
                    $slides
                );


            $job =
                $this->videoJobs
                    ->createJob(
                        $pubAssetId,
                        self::CREATOR_KEY,
                        PlaylistVideoRecipe::COMPOSITION_ID,
                        $recipe,
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
     * $renderPlan is the exact plan saved on pub_video_jobs when
     * this render was queued. Duration is derived from that plan,
     * not from the current editable order.
     */
    public function promoteCompletedVideo(
        int $pubAssetId,
        string $outputRelPath,
        array $renderPlan,
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


            return [
                'file_path' =>
                    $targetPath,

                'url' =>
                    '/public/pub_assets/youtube/'
                    . $pubAssetId
                    . '.mp4',

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


    /**
     * Convert environment-relative prepared image URLs into the
     * absolute URLs the external/local Remotion worker can load.
     *
     * This is operational preparation, not a product decision.
     */
    private function prepareRecipeSlides(
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


    private function assertCreateIngredients(
        array $ingredients
    ): void {
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
                false;


            if (
                array_key_exists(
                    'photo',
                    $slide
                )
                && $slide[
                    'photo'
                ] !== null
            ) {
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


                $hasPhoto =
                    true;
            }


            $hasText =
                $this->hasTextContent(
                    $slide
                );


            if (
                !$hasPhoto
                && !$hasText
            ) {
                throw new RuntimeException(
                    "YouTube slide {$slideNumber} contains no photo or text."
                );
            }
        }
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