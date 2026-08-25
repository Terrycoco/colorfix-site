<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';

use App\PUB\Create\CreateManager;
use App\PUB\Create\Pinterest\BeforeAfterVideoCreator;
use App\PUB\Create\Pinterest\CompositeCreator;
use App\PUB\Create\Pinterest\IdeaCreator;
use App\PUB\Create\Pinterest\PaletteCreator;
use App\PUB\Create\Pinterest\Support\PinterestCreatorTools;
use App\PUB\Create\Video\PdoVideoJobRepository;
use App\PUB\Create\Video\VideoWorkerHealthService;
use App\PUB\Repos\PdoPubAssetRepository;
use App\PUB\Repos\PdoPubRunRepository;
use App\PUB\Services\PubRunService;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond([
            'ok' => false,
            'error' => 'POST only',
        ], 405);
    }

    $input = json_decode(
        file_get_contents('php://input') ?: '',
        true
    );

    if (!is_array($input)) {
        throw new InvalidArgumentException(
            'Invalid JSON.'
        );
    }

    $boxes =
        $input['boxes']
        ?? null;

    if (!is_array($boxes)) {
        throw new InvalidArgumentException(
            'boxes array is required.'
        );
    }

    if ($boxes === []) {
        throw new InvalidArgumentException(
            'At least one CREATE box is required.'
        );
    }


    /*
     * ========================================================
     * JOB / RUN ID
     * ========================================================
     *
     * One CREATE handoff is one batch of boxes belonging
     * to the same PUB job.
     *
     * ANALYZE already stamped pub_run_id onto every box.
     *
     * Do not allow mixed jobs through one CREATE call.
     */
    $pubRunId =
        (int)(
            $boxes[0][
                'pub_run_id'
            ]
            ?? 0
        );

    if ($pubRunId <= 0) {
        throw new InvalidArgumentException(
            'CREATE boxes require pub_run_id.'
        );
    }

    foreach (
        $boxes
        as $index => $box
    ) {
        if (!is_array($box)) {
            throw new InvalidArgumentException(
                "CREATE box {$index} is invalid."
            );
        }

        $boxRunId =
            (int)(
                $box[
                    'pub_run_id'
                ]
                ?? 0
            );

        if ($boxRunId <= 0) {
            throw new InvalidArgumentException(
                "CREATE box {$index} has no pub_run_id."
            );
        }

        if (
            $boxRunId !==
            $pubRunId
        ) {
            throw new InvalidArgumentException(
                'CREATE batch contains boxes from multiple PUB jobs.'
            );
        }
    }


    /*
     * Job bookkeeping.
     *
     * Expected count becomes authoritative when the user
     * actually hands selected ANALYZE boxes to CREATE.
     */
    $runRepository =
        new PdoPubRunRepository(
            $pdo
        );

    $assets =
        new PdoPubAssetRepository(
            $pdo
        );

    $runService =
        new PubRunService(
            $runRepository,
            $assets
        );

    $runService
        ->setExpectedCount(
            $pubRunId,
            count(
                $boxes
            )
        );


    /*
     * ColorFix project root.
     */
    $projectRoot =
        dirname(
            __DIR__,
            4
        );


    /*
     * ColorFix logo.
     */
    $logoPath =
        $projectRoot
        . '/brand/'
        . 'colorfix-pin-logo-compact-right-aligned-transparent.png';


    /*
     * Public origin used by the local video worker when a canonical
     * PhotoEntity image_url is relative.
     */
    $forwardedProto =
        strtolower(
            trim(
                (string)(
                    $_SERVER[
                        'HTTP_X_FORWARDED_PROTO'
                    ]
                    ?? ''
                )
            )
        );

    if (
        !in_array(
            $forwardedProto,
            [
                'http',
                'https',
            ],
            true
        )
    ) {
        $forwardedProto =
            !empty(
                $_SERVER[
                    'HTTPS'
                ]
            )
            && strtolower(
                (string)$_SERVER[
                    'HTTPS'
                ]
            ) !== 'off'
                ? 'https'
                : 'http';
    }

    $host =
        trim(
            (string)(
                $_SERVER[
                    'HTTP_HOST'
                ]
                ?? ''
            )
        );

    if ($host === '') {
        throw new RuntimeException(
            'CREATE could not determine the public host.'
        );
    }

    $publicBaseUrl =
        $forwardedProto
        . '://'
        . $host;


    /*
     * Shared CREATE infrastructure.
     */
    $tools =
        new PinterestCreatorTools();

    $videoJobs =
        new PdoVideoJobRepository(
            $pdo
        );

    $videoWorkerHealth =
        new VideoWorkerHealthService(
            $projectRoot
        );


    /*
     * Specialist creators.
     */
    $compositeCreator =
        new CompositeCreator(
            $tools,
            $projectRoot,
            $logoPath
        );

    $beforeAfterVideoCreator =
        new BeforeAfterVideoCreator(
            $assets,
            $videoJobs,
            $videoWorkerHealth,
            $projectRoot,
            $publicBaseUrl
        );

    $ideaCreator =
        new IdeaCreator(
            $tools,
            $projectRoot,
            $logoPath
        );

    $paletteCreator =
        new PaletteCreator(
            $tools,
            $projectRoot,
            $logoPath
        );


    /*
     * CREATE DEPARTMENT HEAD.
     *
     * From this point forward the Manager owns:
     *
     *   - Creator selection
     *   - PubCom readiness
     *   - PubCom preflight
     *   - unit continuation / skipping
     *   - production routing
     *   - centralized CREATE failure reporting
     */
    $manager =
        new CreateManager(
            $assets,
            $compositeCreator,
            $beforeAfterVideoCreator,
            $ideaCreator,
            $paletteCreator,
            videoJobs:
                $videoJobs,
            runService:
                $runService
        );


    /*
     * Run the CREATE batch.
     */
    $result =
        $manager->createBatch(
            $boxes
        );

    $created =
        $result['created']
        ?? [];

    $queued =
        $result['queued']
        ?? [];

    $failed =
        $result['failed']
        ?? [];

    $createdCount =
        count(
            $created
        );

    $queuedCount =
        count(
            $queued
        );

    $failedCount =
        count(
            $failed
        );


    /*
     * Record CREATE progress on the job.
     *
     * QUEUED IS NOT CREATED.
     *
     * A video job remains a durable pub_asset in pipeline_stage
     * "creating" until the worker returns and CreateManager promotes
     * the physical MP4. video-jobs/complete.php refreshes these counts
     * when that happens.
     *
     * DO NOT complete the run here.
     *
     * The same job still has:
     *
     *   PACKAGE
     *   SCHEDULE
     *   DISPATCH
     *
     * ahead of it.
     */
    $runService
        ->recordProgress(
            $pubRunId,
            $createdCount,
            $failedCount
        );


    /*
     * PubCom dispositions generated by the Manager are
     * carried inside the created / queued / failed entries.
     *
     * The endpoint does not reinterpret them.
     * It simply transports the Manager's result to the UI.
     */
    workflow_respond([
        'ok' => true,

        'pub_run_id' =>
            $pubRunId,

        'expected_count' =>
            count(
                $boxes
            ),

        'created' =>
            $created,

        'queued' =>
            $queued,

        'failed' =>
            $failed,

        'created_count' =>
            $createdCount,

        'queued_count' =>
            $queuedCount,

        'failed_count' =>
            $failedCount,
    ]);

} catch (
    InvalidArgumentException $e
) {
    workflow_respond([
        'ok' => false,
        'error' =>
            $e->getMessage(),
    ], 400);

} catch (Throwable $e) {
    workflow_respond([
        'ok' => false,
        'error' =>
            $e->getMessage(),
    ], 500);
}