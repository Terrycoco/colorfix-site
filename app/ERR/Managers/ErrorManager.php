<?php
declare(strict_types=1);

namespace App\ERR\Managers;

use App\ERR\Repos\PdoErrorRepository;
use App\ERR\Repos\PdoExternalErrorCodeRepository;
use App\ERR\Contracts\ErrorContext;
use App\ERR\Contracts\ErrorHandlerInterface;
use App\ERR\Services\RecoveryExecutor;
use App\ERR\Services\ErrorEmailNotifier;
use Throwable;

/**
 * Shared error reporting entrypoint.
 *
 * Classifies, persists, dispatches installed handlers, and notifies for
 * unresolved background incidents according to their registered policy.
 *
 * Never throws for a reporting failure: errors in ERR must not mask the
 * underlying departmental failure.
 */
final class ErrorManager
{
    public function __construct(
        private PdoErrorRepository $repository,
        private string $fallbackLogPath,
        private array $handlerFactories = [],
        private ?PdoExternalErrorCodeRepository $externalCodes = null
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
        array $context = [],
        ?int $httpStatus = null,
        ?string $extErrNum = null,
        ?string $nativeMessage = null
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
                handlingStatus: $status,
                httpStatus: $httpStatus,
                nativeCode: $extErrNum,
                nativeMessage: $nativeMessage
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
                'http_status' => $httpStatus,
                'ext_err_num' => $extErrNum,
                'native_message' => $nativeMessage,
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
        ?array $bookmark = null,
        ?int $httpStatus = null,
        ?string $extErrNum = null,
        ?string $nativeMessage = null
    ): ?int {
        $logId = $this->report(
            $errNum, $department, $message, $component, $operation,
            $resourceType, $resourceId, $technicalDetail, $context,
            $httpStatus, $extErrNum, $nativeMessage
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
                $this->notifyIfAttentionNeeded($logId, $errNum, $department, $context);
                return $logId;
            }

            $factory = $this->handlerFactories[$handlerKey] ?? null;
            if (!is_callable($factory)) {
                $this->repository->updateHandling(
                    $logId, 'failed', "No installed handler for key: {$handlerKey}"
                );
                $this->notifyIfAttentionNeeded($logId, $errNum, $department, $context);
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

        // Email is advisory: failure must never break the logged incident or recovery.
        $this->notifyIfAttentionNeeded($logId, $errNum, $department, $context);
        return $logId;
    }

    /**
     * Standard entry point for a structured department failure.
     *
     * Required: department, message, fallback_cf_err_num. The fallback must
     * be a registered ColorFix code for that department. External errors are
     * translated when the optional external repository is installed; unknown
     * external errors retain the fallback classification and all diagnostics.
     *
     * Optional: provider, http_status, ext_err_num, native_message,
     * component, operation, resource_type, resource_id, technical_detail,
     * context, bookmark. Keep raw provider response (sanitized) in context.
     *
     * @param array<string, mixed> $package
     */
    public function reportPackageAndHandle(array $package): ?int
    {
        try {
            $department = trim((string)($package['department'] ?? ''));
            $message = trim((string)($package['message'] ?? ''));
            $fallback = (int)($package['fallback_cf_err_num'] ?? 0);
            if ($department === '' || $message === '' || $fallback <= 0) {
                throw new \InvalidArgumentException('ERR package requires department, message, and fallback_cf_err_num.');
            }
            $context = $package['context'] ?? [];
            if (!is_array($context)) {
                throw new \InvalidArgumentException('ERR package context must be an array.');
            }
            $provider = strtolower(trim((string)($package['provider'] ?? '')));
            $status = isset($package['http_status']) ? (int)$package['http_status'] : null;
            $native = isset($package['ext_err_num']) ? (string)$package['ext_err_num'] : null;
            $nativeMessage = isset($package['native_message']) ? (string)$package['native_message'] : null;
            if ($provider !== '') $context['provider'] = $provider;
            // Lookup is advisory. A broken/missing dictionary must never lose an incident.
            $errNum = $fallback;
            if ($provider !== '' && $this->externalCodes !== null) {
                try {
                    $mapped = $this->externalCodes->resolve($provider, $status, $native, $nativeMessage ?? $message);
                    if ($mapped !== null) {
                        $registration = $this->repository->findCode($mapped);
                        if ($registration !== null
                            && (int)($registration['is_active'] ?? 0) === 1
                            && strcasecmp((string)$registration['department'], $department) === 0) {
                            $errNum = $mapped;
                        } else {
                            $context['external_mapping_invalid'] = $mapped;
                        }
                    }
                } catch (Throwable $failure) {
                    $context['external_mapping_failure'] = get_class($failure) . ': ' . $failure->getMessage();
                }
            }
            return $this->reportAndHandle(
                errNum: $errNum,
                department: $department,
                message: $message,
                component: isset($package['component']) ? (string)$package['component'] : null,
                operation: isset($package['operation']) ? (string)$package['operation'] : null,
                resourceType: isset($package['resource_type']) ? (string)$package['resource_type'] : null,
                resourceId: isset($package['resource_id']) ? (int)$package['resource_id'] : null,
                technicalDetail: isset($package['technical_detail']) ? (string)$package['technical_detail'] : null,
                context: $context,
                bookmark: isset($package['bookmark']) && is_array($package['bookmark']) ? $package['bookmark'] : null,
                httpStatus: $status,
                extErrNum: $native,
                nativeMessage: $nativeMessage
            );
        } catch (Throwable $failure) {
            // Never lose the underlying department failure if packaging fails.
            $this->writeFallback([
                'timestamp' => gmdate('c'),
                'event' => 'err_package_failed',
                'department' => $package['department'] ?? null,
                'message' => $package['message'] ?? null,
                'reporting_failure' => $failure->getMessage(),
            ]);
            return null;
        }
    }

    /** @param array<string, mixed> $context */
    private function notifyIfAttentionNeeded(
        int $logId,
        int $errNum,
        string $department,
        array $context
    ): void {
        try {
            $registration = $this->repository->findCode($errNum);
            if ($registration === null
                || (int)($registration['is_active'] ?? 0) !== 1
                || strcasecmp((string)($registration['department'] ?? ''), $department) !== 0) {
                return;
            }

            // A registration marked "both" must specify the actual execution context.
            $kind = strtolower(trim((string)($registration['context_type'] ?? '')));
            $actual = strtolower(trim((string)($context['execution_context'] ?? '')));
            if ($kind !== 'background' && !($kind === 'both' && $actual === 'background')) {
                return;
            }

            $policy = strtolower(trim((string)($registration['notify_policy'] ?? 'none')));
            if (!in_array($policy, ['once_per_incident', 'on_failure', 'always'], true)) {
                return;
            }
            $incident = $this->repository->findLog($logId);
            if ($incident === null) return;
            $status = strtolower((string)($incident['handling_status'] ?? ''));
            if (!in_array($status, ['waiting', 'failed'], true)) return;
            if ($policy === 'on_failure' && $status !== 'failed') return;

            // This is called once on initial report, never for manual Recover attempts.
            // The project root is three levels above app/ERR/err_fallback.log.
            $root = dirname($this->fallbackLogPath, 3);
            (new ErrorEmailNotifier($root))->send($registration, $incident);
        } catch (Throwable $failure) {
            $this->writeFallback([
                'timestamp' => gmdate('c'),
                'event' => 'err_notification_failed',
                'err_log_id' => $logId,
                'message' => $failure->getMessage(),
            ]);
        }
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
