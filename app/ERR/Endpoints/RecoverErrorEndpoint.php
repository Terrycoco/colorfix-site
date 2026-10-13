<?php
declare(strict_types=1);

namespace App\ERR\Endpoints;

use App\ERR\Contracts\ErrorContext;
use App\ERR\Contracts\RecoveryStepResult;
use App\ERR\Repos\PdoErrorRepository;
use App\ERR\Services\ErrorStatusBroadcaster;
use App\ERR\Services\RecoveryStatusReporter;
use App\PUB\Create\CreateManager;
use App\PUB\Dispatch\DispatchManager;
use App\PUB\Create\Video\VideoWorkerHealthService;
use App\PUB\Errors\PubErrorHandler;
use PDO;
use Throwable;

/** Selected Working row only; a failed attempt is not a new ERR occurrence. */
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

        $repo = new PdoErrorRepository($pdo);
        $row = null;
        $claimed = false;
        $status = new ErrorStatusBroadcaster($pdo, ErrorStatusBroadcaster::tokenFromRequest());
        $operationId = '';
        $title = 'ERR Recovery';
        $reporter = null;
        try {
            $input = json_decode(file_get_contents('php://input') ?: '', true, 512, JSON_THROW_ON_ERROR);
            $id = is_array($input) ? (int)($input['working_id'] ?? 0) : 0;
            if ($id <= 0 || !($row = $repo->findWorking($id))) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Working record not found.']);
                return;
            }
            $log = $repo->findLog((int)$row['err_log_id']);
            $errNum = (int)($log['err_num'] ?? 0);
            $expectedStep = $errNum === 4001 ? 'check_video_worker' : 'retry_dispatch';
            if (!$log || !in_array($errNum, [4001, 4002, 4003], true) || $log['department'] !== 'PUB'
                || (string)$row['step_key'] !== $expectedStep) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'This error does not support Recover yet.']);
                return;
            }

            $bookmark = json_decode((string)($row['bookmark_json'] ?? 'null'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($bookmark)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'This record has no rerun bookmark.']);
                return;
            }
            if (!$repo->claimWorking($id)) {
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => 'This recovery is already running or no longer waiting.']);
                return;
            }
            $claimed = true;
            $operationId = 'recover-' . (int)$log['id'] . '-' . $id . '-' . bin2hex(random_bytes(4));
            $resourceId = (int)($log['resource_id'] ?? 0);
            $title = ($errNum === 4001 ? 'CREATE' : 'DISPATCH') . ' — Asset ' . $resourceId;
            $reporter = new RecoveryStatusReporter($status, $operationId, $title);
            $stepDescription = $errNum === 4001
                ? 'Checking worker and restarting asset creation…'
                : 'Retrying publication of the existing asset…';
            $reporter->attempting($stepDescription);
            $details = json_decode((string)($log['context_json'] ?? 'null'), true);
            $context = new ErrorContext(
                logId: (int)$log['id'], errNum: $errNum, department: 'PUB',
                message: (string)$log['message'],
                component: $log['component'], operation: $log['operation'],
                resourceType: $log['resource_type'],
                resourceId: $log['resource_id'] === null ? null : (int)$log['resource_id'],
                details: is_array($details) ? $details : [], bookmark: $bookmark
            );
            $handler = new PubErrorHandler(new VideoWorkerHealthService($projectRoot));
            $result = $errNum === 4001
                ? $handler->recover($context, new CreateManager($pdo, $projectRoot))
                : $handler->recoverDispatch($context, new DispatchManager($pdo, $projectRoot));
            $reporter->stepResult($stepDescription, $result);
            if ($result->status === RecoveryStepResult::SUCCESS) {
                $repo->completeStep((int)$log['id'], (string)$row['step_key']);
                $repo->updateHandling((int)$log['id'], 'resolved', $result->detail, true);
            } else {
                $repo->markStep((int)$log['id'], (string)$row['step_key'], $result->status, $result->detail);
                $repo->updateHandling((int)$log['id'], $result->status, $result->detail);
            }
            echo json_encode(['ok' => true, 'status' => $result->status, 'message' => $result->detail]);
        } catch (Throwable $failure) {
            error_log('ERR manual recover failed: ' . $failure->getMessage());
            $reporter?->outcome('failed', 'Recovery encountered an internal error.');
            if ($claimed && is_array($row)) {
                try {
                    $repo->markStep((int)$row['err_log_id'], (string)$row['step_key'], 'failed', 'Recovery encountered an internal error.');
                    $repo->updateHandling((int)$row['err_log_id'], 'failed', 'Recovery encountered an internal error.');
                } catch (Throwable) {}
            }
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Recovery encountered an internal error. The Working record was retained.']);
        }
    }
}
