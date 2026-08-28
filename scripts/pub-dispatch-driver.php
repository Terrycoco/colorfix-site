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
 *   php scripts/pub-dispatch-driver.php timeout <encoded-job>
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


require_once $projectRoot
    . '/api/autoload.php';

require_once $projectRoot
    . '/api/db.php';


use App\PUB\Dispatch\Driver\DispatchDesk;
use App\PUB\Dispatch\Driver\DispatchDriverJob;
use App\PUB\Dispatch\Driver\DispatchDriverRunner;


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


try {
    $job =
        DispatchDriverJob::decode(
            $encodedJob
        );


    if ($mode === 'timeout') {
        $desk =
            new DispatchDesk(
                $pdo,
                $projectRoot
            );


        $desk->reportTimeout(
            $job
        );


        exit(0);
    }


    if ($mode !== 'run') {
        throw new RuntimeException(
            'Dispatch driver mode must be run or timeout.'
        );
    }


    $runner =
        new DispatchDriverRunner(
            $pdo,
            $projectRoot
        );


    $result =
        $runner->run(
            $job
        );


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
        $e->getMessage()
        . "\n"
    );

    exit(1);
}
