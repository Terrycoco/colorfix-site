<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../../autoload.php';
require_once __DIR__ . '/../../../../db.php';
require_once __DIR__ . '/../../project-workflow/_helpers.php';
require_once __DIR__ . '/_worker_auth.php';

use App\PUB\Create\CreateManager;
use App\PUB\Create\Pinterest\BeforeAfterVideoCreator;
use App\PUB\Create\Pinterest\CompositeCreator;
use App\PUB\Create\Pinterest\IdeaCreator;
use App\PUB\Create\Pinterest\PaletteCreator;
use App\PUB\Create\Pinterest\Support\PinterestCreatorTools;
use App\PUB\Create\Video\PdoVideoJobRepository;
use App\PUB\Create\Video\VideoWorkerHealthService;
use App\PUB\Errors\PubErrorReporter;
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

    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        throw new InvalidArgumentException(
            'JSON body required.'
        );
    }

    $payload = json_decode(
        $raw,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!is_array($payload)) {
        throw new InvalidArgumentException(
            'Valid JSON body required.'
        );
    }

    $jobId =
        (int)(
            $payload[
                'pub_video_job_id'
            ]
            ?? 0
        );

    $status =
        strtolower(
            trim(
                (string)(
                    $payload[
                        'status'
                    ]
                    ?? ''
                )
            )
        );

    if ($jobId <= 0) {
        throw new InvalidArgumentException(
            'pub_video_job_id required.'
        );
    }

    if (
        !in_array(
            $status,
            [
                'complete',
                'failed',
            ],
            true
        )
    ) {
        throw new InvalidArgumentException(
            'status must be complete or failed.'
        );
    }

    $outputRelPath =
        isset(
            $payload[
                'output_rel_path'
            ]
        )
            ? trim(
                (string)$payload[
                    'output_rel_path'
                ]
            )
            : null;

    $outputFileSizeBytes =
        isset(
            $payload[
                'output_file_size_bytes'
            ]
        )
            ? (int)$payload[
                'output_file_size_bytes'
            ]
            : null;

    $errorMessage =
        isset(
            $payload[
                'error_message'
            ]
        )
            ? trim(
                (string)$payload[
                    'error_message'
                ]
            )
            : null;

    if (
        $status === 'complete'
        && ($outputRelPath ?? '') === ''
    ) {
        throw new InvalidArgumentException(
            'output_rel_path required for completed jobs.'
        );
    }

    /*
     * CREATE DEPARTMENT COMPOSITION ROOT.
     *
     * The video worker does not promote files into PUB by itself.
     * It reports a completed working file back to CreateManager.
     */
    $projectRoot =
        dirname(
            __DIR__,
            5
        );

    $logoPath =
        $projectRoot
        . '/brand/'
        . 'colorfix-pin-logo-compact-right-aligned-transparent.png';

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

    $assets =
        new PdoPubAssetRepository(
            $pdo
        );

    $videoJobs =
        new PdoVideoJobRepository(
            $pdo
        );

    $videoWorkerHealth =
        new VideoWorkerHealthService(
            $projectRoot
        );

    $runRepository =
        new PdoPubRunRepository(
            $pdo
        );

    $runService =
        new PubRunService(
            $runRepository,
            $assets
        );

    $tools =
        new PinterestCreatorTools();

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

    $result =
        $manager->settleVideoJob(
            $jobId,
            $status,
            $outputRelPath,
            $outputFileSizeBytes,
            $errorMessage
        );

    workflow_respond([
        'ok' => true,
        'result' =>
            $result,
        'job' =>
            $result[
                'job'
            ]
            ?? null,
        'asset' =>
            $result[
                'asset'
            ]
            ?? null,
        'run_progress' =>
            $result[
                'run_progress'
            ]
            ?? null,
    ]);

} catch (
    InvalidArgumentException
    | JsonException $e
) {
    workflow_respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 400);

} catch (Throwable $e) {
    $errorReporter =
        new PubErrorReporter(
            dirname(
                __DIR__,
                5
            )
            . '/app/PUB/Errors/pub_errors.log'
        );

    $errorReporter->report(
        $e,
        [
            'stage' =>
                'create',

            'code' =>
                'video_job_settlement_failure',

            'pub_video_job_id' =>
                isset($jobId)
                    ? $jobId
                    : null,
        ]
    );

    workflow_respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}