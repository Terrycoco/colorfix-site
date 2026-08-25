<?php
declare(strict_types=1);

use App\PUB\Analyze\AnalyzeManager;
use App\PUB\Analyze\Sources\PlaylistSourcePreparer;

use App\PUB\Analyze\Pinterest\BeforeAfterVideoAnalyzer;
use App\PUB\Analyze\Pinterest\CompositeAnalyzer;
use App\PUB\Analyze\Pinterest\IdeaAnalyzer;
use App\PUB\Analyze\Pinterest\PaletteAnalyzer;
use App\PUB\Analyze\Pinterest\YouTubeTeaserAnalyzer;
use App\PUB\Analyze\Pinterest\Support\PlaylistPaletteResolver;

use App\PUB\Analyze\YouTube\PlaylistVideoAnalyzer;

use App\PUB\Errors\PubErrorReporter;
use App\PUB\Repos\PdoPubAssetRepository;
use App\PUB\Repos\PdoPubRunRepository;
use App\PUB\Services\PubRunService;

use App\Repos\PdoPlaylistRepository;

use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReservationRelationships;
use App\REX\Services\RexReserver;
use App\REX\Services\RexTokenGenerator;

use App\Services\ViewerService;
use App\PV\PVService;

/*
 * Keep PHP's own error page out of the fetch response.
 * PUB will return JSON errors itself.
 */
ini_set('display_errors', '0');

$responseSent = false;
$errorReporter = null;

$sendJson = static function (
    int $status,
    array $payload
) use (&$responseSent): void {
    $responseSent = true;

    http_response_code($status);

    if (!headers_sent()) {
        header(
            'Content-Type: application/json; charset=UTF-8'
        );
    }

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        echo '{"ok":false,"error":"Could not encode PUB response as JSON."}';
        return;
    }

    echo $json;
};


register_shutdown_function(
    static function () use (
        &$responseSent
    ): void {
        if ($responseSent) {
            return;
        }

        $error = error_get_last();

        if (!is_array($error)) {
            return;
        }

        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
            E_RECOVERABLE_ERROR,
        ];

        if (!in_array(
            (int)($error['type'] ?? 0),
            $fatalTypes,
            true
        )) {
            return;
        }

        http_response_code(500);

        if (!headers_sent()) {
            header(
                'Content-Type: application/json; charset=UTF-8'
            );
        }

        echo json_encode(
            [
                'ok' => false,
                'error' =>
                    'PHP fatal: '
                    . (string)($error['message'] ?? 'Unknown fatal error'),
                'error_file' =>
                    (string)($error['file'] ?? ''),
                'error_line' =>
                    (int)($error['line'] ?? 0),
            ],
            JSON_UNESCAPED_SLASHES
        );
    }
);


if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    $sendJson(
        200,
        [
            'ok' => true,
        ]
    );

    exit;
}


