<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Driver;

use App\Lib\EnvLoader;
use RuntimeException;

/**
 * DRIVER LAUNCHER
 *
 * Creates one detached server-side process for one DispatchDriverJob.
 * There are no idle drivers. Each process handles one assignment and dies.
 *
 * A hard OS-level timeout wraps every driver.
 *
 * IMPORTANT LIFECYCLE GUARANTEE:
 *
 * After the route process exits for ANY reason, a fresh settlement pass is
 * always invoked. DispatchManager::failShipment() is deliberately idempotent:
 *
 *   - SHIPPED stays SHIPPED
 *   - ERROR / DISPATCH stays ERROR / DISPATCH
 *   - a stranded SHIPPING row becomes ERROR / DISPATCH
 *
 * This means a normal route failure, an unexpected non-zero exit, or even a
 * route process that exits zero without reporting a result cannot silently
 * leave the box sitting at SHIPPING.
 */
final class DispatchDriverLauncher
{
    private const RUNNER_SCRIPT = 'scripts/pub-dispatch-driver.php';


    public function __construct(
        private string $projectRoot
    ) {
        $this->projectRoot =
            rtrim(
                trim(
                    $this->projectRoot
                ),
                DIRECTORY_SEPARATOR
            );


        if ($this->projectRoot === '') {
            throw new RuntimeException(
                'Dispatch Driver Launcher requires project root.'
            );
        }
    }


    /**
     * Ask the server for one one-shot driver and hand it the complete job
     * ticket at launch time.
     */
    public function requestDriver(
        DispatchDriverJob $job
    ): DispatchDriverHandle {
        $this->assertAvailable();


        $php =
            $this->phpBinary();

        $timeout =
            $this->timeoutBinary();

        $runner =
            $this->runnerScript();

        $ticket =
            $job->encode();

        $log =
            $this->projectRoot
            . '/app/PUB/Errors/pub_dispatch_driver.log';


        /*
         * The detached shell wrapper is the driver's process handle.
         *
         * The route process is bounded by GNU timeout. Regardless of how the
         * route process exits, the wrapper then invokes the same tiny CLI
         * entrypoint in SETTLE mode.
         *
         * SETTLE is intentionally unconditional. If the route already
         * reported success or failure, DispatchManager leaves that durable
         * state alone. If the route exited without settling the asset, SETTLE
         * converts the stranded SHIPPING row into ERROR / DISPATCH.
         */
        $inner =
            escapeshellarg(
                $timeout
            )
            . ' --signal=TERM --kill-after=10s '
            . (int)$job->timeoutSeconds()
            . 's '
            . escapeshellarg(
                $php
            )
            . ' '
            . escapeshellarg(
                $runner
            )
            . ' run '
            . escapeshellarg(
                $ticket
            )
            . '; code=$?; '
            . escapeshellarg(
                $php
            )
            . ' '
            . escapeshellarg(
                $runner
            )
            . ' settle '
            . escapeshellarg(
                $ticket
            )
            . ' "$code"; '
            . 'settle_code=$?; '
            . 'if [ "$settle_code" -ne 0 ]; then '
            . 'echo "Dispatch settlement pass failed with exit code $settle_code." >&2; '
            . 'fi';


        $command =
            'nohup sh -c '
            . escapeshellarg(
                $inner
            )
            . ' >> '
            . escapeshellarg(
                $log
            )
            . ' 2>&1 < /dev/null & echo $!';


        $output = [];
        $exitCode = 0;


        exec(
            $command,
            $output,
            $exitCode
        );


        $pid =
            isset(
                $output[0]
            )
                ? (int)trim(
                    (string)$output[0]
                )
                : 0;


        if (
            $exitCode !== 0
            || $pid <= 0
        ) {
            throw new RuntimeException(
                'Dispatch could not start a background driver process.'
            );
        }


        return new DispatchDriverHandle(
            $pid,
            $job->pubAssetId()
        );
    }


    /**
     * Fail before accepting an asynchronous route if this server cannot
     * create bounded detached PHP processes.
     */
    public function assertAvailable(): void
    {
        if (!function_exists('exec')) {
            throw new RuntimeException(
                'Dispatch drivers are unavailable because PHP exec() is disabled.'
            );
        }


        $disabled =
            array_map(
                'trim',
                explode(
                    ',',
                    (string)ini_get(
                        'disable_functions'
                    )
                )
            );


        if (in_array(
            'exec',
            $disabled,
            true
        )) {
            throw new RuntimeException(
                'Dispatch drivers are unavailable because PHP exec() is disabled.'
            );
        }


        if (!is_file(
            $this->runnerScript()
        )) {
            throw new RuntimeException(
                'Dispatch driver CLI entrypoint is missing.'
            );
        }


        $this->phpBinary();
        $this->timeoutBinary();
    }


    private function runnerScript(): string
    {
        return $this->projectRoot
            . '/'
            . self::RUNNER_SCRIPT;
    }


    private function phpBinary(): string
    {
        $configured =
            trim(
                (string)(
                    EnvLoader::get(
                        'PUB_DISPATCH_PHP_BINARY'
                    )
                    ?? ''
                )
            );


        if ($configured !== '') {
            if (!is_executable($configured)) {
                throw new RuntimeException(
                    'Configured PUB_DISPATCH_PHP_BINARY is not executable.'
                );
            }


            return $configured;
        }


        $resolved =
            $this->commandPath(
                'php'
            );


        if ($resolved === null) {
            throw new RuntimeException(
                'Dispatch Driver Launcher could not locate server PHP CLI. Set PUB_DISPATCH_PHP_BINARY in .env.local.'
            );
        }


        return $resolved;
    }


    private function timeoutBinary(): string
    {
        $configured =
            trim(
                (string)(
                    EnvLoader::get(
                        'PUB_DISPATCH_TIMEOUT_BINARY'
                    )
                    ?? ''
                )
            );


        if ($configured !== '') {
            if (!is_executable($configured)) {
                throw new RuntimeException(
                    'Configured PUB_DISPATCH_TIMEOUT_BINARY is not executable.'
                );
            }


            return $configured;
        }


        $resolved =
            $this->commandPath(
                'timeout'
            );


        if ($resolved === null) {
            throw new RuntimeException(
                'Dispatch Driver Launcher requires the server timeout command. Set PUB_DISPATCH_TIMEOUT_BINARY in .env.local if it is installed outside PATH.'
            );
        }


        return $resolved;
    }


    private function commandPath(
        string $command
    ): ?string {
        $output = [];
        $exitCode = 0;


        exec(
            'command -v '
            . escapeshellarg(
                $command
            )
            . ' 2>/dev/null',
            $output,
            $exitCode
        );


        if ($exitCode !== 0) {
            return null;
        }


        $path =
            trim(
                (string)(
                    $output[0]
                    ?? ''
                )
            );


        if (
            $path === ''
            || !is_executable(
                $path
            )
        ) {
            return null;
        }


        return $path;
    }
}
