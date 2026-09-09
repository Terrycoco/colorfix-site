<?php
declare(strict_types=1);

/*
 * PUB DISPATCH DRIVER — SERVER CLI ENTRYPOINT
 *
 * This file is intentionally tiny. All driver behavior lives under:
 *
 *   App\PUB\Dispatch\Driver
 *
 * Usage:
 *   php scripts/pub-dispatch-driver.php run <encoded-job>
 *   php scripts/pub-dispatch-driver.php settle <encoded-job> <route-exit-code>
 *
 * SETTLE is called unconditionally by DispatchDriverLauncher after the route
 * process exits. It is the final lifecycle guard that prevents a detached
 * process failure from silently stranding an asset at SHIPPING.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(
        STDERR,
        "PUB Dispatch driver is CLI-only.\n"
    );

    exit(2);
}


$projectRoot =
    dirname(
        __DIR__
    );


$mode =
    strtolower(
        trim(
            (string)(
                $argv[1]
                ?? ''
            )
        )
    );

$encodedJob =
    trim(
        (string)(
            $argv[2]
            ?? ''
        )
    );

$routeExitCode =
    isset(
        $argv[3]
    )
        ? (int)$argv[3]
        : null;


/*
 * Keep bootstrap inside the catch boundary.
 *
 * A Throwable raised while loading application bootstrap must produce a
 * non-zero process exit so the detached shell can execute its settlement
 * pass instead of silently abandoning the box.
 */
try {
    require_once $projectRoot
        . '/api/autoload.php';

    require_once $projectRoot
        . '/api/db.php';


    /*
     * api/db.php owns the ordinary application PHP error log. A detached
     * Dispatch driver needs its diagnostics in the dedicated driver log.
     */
    ini_set(
        'log_errors',
        '1'
    );

    ini_set(
        'error_log',
        $projectRoot
        . '/app/PUB/Errors/pub_dispatch_driver.log'
    );


    $job =
        App\PUB\Dispatch\Driver\DispatchDriverJob::decode(
            $encodedJob
        );


    if ($mode === 'settle') {
        if ($routeExitCode === null) {
            throw new RuntimeException(
                'Dispatch driver settlement requires the route exit code.'
            );
        }


        $desk =
            new App\PUB\Dispatch\Driver\DispatchDesk(
                $pdo,
                $projectRoot
            );


        if (
            $routeExitCode === 124
            || $routeExitCode === 137
        ) {
            $desk->reportTimeout(
                $job
            );

        } elseif ($routeExitCode === 0) {
            /*
             * On the normal success path the asset is already SHIPPED and
             * failShipment() treats this late failure report as a no-op.
             *
             * If the route process exited zero without reporting success,
             * however, this converts the stranded SHIPPING row to ERROR.
             */
            $desk->reportFailure(
                $job,
                'dispatch_driver_unsettled',
                'Dispatch driver process exited without reporting a final shipment result.'
            );

        } else {
            /*
             * On a normally handled route failure, the Runner has already
             * moved the asset to ERROR / DISPATCH. failShipment() is
             * idempotent and leaves the original, more specific error intact.
             *
             * If the process died before the Runner could report, this is the
             * fallback that settles the stranded SHIPPING row.
             */
            $desk->reportFailure(
                $job,
                'dispatch_driver_process_failed',
                'Dispatch driver process exited unexpectedly with code '
                . $routeExitCode
                . ' before reporting a final shipment result.'
            );
        }


        exit(0);
    }


    if ($mode !== 'run') {
        throw new RuntimeException(
            'Dispatch driver mode must be run or settle.'
        );
    }


    $runner =
        new App\PUB\Dispatch\Driver\DispatchDriverRunner(
            $pdo,
            $projectRoot
        );


    $result =
        $runner->run(
            $job
        );


    if (
        empty(
            $result[
                'ok'
            ]
        )
    ) {
        $message =
            trim(
                (string)(
                    $result[
                        'error'
                    ]
                    ?? 'Dispatch driver reported failure.'
                )
            );


        fwrite(
            STDERR,
            'Dispatch driver failed for asset #'
            . $job->pubAssetId()
            . ': '
            . $message
            . "\n"
        );
    }


    exit(
        !empty(
            $result[
                'ok'
            ]
        )
            ? 0
            : 1
    );

} catch (Throwable $e) {
    error_log(
        'PUB Dispatch driver bootstrap failed: '
        . $e->getMessage()
    );

    fwrite(
        STDERR,
        'PUB Dispatch driver bootstrap failed: '
        . $e->getMessage()
        . "\n"
    );

    exit(1);
}