try {
    /*
     * BOOTSTRAP
     */
    require_once __DIR__ . '/../../../autoload.php';
    require_once __DIR__ . '/../../../db.php';
    require_once __DIR__ . '/../auth.php';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $sendJson(
            405,
            [
                'ok' => false,
                'error' => 'POST only',
            ]
        );

        exit;
    }


    /*
     * REQUEST
     */
    $raw =
        file_get_contents(
            'php://input'
        )
        ?: '';

    $data =
        json_decode(
            $raw,
            true
        );

    if (!is_array($data)) {
        $sendJson(
            400,
            [
                'ok' => false,
                'error' => 'Valid JSON body required.',
            ]
        );

        exit;
    }


    $sourceType =
        strtolower(
            trim(
                (string)(
                    $data[
                        'source_type'
                    ]
                    ?? ''
                )
            )
        );

    $sourceId =
        (int)(
            $data[
                'source_id'
            ]
            ?? 0
        );

    $outputTypeRaw =
        $data[
            'output_type'
        ]
        ?? (
            is_array(
                $data[
                    'outputs'
                ]
                ?? null
            )
                ? (
                    $data[
                        'outputs'
                    ][0]
                    ?? ''
                )
                : ''
        );

    $outputType =
        strtolower(
            trim(
                (string)$outputTypeRaw
            )
        );


    /*
     * RUN MODE
     *
     * check
     *   Default Analyze click.
     *   If an exact matching job already exists, stop and
     *   return it to the UI for a New vs Overwrite decision.
     *
     * new
     *   Intentionally create another pub_run_id.
     *
     * overwrite
     *   Delete/reset one exact existing job and reuse its ID.
     */
    $runMode =
        strtolower(
            trim(
                (string)(
                    $data[
                        'run_mode'
                    ]
                    ?? 'check'
                )
            )
        );

    $overwritePubRunId =
        (int)(
            $data[
                'overwrite_pub_run_id'
            ]
            ?? 0
        );


    if ($sourceType === '') {
        $sendJson(
            400,
            [
                'ok' => false,
                'error' => 'source_type required',
            ]
        );
        exit;
    }

    if ($sourceId <= 0) {
        $sendJson(
            400,
            [
                'ok' => false,
                'error' => 'Valid source_id required',
            ]
        );
        exit;
    }

    if ($outputType === '') {
        $sendJson(
            400,
            [
                'ok' => false,
                'error' => 'output_type required',
            ]
        );
        exit;
    }

    if (
        !in_array(
            $runMode,
            [
                'check',
                'new',
                'overwrite',
            ],
            true
        )
    ) {
        $sendJson(
            400,
            [
                'ok' => false,
                'error' => 'Invalid run_mode.',
            ]
        );
        exit;
    }


    /*
     * Request completeness only.
     *
     * The endpoint may reject an incomplete form, but it does
     * not decide what ANALYZE does with a valid overwrite.
     */
    if (
        $runMode === 'overwrite'
        && $overwritePubRunId <= 0
    ) {
        $sendJson(
            400,
            [
                'ok' => false,
                'error' =>
                    'overwrite_pub_run_id required for overwrite.',
            ]
        );
        exit;
    }


    /*
     * DATABASE
     *
     * db.php creates $pdo directly.
     */
    if (
        !isset($pdo)
        || !$pdo instanceof PDO
    ) {
        throw new RuntimeException(
            'Database connection unavailable.'
        );
    }


    $projectRoot =
        dirname(
            __DIR__,
            4
        );

    $errorReporter =
        new PubErrorReporter(
            $projectRoot
            . '/app/PUB/Errors/pub_errors.log'
        );


    /*
     * ANALYZE INFRASTRUCTURE
     *
     * The endpoint wires the department together.
     * It does NOT make ANALYZE-stage workflow decisions.
     */
    $runRepository =
        new PdoPubRunRepository(
            $pdo
        );

    $assetRepository =
        new PdoPubAssetRepository(
            $pdo
        );

    $runService =
        new PubRunService(
            $runRepository,
            $assetRepository
        );


    /*
     * SOURCE PREPARATION
     */
    $playlistRepository =
        new PdoPlaylistRepository(
            $pdo
        );

