<?php
declare(strict_types=1);

namespace App\PUB\Create\Pinterest;

use App\PUB\Create\CreateBoxValidator;
use App\PUB\Create\Video\PdoVideoJobRepository;
use App\PUB\Create\Video\VideoWorkerHealthService;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use App\PUB\Repos\PdoPubAssetRepository;
use RuntimeException;
use Throwable;

/**
 * PINTEREST BEFORE/AFTER VIDEO CREATOR
 *
 * CHEF'S INGREDIENT ORDER:
 *
 *   ingredients {
 *     before {
 *       file_path
 *       image_url
 *     }
 *
 *     after {
 *       file_path
 *       image_url
 *     }
 *
 *     search_title
 *     end_slide_text
 *   }
 *
 * This Creator owns the Pinterest Before/After video recipe.
 *
 * Rendering itself is asynchronous:
 *
 *   CREATE Manager
 *      ↓
 *   BeforeAfterVideoCreator
 *      ↓
 *   pub_video_jobs (queued)
 *      ↓
 *   external/local video worker
 *      ↓
 *   private uploaded working file
 *      ↓
 *   CREATE Manager authorizes final promotion
 *      ↓
 *   public/pub_assets/pinterest/{pub_asset_id}.mp4
 *
 * The Creator does NOT run Remotion directly.
 */
final class BeforeAfterVideoCreator implements PubComWorkerContract
{
    private const CREATOR_KEY =
        'pinterest.before_after_video';

    private const REQUIRED_INGREDIENTS = [
        'before',
        'after',
        'search_title',
        'end_slide_text',
    ];


    private ?PubComChannel $pubComChannel = null;


    public function __construct(
        private PdoPubAssetRepository $assets,
        private PdoVideoJobRepository $videoJobs,
        private VideoWorkerHealthService $videoWorkerHealth,
        private string $projectRoot,
        private string $publicBaseUrl,
    ) {}


    public function connectPubCom(
        PubComChannel $channel
    ): void {
        $this->pubComChannel =
            $channel;
    }


    public function readiness(): PubComSignal
    {
        if (
            trim(
                $this->projectRoot
            ) === ''
        ) {
            return PubComSignal::unavailable(
                'before_after_video_project_root_unavailable',
                'Before/After Video Creator has no project root.',
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
                'before_after_video_public_base_url_unavailable',
                'Before/After Video Creator has no public base URL.',
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
                'before_after_video_worker_unavailable',
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
            'Before/After Video Creator is ready.',
            [
                'worker' =>
                    self::class,

                'video_worker' =>
                    $workerHealth,
            ]
        );
    }


    /**
     * Inspect Creator ingredients before an asset ID is
     * reserved for a NEW order.
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
                'before_after_video_invalid_ingredients',
                'Before/After Video cannot be created because its ingredients are incomplete or invalid.',
                [
                    'worker' =>
                        self::class,

                    'reason' =>
                        $e->getMessage(),
                ]
            );
        }


        return PubComSignal::ready(
            'Before/After Video assignment is eligible.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * Queue asynchronous production.
     *
     * The permanent pub_asset row has already been reserved by
     * CreateManager. It intentionally remains pipeline_stage =
     * "creating" until the video worker returns a finished file.
     */
    public function create(
        int $pubAssetId,
        array $ingredients
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Before/After Video Creator requires a valid pub_asset_id.'
            );
        }


        $this->assertCreateIngredients(
            $ingredients
        );


