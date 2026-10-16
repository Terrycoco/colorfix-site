<?php
declare(strict_types=1);

namespace App\ERR\Services;

use App\ERR\Contracts\ErrorContext;
use App\ERR\Contracts\ErrorHandlerInterface;
use App\ERR\Contracts\ErrorHandlingResult;
use App\ERR\Contracts\RecoveryStepInterface;
use App\ERR\Contracts\RecoveryStepResult;
use App\ERR\Repos\PdoErrorRepository;
use Throwable;

/**
 * Executes a department's ordered recovery steps.
 *
 * Each step is written to err_working BEFORE being attempted; completed
 * steps are deleted, while failed/waiting/unknown steps remain.
 *
 * Not a background scheduler, retry engine, or automatic handler registry.
 * The caller supplies an already resolved, trusted ErrorHandlerInterface.
 */
final class RecoveryExecutor
{
    public function __construct(
        private PdoErrorRepository $repository,
        private ?RecoveryStatusReporter $statusReporter = null
    ) {}

    public function run(ErrorContext $context, ErrorHandlerInterface $handler): ErrorHandlingResult
    {
        return $this->process($context, $handler, null);
    }

    /** Resume a waiting asynchronous operation without re-executing it. */
    public function resume(ErrorContext $context, ErrorHandlerInterface $handler, string $pendingKey): ErrorHandlingResult
    {
        return $this->process($context, $handler, $pendingKey);
    }

    private function process(ErrorContext $context, ErrorHandlerInterface $handler, ?string $pendingKey): ErrorHandlingResult
    {
        $results = [];
        $failedStep = null;
        try {
            $steps = $handler->steps($context);
            $seen = [];
            $pendingIndex = $pendingKey === null ? 0 : -1;
            foreach ($steps as $index => $step) {
                if (!$step instanceof \App\ERR\Contracts\RecoveryStepInterface) {
                    throw new \RuntimeException('A handler returned an invalid recovery step.');
                }
                $key = trim($step->key());
                if ($key === '' || strlen($key) > 100 || isset($seen[$key])) {
                    throw new \RuntimeException('Recovery step keys must be unique and nonempty.');
                }
                $seen[$key] = true;
                if ($key === $pendingKey) $pendingIndex = $index;
            }
            if ($pendingIndex < 0) throw new \RuntimeException('Outstanding step is no longer registered.');

            // Earlier steps have completed and were removed from err_working.
            // Their effects MUST NOT be repeated when an asynchronous step settles.
            for ($index = 0; $index < $pendingIndex; $index++) {
                $results[$steps[$index]->key()] = RecoveryStepResult::success('Completed earlier in this recovery.');
            }

            for ($index = $pendingIndex; $index < count($steps); $index++) {
                $step = $steps[$index];
                $key = trim($step->key());
                $failedStep = $key;
                if ($pendingKey !== null && $index === $pendingIndex) {
                    if (!$step instanceof \App\ERR\Contracts\PendingRecoveryStepInterface) {
                        throw new \RuntimeException('A waiting step has no registered completion checker.');
                    }
                    // Claim acquired by the endpoint. Never re-run the side effect.
                    $this->statusReporter?->attempting($step->description(), $key);
                    $result = $step->check($context);
                } else {
                    $this->repository->startStep($context->logId, $key, $step->description(), $context->bookmark);
                    $this->statusReporter?->attempting($step->description(), $key);
                    try {
                        $result = $step->execute($context);
                    } catch (Throwable $failure) {
                        $result = RecoveryStepResult::failed(get_class($failure) . ': ' . $failure->getMessage());
                    }
                }
                $results[$key] = $result;
                $this->statusReporter?->stepResult($step->description(), $result, $key);
                if ($result->isFinished()) {
                    $this->repository->completeStep($context->logId, $key);
                } else {
                    $this->repository->markStep($context->logId, $key, $result->status, $result->detail);
                    break;
                }
                $failedStep = null;
            }
            $outcome = $handler->outcome($context, $results);
            foreach ($results as $result) {
                if (!$result->isFinished() && $outcome->status === ErrorHandlingResult::RESOLVED) {
                    $outcome = ErrorHandlingResult::failed('Handler reported resolved despite unfinished recovery.');
                    break;
                }
            }
            $this->repository->updateHandling(
                $context->logId, $outcome->status, $outcome->detail,
                $outcome->status === ErrorHandlingResult::RESOLVED
            );
            $this->statusReporter?->outcome($outcome->status, $outcome->detail);
            return $outcome;
        } catch (Throwable $failure) {
            $detail = 'Recovery execution failed' . ($failedStep ? " at step {$failedStep}" : '')
                . ': ' . get_class($failure) . ': ' . $failure->getMessage();
            try {
                $this->repository->updateHandling($context->logId, ErrorHandlingResult::FAILED, $detail);
            } catch (Throwable) {
                error_log($detail);
            }
            $this->statusReporter?->outcome('failed', $detail);
            return ErrorHandlingResult::failed($detail);
        }
    }
}
