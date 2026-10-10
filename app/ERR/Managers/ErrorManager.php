<?php
declare(strict_types=1);

namespace App\ERR\Managers;

use App\ERR\Repos\PdoErrorRepository;
use App\ERR\Contracts\ErrorContext;
use App\ERR\Contracts\ErrorHandlerInterface;
use App\ERR\Services\RecoveryExecutor;
use Throwable;

/**
 * Shared error reporting entrypoint.
 *
 * Phase 1: classify and persist occurrences. Recovery dispatch is deliberately
 * not enabled until the handler/executor contracts are installed.
 *
 * Never throws for a reporting failure: errors in ERR must not mask the
 * underlying departmental failure.
 */
final class ErrorManager
{
    public function __construct(
        private PdoErrorRepository $repository,
        private string $fallbackLogPath,
        private array $handlerFactories = []
    ) {}

    /**
     * Report a known departmental error.
     *
     * @param array<string, mixed> $context
     * @return int|null err_log ID, or null when database reporting failed.
     */
    public function report(
        int $errNum,
        string $department,
        string $message,
        ?string $component = null,
        ?string $operation = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?string $technicalDetail = null,
        array $context = []
    ): ?int {
        try {
            $registration = $this->repository->findCode($errNum);

            $registered = $registration !== null
                && (int)($registration['is_active'] ?? 0) === 1
                && strcasecmp((string)$registration['department'], $department) === 0;

            if (!$registered) {
                $context['requested_err_num'] = $errNum;
                $context['classification'] = 'unclassified';
            }

            $safeNumber = $registered ? $errNum : null;
            $status = $registered ? 'pending' : 'unclassified';

            return $this->repository->createLog(
                errNum: $safeNumber,
                department: $department,
                message: $message,
                component: $component,
                operation: $operation,
                resourceType: $resourceType,
                resourceId: $resourceId,
                technicalDetail: $technicalDetail,
                context: $context,
                handlingStatus: $status
            );
        } catch (Throwable $failure) {
            $this->writeFallback([
                'timestamp' => gmdate('c'),
                'event' => 'err_reporting_failed',
                'err_num' => $errNum,
                'department' => $department,
                'component' => $component,
                'operation' => $operation,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'message' => $message,
                'technical_detail' => $technicalDetail,
                'context' => $context,
                'reporting_failure' => $failure->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Record an occurrence, then execute its registered departmental recovery.
     *
     * Handler keys come from err_codes; executable factories come from
     * trusted application code. Never execute a PHP callable from DB text.
     *
     * A failure in error recovery does not interrupt the original operation.
     * Returns the err_log ID when logging succeeded.
     */
    public function reportAndHandle(
        int $errNum,
        string $department,
        string $message,
        ?string $component = null,
        ?string $operation = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?string $technicalDetail = null,
        array $context = [],
        ?array $bookmark = null
    ): ?int {
        $logId = $this->report(
            $errNum, $department, $message, $component, $operation,
            $resourceType, $resourceId, $technicalDetail, $context
        );

        if ($logId === null) {
            return null;
        }

        try {
            $registration = $this->repository->findCode($errNum);
            if ($registration === null
                || (int)($registration['is_active'] ?? 0) !== 1
                || strcasecmp((string)$registration['department'], $department) !== 0) {
                return $logId;
            }

            $handlerKey = trim((string)($registration['handler_key'] ?? ''));
            if ($handlerKey === '') {
                $this->repository->updateHandling(
                    $logId, 'waiting', 'No automatic handler registered.'
                );
                return $logId;
            }

            $factory = $this->handlerFactories[$handlerKey] ?? null;
            if (!is_callable($factory)) {
                $this->repository->updateHandling(
                    $logId, 'failed', "No installed handler for key: {$handlerKey}"
                );
                return $logId;
            }

            $handler = $factory();
            if (!$handler instanceof ErrorHandlerInterface) {
                throw new \RuntimeException(
                    "Handler {$handlerKey} does not implement ErrorHandlerInterface."
                );
            }

            $errorContext = new ErrorContext(
                logId: $logId,
                errNum: $errNum,
                department: $department,
                message: $message,
                component: $component,
                operation: $operation,
                resourceType: $resourceType,
                resourceId: $resourceId,
                details: $context,
                bookmark: $bookmark
            );

            (new RecoveryExecutor($this->repository))
                ->run($errorContext, $handler);
        } catch (Throwable $failure) {
            $details = 'Recovery dispatch failed: '
                . get_class($failure) . ': ' . $failure->getMessage();
            try {
                $this->repository->updateHandling($logId, 'failed', $details);
            } catch (Throwable) {
                $this->writeFallback([
                    'timestamp' => gmdate('c'),
                    'event' => 'err_recovery_dispatch_failed',
                    'err_log_id' => $logId,
                    'message' => $details
                ]);
            }
        }

        return $logId;
    }

    /**
     * Last-resort diagnostics; intentionally never re-enters ERR or the DB.
     * The caller should supply a server-only path outside the public webroot.
     *
     * @param array<string, mixed> $payload
     */
    private function writeFallback(array $payload): void
    {
        try {
            $line = json_encode(
                $payload,
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
            if ($line === false) {
                return;
            }
            @file_put_contents(
                $this->fallbackLogPath,
                $line . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable) {
            // Fail closed: never trigger another error handler from ERR.
        }
    }
}
