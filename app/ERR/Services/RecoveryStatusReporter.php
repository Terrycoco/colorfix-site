<?php
declare(strict_types=1);

namespace App\ERR\Services;

use App\ERR\Contracts\RecoveryStepResult;

/** ERR owns the visual reporting for a recovery attempt, not the department. */
final class RecoveryStatusReporter
{
    public function __construct(
        private ErrorStatusBroadcaster $broadcaster,
        private string $operationId,
        private string $title
    ) {}

    public function attempting(string $description): void
    {
        $this->broadcaster->broadcast($this->operationId, $this->title, 'attempting', $description);
    }

    public function stepResult(string $description, RecoveryStepResult $result): void
    {
        $status = match ($result->status) {
            RecoveryStepResult::SUCCESS => 'success',
            RecoveryStepResult::WAITING => 'waiting',
            default => 'failed',
        };
        // The next step, if any, replaces this message in the same window.
        $this->broadcaster->broadcast(
            $this->operationId, $this->title, $status,
            $result->detail !== '' ? $result->detail : $description
        );
    }

    public function outcome(string $status, string $detail): void
    {
        $visualStatus = match ($status) {
            'resolved' => 'success',
            'waiting' => 'waiting',
            default => 'failed',
        };
        $this->broadcaster->broadcast(
            $this->operationId, $this->title, $visualStatus,
            $detail !== '' ? $detail : 'Recovery finished.'
        );
    }
}
