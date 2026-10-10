<?php
declare(strict_types=1);

namespace App\ERR\Contracts;

/**
 * Overall handler outcome, separate from whether the underlying incident
 * is actually resolved (e.g. Pinterest may still need reauthorization).
 */
final class ErrorHandlingResult
{
    public const RESOLVED = 'resolved';
    public const WAITING = 'waiting';
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly string $detail
    ) {}

    public static function resolved(string $detail = ''): self
    {
        return new self(self::RESOLVED, $detail);
    }

    public static function waiting(string $detail): self
    {
        return new self(self::WAITING, $detail);
    }

    public static function failed(string $detail): self
    {
        return new self(self::FAILED, $detail);
    }
}
