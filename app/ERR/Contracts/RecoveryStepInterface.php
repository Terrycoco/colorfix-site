<?php
declare(strict_types=1);

namespace App\ERR\Contracts;

/**
 * One named, independently verifiable recovery operation.
 *
 * Steps must be safe to re-enter, or inspect the real-world state before
 * repeating an action whose previous outcome is uncertain.
 * Exceptions are caught by the future shared RecoveryExecutor.
 */
interface RecoveryStepInterface
{
    /** Stable identifier used by err_working, e.g. "check_worker". */
    public function key(): string;

    /** Human-readable description of the action being attempted. */
    public function description(): string;

    /** Execute once; return an explicit outcome. */
    public function execute(ErrorContext $context): RecoveryStepResult;
}
