<?php
declare(strict_types=1);
namespace App\PUB\Errors;

use App\PUB\Dispatch\Driver\DispatchFailureDetailProvider;
use RuntimeException;

final class PinterestApiException extends RuntimeException implements DispatchFailureDetailProvider
{
    /** @param array<string, mixed> $diagnostics Already sanitized by caller. */
    public function __construct(string $message, private readonly array $diagnostics)
    {
        parent::__construct($message);
    }

    public function dispatchFailureDetails(): array
    {
        return $this->diagnostics;
    }
}