$playlistSourcePreparer = new PlaylistSourcePreparer(
    new PdoPlaylistRepository($pdo),
    new PVService($pdo)
);


    /*
     * PALETTE / REX SUPPORT
     */
    $rexRepository =
        new PdoRexReservationRepository(
            $pdo
        );

    $rexRelationships =
        new RexReservationRelationships(
            $rexRepository
        );

    $rexReserver =
        new RexReserver(
            $rexRepository,
            new RexTokenGenerator()
        );

    $viewerService =
        new ViewerService(
            $pdo
        );

    $playlistPaletteResolver =
        new PlaylistPaletteResolver(
            $rexRepository,
            $rexRelationships,
            $rexReserver,
            $viewerService
        );

    $paletteAnalyzer =
        new PaletteAnalyzer(
            $playlistPaletteResolver
        );


    /*
     * ANALYZE MANAGER
     */
    $manager =
        new AnalyzeManager(
            $runService,
            $playlistSourcePreparer,
            new CompositeAnalyzer(),
            new BeforeAfterVideoAnalyzer(),
            new IdeaAnalyzer(),
            $paletteAnalyzer,
            new PlaylistVideoAnalyzer(),
            new YouTubeTeaserAnalyzer()
        );


    /*
     * ANALYZE
     *
     * One call enters the department. From here,
     * AnalyzeManager owns the job decision, source
     * preparation, PubCom authorization, specialist routing,
     * and sealed boxes.
     */
    $result =
        $manager->analyze(
            $sourceType,
            $sourceId,
            $outputType,
            $runMode,
            $overwritePubRunId
        );


    /*
     * EXISTING JOB RESPONSE
     *
     * The Manager made the decision to stop.
     * The endpoint only translates that decision to HTTP.
     */
    if (
        ($result['code'] ?? '') ===
        'existing_pub_run'
    ) {
        $existingRunId =
            (int)(
                $result['pub_run_id']
                ?? 0
            );

        $sendJson(
            409,
            [
                'ok' =>
                    false,

                'code' =>
                    'existing_pub_run',

                'error' =>
                    "PUB job #{$existingRunId} already exists for this source and output.",

                'existing_run' =>
                    $result['existing_run']
                    ?? null,

                'can_overwrite' =>
                    (bool)(
                        $result['can_overwrite']
                        ?? false
                    ),

                'blocking_assets' =>
                    $result['blocking_assets']
                    ?? [],

                'asset_count' =>
                    (int)(
                        $result['asset_count']
                        ?? 0
                    ),
            ]
        );

        exit;
    }


    $boxes =
        is_array(
            $result['boxes']
            ?? null
        )
            ? array_values(
                $result['boxes']
            )
            : [];

    $failed =
        is_array(
            $result['failed']
            ?? null
        )
            ? array_values(
                $result['failed']
            )
            : [];


    /*
     * PUBCOM
     *
     * The Manager has already interpreted worker signals
     * into dispositions. The endpoint only transports them
     * to the frontend.
     */
    $pubCom =
        is_array(
            $result['pubcom']
            ?? null
        )
            ? array_values(
                $result['pubcom']
            )
            : [];


    /*
     * RESPONSE
     *
     * Preserve the current frontend contract.
     */
    $sendJson(
        200,
        [
            'ok' =>
                true,

            'pub_run_id' =>
                (int)(
                    $result['pub_run_id']
                    ?? 0
                ),

            'run_mode' =>
                (string)(
                    $result['run_mode']
                    ?? $runMode
                ),

            'overwrote_existing_job' =>
                (bool)(
                    $result['overwrote_existing_job']
                    ?? false
                ),

            'deleted_asset_count' =>
                (int)(
                    $result['deleted_asset_count']
                    ?? 0
                ),

            'source_type' =>
                (string)(
                    $result['source_type']
                    ?? $sourceType
                ),

            'source_id' =>
                (int)(
                    $result['source_id']
                    ?? $sourceId
                ),

            'output_type' =>
                (string)(
                    $result['output_type']
                    ?? $outputType
                ),

            'box_count' =>
                count(
                    $boxes
                ),

            'failed_count' =>
                count(
                    $failed
                ),

            'boxes' =>
                $boxes,

            'failed' =>
                $failed,

            'pubcom' =>
                $pubCom,
        ]
    );

} catch (Throwable $e) {
    $failure = null;

    if (
        $errorReporter instanceof
        PubErrorReporter
    ) {
        $failure =
            $errorReporter->report(
                $e,
                [
                    'stage' =>
                        'analyze',

                    'pub_run_id' =>
                        $overwritePubRunId >
                        0
                            ? $overwritePubRunId
                            : null,

                    'source_type' =>
                        $sourceType
                        ?? null,

                    'source_id' =>
                        $sourceId
                        ?? null,

                    'code' =>
                        'analyze_failure',
                ]
            );
    }

    $sendJson(
        500,
        [
            'ok' =>
                false,

            'error' =>
                $failure[
                    'error'
                ]
                ?? $e->getMessage(),

            'code' =>
                $failure[
                    'code'
                ]
                ?? 'analyze_failure',

            'pub_run_id' =>
                $failure[
                    'pub_run_id'
                ]
                ?? null,

            'error_class' =>
                get_class(
                    $e
                ),

            'error_file' =>
                $e->getFile(),

            'error_line' =>
                $e->getLine(),
        ]
    );
}