        try {
            /*
             * REDO SAFETY.
             *
             * If the same asset already has an unfinished video job,
             * that older job must not be allowed to arrive later and
             * overwrite the newer REDO.
             */
            $this->videoJobs
                ->failActiveJobsForAsset(
                    $pubAssetId,
                    'Superseded by a newer CREATE request.'
                );


            $recipe =
                BeforeAfterVideoRecipe::plan(
                    $this->absolutePublicUrl(
                        (string)$ingredients[
                            'before'
                        ][
                            'image_url'
                        ]
                    ),
                    $this->absolutePublicUrl(
                        (string)$ingredients[
                            'after'
                        ][
                            'image_url'
                        ]
                    ),
                    (string)$ingredients[
                        'search_title'
                    ],
                    (string)$ingredients[
                        'end_slide_text'
                    ]
                );


            $job =
                $this->videoJobs
                    ->createJob(
                        $pubAssetId,
                        self::CREATOR_KEY,
                        BeforeAfterVideoRecipe::COMPOSITION_ID,
                        $recipe,
                        BeforeAfterVideoRecipe::OUTPUT_MIME_TYPE
                    );


            return [
                'pub_asset_id' =>
                    $pubAssetId,

                'asset_type' =>
                    'pin_before_after_video',

                /*
                 * CreateManager uses this field to distinguish an
                 * accepted async order from a physically created asset.
                 */
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
                    'before_after_video_queue_failed',
                    $e->getMessage()
                );

            throw $e;
        }
    }


    /**
     * Promote a completed private worker upload into the permanent
     * PUB asset location.
     *
     * CreateManager calls this only after it has verified that the
     * video job belongs to this Creator and to a real pub_asset.
     *
     * The private working file is deliberately NOT deleted here.
     * CreateManager first records the video job complete, then asks
     * discardWorkingFile() to clean up. That makes a failed DB update
     * retryable without rerendering the video.
     */
    public function promoteCompletedVideo(
        int $pubAssetId,
        string $outputRelPath,
        ?int $reportedFileSizeBytes = null
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Completed Before/After Video requires a valid pub_asset_id.'
            );
        }


        $workingFile =
            $this->resolveWorkingFile(
                $outputRelPath
            );


        if (!is_file($workingFile)) {
            throw new RuntimeException(
                'Completed Before/After Video working file was not found.'
            );
        }


        $targetDir =
            rtrim(
                $this->projectRoot,
                DIRECTORY_SEPARATOR
            )
            . '/public/pub_assets/pinterest';


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
                'Could not create Pinterest PUB asset directory.'
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
                'Could not stage completed Before/After Video.'
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
                    'Completed Before/After Video file is empty.'
                );
            }


            if (
                $reportedFileSizeBytes !== null
                && $reportedFileSizeBytes > 0
                && $fileSize !== $reportedFileSizeBytes
            ) {
                throw new RuntimeException(
                    'Completed Before/After Video file size does not match the worker upload receipt.'
                );
            }


            /*
             * Atomic replacement on the same filesystem.
             * For REDO this replaces the old MP4 in place.
             */
            if (
                !rename(
                    $temporaryTarget,
                    $targetPath
                )
            ) {
                throw new RuntimeException(
                    'Could not promote completed Before/After Video.'
                );
            }


            $checksum =
                hash_file(
                    'sha256',
                    $targetPath
                );


            if ($checksum === false) {
                throw new RuntimeException(
                    'Could not checksum completed Before/After Video.'
                );
            }


            $created = [
                'file_path' =>
                    $targetPath,

                'url' =>
                    '/public/pub_assets/pinterest/'
                    . $pubAssetId
                    . '.mp4',

                'mime_type' =>
                    BeforeAfterVideoRecipe::OUTPUT_MIME_TYPE,

                'width' =>
                    BeforeAfterVideoRecipe::WIDTH,

                'height' =>
                    BeforeAfterVideoRecipe::HEIGHT,

                'duration_ms' =>
                    BeforeAfterVideoRecipe::durationMs(),

                'file_size_bytes' =>
                    $fileSize,

                'checksum' =>
                    $checksum,
            ];


            /*
             * Return the finished physical asset to CreateManager.
             *
             * The Creator owns the product and file promotion.
             * CreateManager owns the durable lifecycle transition
             * from creating -> created.
             */
            return $created;

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
                'Failed Before/After Video requires a valid pub_asset_id.'
            );
        }


        $message =
            trim(
                $message
            );


        if ($message === '') {
            $message =
                'Before/After Video rendering failed.';
        }


        $this->assets
            ->markError(
                $pubAssetId,
                'create',
                'before_after_video_render_failed',
                $message
            );
    }


    /**
     * Best-effort cleanup after BOTH the permanent asset and the
     * durable video-job completion record have succeeded.
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


    private function assertCreateIngredients(
        array $ingredients
    ): void {
        CreateBoxValidator::assertRequired(
            self::REQUIRED_INGREDIENTS,
            $ingredients,
            'Before/After Video'
        );

        CreateBoxValidator::assertArray(
            $ingredients,
            'before',
            'Before/After Video'
        );

        CreateBoxValidator::assertArray(
            $ingredients,
            'after',
            'Before/After Video'
        );


        $beforePath =
            trim(
                (string)(
                    $ingredients[
                        'before'
                    ][
                        'file_path'
                    ]
                    ?? ''
                )
            );

        $afterPath =
            trim(
                (string)(
                    $ingredients[
                        'after'
                    ][
                        'file_path'
                    ]
                    ?? ''
                )
            );

        $beforeUrl =
            trim(
                (string)(
                    $ingredients[
                        'before'
                    ][
                        'image_url'
                    ]
                    ?? ''
                )
            );

        $afterUrl =
            trim(
                (string)(
                    $ingredients[
                        'after'
                    ][
                        'image_url'
                    ]
                    ?? ''
                )
            );

        $searchTitle =
            trim(
                (string)(
                    $ingredients[
                        'search_title'
                    ]
                    ?? ''
                )
            );

        $endSlideText =
            trim(
                (string)(
                    $ingredients[
                        'end_slide_text'
                    ]
                    ?? ''
                )
            );


        if ($beforePath === '') {
            throw new RuntimeException(
                'Before/After Video Before source file path is missing.'
            );
        }

        if ($afterPath === '') {
            throw new RuntimeException(
                'Before/After Video After source file path is missing.'
            );
        }

        if ($beforeUrl === '') {
            throw new RuntimeException(
                'Before/After Video Before image URL is missing.'
            );
        }

        if ($afterUrl === '') {
            throw new RuntimeException(
                'Before/After Video After image URL is missing.'
            );
        }

        if ($searchTitle === '') {
            throw new RuntimeException(
                'Before/After Video search_title is missing.'
            );
        }

        if ($endSlideText === '') {
            throw new RuntimeException(
                'Before/After Video end_slide_text is missing.'
            );
        }

        if (!is_file($beforePath)) {
            throw new RuntimeException(
                'Before/After Video Before source file does not exist.'
            );
        }

        if (!is_file($afterPath)) {
            throw new RuntimeException(
                'Before/After Video After source file does not exist.'
            );
        }
    }


    private function absolutePublicUrl(
        string $url
    ): string {
        $url =
            trim(
                $url
            );


        if ($url === '') {
            throw new RuntimeException(
                'Before/After Video image URL is empty.'
            );
        }


        if (
            preg_match(
                '#^https?://#i',
                $url
            ) === 1
        ) {
            return $url;
        }


        return rtrim(
            $this->publicBaseUrl,
            '/'
        )
            . '/'
            . ltrim(
                $url,
                '/'
            );
    }


    /**
     * Resolve only files that were written into the private
     * storage/pub-video-jobs staging area.
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
}