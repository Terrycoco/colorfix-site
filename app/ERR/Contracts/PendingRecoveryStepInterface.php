<?php
declare(strict_types=1);
namespace App\ERR\Contracts;

/** A department-owned, read-only completion check for work started asynchronously. */
interface PendingRecoveryStepInterface extends RecoveryStepInterface
{
    /** Must inspect actual outcome; MUST NOT start or retry the operation. */
    public function check(ErrorContext $context): RecoveryStepResult;
}
