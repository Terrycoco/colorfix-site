<?php
declare(strict_types=1);

namespace App\ERR\Contracts;

/**
 * Outcome of one recovery step.
 * Only SUCCESS / SKIPPED are considered finished; the executor can remove
 * their err_working rows. WAITING / FAILED / UNKNOWN must remain visible.
 */
final class RecoveryStepResult
{
    public const SUCCESS = 'success';
    public const SKIPPED = 'skipped';
    public const WAITING = 'waiting';
    public const FAILED = 'failed';
    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $status,
        public readonly string $detail
    ) {}

    public static function success(string $detail = ''): self
    {
        return new self(self::SUCCESS, $detail);
    }

    public static function skipped(string $detail = ''): self
    {
        return new self(self::SKIPPED, $detail);
    }

    public static function waiting(string $detail): self
    {
        return new self(self::WAITING, $detail);
    }

    public static function failed(string $detail): self
    {
        return new self(self::FAILED, $detail);
    }

    public static function unknown(string $detail): self
    {
        return new self(self::UNKNOWN, $detail);
    }

    public function isFinished(): bool
    {
        return $this->status === self::SUCCESS
            || $this->status === self::SKIPPED;
    }
}
