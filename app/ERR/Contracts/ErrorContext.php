<?php
declare(strict_types=1);

namespace App\ERR\Contracts;

/**
 * Immutable identifying information passed to departmental recovery handlers.
 * The original occurrence has already been recorded in err_log.
 */
final class ErrorContext
{
    public function __construct(
        public readonly int $logId,
        public readonly int $errNum,
        public readonly string $department,
        public readonly string $message,
        public readonly ?string $component = null,
        public readonly ?string $operation = null,
        public readonly ?string $resourceType = null,
        public readonly ?int $resourceId = null,
        public readonly array $details = [],
        public readonly ?array $bookmark = null
    ) {
        if ($logId <= 0 || $errNum <= 0) {
            throw new \InvalidArgumentException('Error log ID and error number must be positive.');
        }
        if (trim($department) === '') {
            throw new \InvalidArgumentException('Error department is required.');
        }
    }
}
