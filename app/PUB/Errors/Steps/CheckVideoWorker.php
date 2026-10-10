<?php
declare(strict_types=1);

namespace App\PUB\Errors\Steps;

use App\ERR\Contracts\ErrorContext;
use App\ERR\Contracts\RecoveryStepInterface;
use App\ERR\Contracts\RecoveryStepResult;
use App\PUB\Create\Video\VideoWorkerHealthService;

/** Checks the existing worker heartbeat. Does not start or restart a process. */
final class CheckVideoWorker implements RecoveryStepInterface
{
    public function __construct(
        private VideoWorkerHealthService $health
    ) {}

    public function key(): string
    {
        return 'check_video_worker';
    }

    public function description(): string
    {
        return 'Check video listener heartbeat';
    }

    public function execute(ErrorContext $context): RecoveryStepResult
    {
        $status = $this->health->status();
        if (!empty($status['alive'])) {
            return RecoveryStepResult::success('Video worker heartbeat is healthy.');
        }

        $reason = (string)($status['reason'] ?? 'unknown');
        return RecoveryStepResult::waiting(
            'Video listener unavailable (' . $reason . '). On the Mac, from the ColorFix project root, run: ./scripts/start-pub-video-worker.sh; then retry.'
        );
    }
}
