<?php
declare(strict_types=1);

namespace App\ERR\Endpoints;

use App\ERR\Contracts\ErrorContext;
use App\ERR\Contracts\ErrorHandlerInterface;
use App\ERR\Contracts\RecoveryProviderInterface;
use App\ERR\Repos\PdoErrorRepository;
use App\ERR\Services\ErrorStatusBroadcaster;
use App\ERR\Services\RecoveryExecutor;
use App\ERR\Services\RecoveryStatusReporter;
use PDO;
use Throwable;

/** Common ERR recovery preview and executor; department supplies ordered steps. */
final class RecoverErrorEndpoint
{
    public static function respond(PDO $pdo, string $projectRoot): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST required.']);
            return;
        }

        $repo = null;
        $row = null;
        $claimed = false;
        $finished = false;
        $action = "";
        $id = 0;
        $phase = "reading request";
        try {
            $input = json_decode(file_get_contents('php://input') ?: '', true, 512, JSON_THROW_ON_ERROR);
            $id = is_array($input) ? (int)($input['working_id'] ?? 0) : 0;
            $action = is_array($input) ? (string)($input['action'] ?? '') : '';
            if ($id <= 0 || !in_array($action, ['plan', 'start', 'check'], true)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Working ID and recovery action are required.']);
                return;
            }
            $phase = "loading Working record";
            $repo = new PdoErrorRepository($pdo);
            $row = $repo->findWorking($id);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Working record not found.']);
                return;
            }
            $phase = "loading incident log";
            $log = $repo->findLog((int)$row['err_log_id']);
            if (!$log) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Error occurrence not found.']);
                return;
            }
            $phase = "loading error registration";
            $code = $repo->findCode((int)($log['err_num'] ?? 0));
            if (!$code || (int)($code['is_active'] ?? 0) !== 1
                || strcasecmp((string)$code['department'], (string)$log['department']) !== 0) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'No active error registration matches this incident.']);
                return;
            }
            $phase = "parsing incident context";
            $bookmark = json_decode((string)($row['bookmark_json'] ?? 'null'), true, 512, JSON_THROW_ON_ERROR);
            $details = json_decode((string)($log['context_json'] ?? 'null'), true, 512, JSON_THROW_ON_ERROR);
            $phase = "constructing recovery context";
            $context = new ErrorContext(
                logId: (int)$log['id'],
                errNum: (int)$log['err_num'],
                department: (string)$log['department'],
                message: (string)$log['message'],
                component: $log['component'] === null ? null : (string)$log['component'],
                operation: $log['operation'] === null ? null : (string)$log['operation'],
                resourceType: $log['resource_type'] === null ? null : (string)$log['resource_type'],
                resourceId: $log['resource_id'] === null ? null : (int)$log['resource_id'],
                details: is_array($details) ? $details : [],
                bookmark: is_array($bookmark) ? $bookmark : null
            );

            // Trusted handler registration: never resolve class names from database text.
            // Add future departments here via a centralized resolver/registry.
            $phase = "resolving department recovery provider";
            $handler = self::resolveHandler($context, (string)($code['handler_key'] ?? ''), $pdo, $projectRoot);
            $phase = "reading registered recovery steps";
            $steps = $handler->steps($context);
            $plan = [];
            $keys = [];
            foreach ($steps as $step) {
                $key = trim($step->key());
                if ($key === '' || isset($keys[$key]) || strlen($key) > 100) {
                    throw new \RuntimeException('Invalid or duplicate registered recovery step.');
                }
                $keys[$key] = true;
                $plan[] = ['key' => $key, 'description' => $step->description()];
            }
            $phase = "validating registered recovery plan";
            if (!$plan || !isset($keys[(string)$row['step_key']])) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'This Working step is not registered for recovery.']);
                return;
            }
            if ($action === 'check') {
                // The browser only asks whether an already-started step has settled.
                // Claim prevents overlapping checks; never call execute() again.
                $requestedOperationId = (string)($input['operation_id'] ?? '');
                if (!preg_match('/\Aerr-recover-[a-zA-Z0-9-]{8,80}\z/D', $requestedOperationId)) {
                    http_response_code(422);
                    echo json_encode(['ok' => false, 'error' => 'Valid recovery operation ID required.']);
                    return;
                }
                if ((string)$row['status'] !== 'waiting' || !$repo->claimWaitingForCheck($id)) {
                    http_response_code(409);
                    echo json_encode(['ok' => false, 'error' => 'Recovery step is not waiting or is being checked.']);
                    return;
                }
                $claimed = true;
                $reporter = new RecoveryStatusReporter(
                    new ErrorStatusBroadcaster($pdo, ErrorStatusBroadcaster::tokenFromRequest()),
                    $requestedOperationId, 'ERR ' . $context->errNum . ' — ' . $context->department
                );
                $phase = 'checking asynchronous recovery outcome';
                $outcome = (new RecoveryExecutor($repo, $reporter))->resume($context, $handler, (string)$row['step_key']);
                $finished = true;
                echo json_encode(['ok' => true, 'status' => $outcome->status, 'message' => $outcome->detail,
                    'next_working_id' => self::nextWaitingId($repo, $context->logId),
                    'can_check_next' => self::canCheckNext($repo, $context->logId, $handler, $context)]);
                return;
            }
            if ($action === 'plan') {
                $phase = "serializing recovery plan";
                echo json_encode([
                    'ok' => true,
                    'error_number' => $context->errNum,
                    'department' => $context->department,
                    'resource_type' => $context->resourceType,
                    'resource_id' => $context->resourceId,
                    'handler_key' => (string)$code['handler_key'],
                    'steps' => $plan,
                ], JSON_THROW_ON_ERROR);
                return;
            }
            $phase = "claiming recovery for execution";
            if (!$repo->claimWorking($id)) {
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => 'This recovery is already running or no longer available.']);
                return;
            }
            $requestedOperationId = (string)($input['operation_id'] ?? '');
            $claimed = true;
            $operationId = preg_match('/\Aerr-recover-[a-zA-Z0-9-]{8,80}\z/D', $requestedOperationId)
                ? $requestedOperationId
                : 'recover-' . $context->logId . '-' . $id . '-' . bin2hex(random_bytes(4));
            $title = 'ERR ' . $context->errNum . ' — ' . $context->department;
            $reporter = new RecoveryStatusReporter(
                new ErrorStatusBroadcaster($pdo, ErrorStatusBroadcaster::tokenFromRequest()),
                $operationId, $title
            );
            $phase = "executing registered recovery steps";
            $outcome = (new RecoveryExecutor($repo, $reporter))->run($context, $handler);
            $finished = true;
            echo json_encode([
                'ok' => true, 'status' => $outcome->status, 'message' => $outcome->detail,
                'operation_id' => $operationId,
                'next_working_id' => self::nextWaitingId($repo, $context->logId),
                'can_check_next' => self::canCheckNext($repo, $context->logId, $handler, $context),
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $failure) {
            error_log(sprintf('ERR recovery failed [action=%s working_id=%d phase=%s exception=%s]: %s',
                $action, $id, $phase, get_class($failure), $failure->getMessage()));
            if ($action === 'plan') {
                // Planning never claims, executes, or updates a recovery record.
                // This authenticated admin endpoint can return a targeted diagnostic.
                http_response_code(500);
                echo json_encode([
                    'ok' => false,
                    'error' => 'Could not load recovery plan at ' . $phase . ': ' . $failure->getMessage(),
                    'phase' => $phase,
                ]);
                return;
            }
            if ($claimed && !$finished && $repo instanceof PdoErrorRepository && is_array($row)) {
                try {
                    $repo->markStep((int)$row['err_log_id'], (string)$row['step_key'], 'failed',
                        'ERR could not complete the recovery attempt.');
                    $repo->updateHandling((int)$row['err_log_id'], 'failed',
                        'ERR could not complete the recovery attempt.');
                } catch (Throwable $ignored) {
                    error_log('ERR could not reset recovery claim: ' . $ignored->getMessage());
                }
            }
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Recovery could not be completed. Inspect the Working record before retrying.']);
        }
    }

    private static function nextWaitingId(PdoErrorRepository $repo, int $logId): ?int
    {
        foreach ($repo->listWorking($logId) as $candidate) {
            if ((string)$candidate['status'] === 'waiting') return (int)$candidate['id'];
        }
        return null;
    }

    private static function canCheckNext(
        PdoErrorRepository $repo, int $logId, ErrorHandlerInterface $handler, ErrorContext $context
    ): bool {
        $pendingId = self::nextWaitingId($repo, $logId);
        if (!$pendingId) return false;
        $working = $repo->findWorking($pendingId);
        if (!$working) return false;
        foreach ($handler->steps($context) as $step) {
            if ($step->key() === (string)$working['step_key']) {
                return $step instanceof \App\ERR\Contracts\PendingRecoveryStepInterface;
            }
        }
        return false;
    }

    private static function resolveHandler(
        ErrorContext $context, string $handlerKey, PDO $pdo, string $projectRoot
    ): ErrorHandlerInterface {
        // ERR never imports a department's implementation. The installed
        // department registers a conventional RecoveryProvider class.
        $department = strtoupper($context->department);
        if (!preg_match('/\A[A-Z][A-Z0-9_]*\z/D', $department)) {
            throw new \InvalidArgumentException('Invalid recovery department.');
        }
        $providerClass = 'App\\' . $department . '\\Errors\\RecoveryProvider';
        if (!class_exists($providerClass)) {
            throw new \RuntimeException('No recovery provider installed for this department.');
        }
        $provider = new $providerClass();
        if (!$provider instanceof RecoveryProviderInterface) {
            throw new \RuntimeException('Department recovery provider has an invalid contract.');
        }
        return $provider->handler($context, $handlerKey, $pdo, $projectRoot);
    }
}
