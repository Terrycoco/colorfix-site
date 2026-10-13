<?php
declare(strict_types=1);
namespace App\PUB\Dispatch\Driver;

/** Structured, sanitized diagnostics that survive the one-shot driver boundary. */
interface DispatchFailureDetailProvider
{
    /** @return array<string, mixed> */
    public function dispatchFailureDetails(): array;
}
