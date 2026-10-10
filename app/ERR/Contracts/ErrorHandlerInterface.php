<?php
declare(strict_types=1);

namespace App\ERR\Contracts;

/**
 * Department-owned recovery entrypoint.
 * Declare an ordered sequence of steps; do not execute them here.
 * ERR's RecoveryExecutor will persist a step before running it, remove
 * it after verified completion, and retain unfinished steps.
 */
interface ErrorHandlerInterface
{
    /**
     * @return list<RecoveryStepInterface>
     */
    public function steps(ErrorContext $context): array;

    /**
     * Called after the executor processes the sequence. The handler may
     * interpret results, but must not pretend that waiting work is resolved.
     *
     * @param array<string, RecoveryStepResult> $results keyed by step key
     */
    public function outcome(ErrorContext $context, array $results): ErrorHandlingResult;
}
