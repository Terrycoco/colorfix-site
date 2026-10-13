<?php
declare(strict_types=1);

namespace App\ERR\DTOs;

use InvalidArgumentException;

/**
 * Immutable, JSON-compatible error report supplied by a department to ERR.
 *
 * Callers MUST redact secrets before building this object. In particular,
 * never include bearer tokens, signed upload URLs, or upload credentials.
 * This DTO validates its shape but is not a secret-scrubbing service.
 *
 * toArray() uses the keys accepted by ErrorManager::reportPackageAndHandle().
 */
final class ErrorReport
{
    public function __construct(
        public readonly string $department,
        public readonly string $message,
        public readonly int $fallbackCfErrNum,
        public readonly ?string $component = null,
        public readonly ?string $operation = null,
        public readonly ?string $resourceType = null,
        public readonly ?int $resourceId = null,
        public readonly ?string $provider = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $extErrNum = null,
        public readonly ?string $nativeMessage = null,
        public readonly ?string $technicalDetail = null,
        public readonly array $context = [],
        public readonly ?array $bookmark = null,
        public readonly ?string $executionContext = null
    ) {
        if (trim($department) === '' || trim($message) === '') {
            throw new InvalidArgumentException('ERR report requires department and message.');
        }
        if ($fallbackCfErrNum <= 0) {
            throw new InvalidArgumentException('ERR report requires a positive fallbackCfErrNum.');
        }
        if ($resourceId !== null && $resourceId <= 0) {
            throw new InvalidArgumentException('ERR report resourceId must be positive when supplied.');
        }
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('ERR report httpStatus must be an HTTP response status (100–599).');
        }
        if ($executionContext !== null && !in_array($executionContext, ['background', 'interactive'], true)) {
            throw new InvalidArgumentException('ERR report executionContext must be background or interactive.');
        }
        self::assertJsonValue($context, 'context');
        if ($bookmark !== null) {
            self::assertJsonValue($bookmark, 'bookmark');
        }
        if (array_key_exists('execution_context', $context) && $executionContext !== null
            && $context['execution_context'] !== $executionContext) {
            throw new InvalidArgumentException('ERR report execution_context conflicts with context.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $context = $this->context;
        if ($this->executionContext !== null) {
            $context['execution_context'] = $this->executionContext;
        }
        return [
            'department' => $this->department,
            'message' => $this->message,
            'fallback_cf_err_num' => $this->fallbackCfErrNum,
            'component' => $this->component,
            'operation' => $this->operation,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'provider' => $this->provider,
            'http_status' => $this->httpStatus,
            'ext_err_num' => $this->extErrNum,
            'native_message' => $this->nativeMessage,
            'technical_detail' => $this->technicalDetail,
            'context' => $context,
            'bookmark' => $this->bookmark,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            department: self::requiredString($data, 'department'),
            message: self::requiredString($data, 'message'),
            fallbackCfErrNum: self::requiredInt($data, 'fallback_cf_err_num'),
            component: self::optionalString($data, 'component'),
            operation: self::optionalString($data, 'operation'),
            resourceType: self::optionalString($data, 'resource_type'),
            resourceId: self::optionalInt($data, 'resource_id'),
            provider: self::optionalString($data, 'provider'),
            httpStatus: self::optionalInt($data, 'http_status'),
            extErrNum: self::optionalString($data, 'ext_err_num'),
            nativeMessage: self::optionalString($data, 'native_message'),
            technicalDetail: self::optionalString($data, 'technical_detail'),
            context: self::optionalArray($data, 'context') ?? [],
            bookmark: self::optionalArray($data, 'bookmark'),
            executionContext: self::getExecutionContext($data)
        );
    }

    private static function getExecutionContext(array $data): ?string
    {
        $context = self::optionalArray($data, 'context') ?? [];
        $value = self::optionalString($data, 'execution_context');
        if ($value !== null) {
            return $value;
        }
        if (!array_key_exists('execution_context', $context)) {
            return null;
        }
        if (!is_string($context['execution_context'])) {
            throw new InvalidArgumentException('context.execution_context must be a string.');
        }
        return $context['execution_context'];
    }

    private static function requiredString(array $data, string $field): string
    {
        if (!isset($data[$field]) || !is_string($data[$field])) {
            throw new InvalidArgumentException("ERR report {$field} must be a string.");
        }
        return $data[$field];
    }

    private static function optionalString(array $data, string $field): ?string
    {
        if (!isset($data[$field])) return null;
        if (!is_string($data[$field])) {
            throw new InvalidArgumentException("ERR report {$field} must be a string or null.");
        }
        return $data[$field];
    }

    private static function requiredInt(array $data, string $field): int
    {
        if (!isset($data[$field]) || !is_int($data[$field])) {
            throw new InvalidArgumentException("ERR report {$field} must be an integer.");
        }
        return $data[$field];
    }

    private static function optionalInt(array $data, string $field): ?int
    {
        if (!isset($data[$field])) return null;
        if (!is_int($data[$field])) {
            throw new InvalidArgumentException("ERR report {$field} must be an integer or null.");
        }
        return $data[$field];
    }

    private static function optionalArray(array $data, string $field): ?array
    {
        if (!isset($data[$field])) return null;
        if (!is_array($data[$field])) {
            throw new InvalidArgumentException("ERR report {$field} must be an array or null.");
        }
        return $data[$field];
    }

    private static function assertJsonValue(mixed $value, string $path, int $depth = 0): void
    {
        if ($depth > 20) {
            throw new InvalidArgumentException("ERR report {$path} exceeds maximum nesting depth.");
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                self::assertJsonValue($child, $path . '.' . (string)$key, $depth + 1);
            }
            return;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)
            || (is_float($value) && is_finite($value))) {
            return;
        }
        throw new InvalidArgumentException("ERR report {$path} must contain only JSON-compatible values.");
    }
}
