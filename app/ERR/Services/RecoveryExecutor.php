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

    public function run(
        ErrorContext $context,
        ErrorHandlerInterface $handler
    ): ErrorHandlingResult {
        $results = [];
        $failedStep = null;

        try {
            $steps = $handler->steps($context);
            $seenKeys = [];

            foreach ($steps as $step) {
                if (!$step instanceof RecoveryStepInterface) {
                    throw new \RuntimeException(
                        'A handler returned an invalid recovery step.'
                    );
                }
                $key = trim($step->key());
                if ($key === '' || strlen($key) > 100 || isset($seenKeys[$key])) {
                    throw new \RuntimeException(
                        'Recovery step keys must be unique, nonempty, and at most 100 characters.'
                    );
                }
                $seenKeys[$key] = true;
            }

            foreach ($steps as $step) {
                $key = trim($step->key());
                $failedStep = $key;

                // Never execute if we cannot record that execution first.
                $this->repository->startStep(
                    $context->logId,
                    $key,
                    $step->description(),
                    $context->bookmark
                );

                $this->statusReporter?->attempting($step->description());

                try {
                    $result = $step->execute($context);
                } catch (Throwable $failure) {
                    $result = RecoveryStepResult::failed(
                        get_class($failure) . ': ' . $failure->getMessage()
                    );
                }

                $results[$key] = $result;
                $this->statusReporter?->stepResult($step->description(), $result);

                if ($result->isFinished()) {
                    // If deletion fails, leave the row for reconciliation.
                    $this->repository->completeStep($context->logId, $key);
                } else {
                    $this->repository->markStep(
                        $context->logId,
                        $key,
                        $result->status,
                        $result->detail
                    );
                    // Ordered dependent steps must not run after a blocker.
                    break;
                }

                $failedStep = null;
            }

            $outcome = $handler->outcome($context, $results);

            // The handler cannot claim resolution while a step is unfinished.
            foreach ($results as $result) {
                if (!$result->isFinished()
                    && $outcome->status === ErrorHandlingResult::RESOLVED) {
                    $outcome = ErrorHandlingResult::failed(
                        'Handler reported resolved despite an unfinished recovery step.'
                    );
                    break;
                }
            }

            $this->repository->updateHandling(
                $context->logId,
                $outcome->status,
                $outcome->detail,
                $outcome->status === ErrorHandlingResult::RESOLVED
            );

            $this->statusReporter?->outcome($outcome->status, $outcome->detail);
            return $outcome;
        } catch (Throwable $failure) {
            $detail = 'Recovery execution failed'
                . ($failedStep === null ? '' : " at step {$failedStep}")
                . ': ' . get_class($failure)
                . ': ' . $failure->getMessage();

            // Do not recursively report an error from the error system.
            // If DB bookkeeping fails, the original working row remains.
            try {
                $this->repository->updateHandling(
                    $context->logId,
                    ErrorHandlingResult::FAILED,
                    $detail
                );
            } catch (Throwable) {
                error_log($detail);
            }

            $this->statusReporter?->outcome('failed', $detail);
            return ErrorHandlingResult::failed($detail);
        }
    }
}
