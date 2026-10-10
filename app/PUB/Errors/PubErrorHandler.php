<?php
declare(strict_types=1);

namespace App\PUB\Errors;

use App\ERR\Contracts\ErrorContext;
use App\ERR\Contracts\ErrorHandlerInterface;
use App\ERR\Contracts\ErrorHandlingResult;
use App\ERR\Contracts\RecoveryStepResult;
use App\PUB\Create\Video\VideoWorkerHealthService;
use App\PUB\Errors\Steps\CheckVideoWorker;

/**
 * PUB recovery orchestration. For now, only the VIDEO_WORKER_UNAVAILABLE
 * registration is supported. Existing PubErrorReporter stays in service
 * until its callers have been reviewed and migrated one by one.
 */
final class PubErrorHandler implements ErrorHandlerInterface
{
    public function __construct(
        private VideoWorkerHealthService $workerHealth
    ) {}

    public function steps(ErrorContext $context): array
    {
        if ($context->department !== 'PUB' || $context->errNum !== 4001) {
            throw new \InvalidArgumentException('Unsupported PUB recovery error.');
        }

        return [new CheckVideoWorker($this->workerHealth)];
    }

    public function outcome(ErrorContext $context, array $results): ErrorHandlingResult
    {
        $result = $results['check_video_worker'] ?? null;
        if (!$result instanceof RecoveryStepResult) {
            return ErrorHandlingResult::failed('Worker health check did not execute.');
        }

        return match ($result->status) {
            RecoveryStepResult::SUCCESS => ErrorHandlingResult::resolved($result->detail),
            RecoveryStepResult::WAITING => ErrorHandlingResult::waiting($result->detail),
            default => ErrorHandlingResult::failed($result->detail ?: 'Worker health check failed.'),
        };
    }
}
