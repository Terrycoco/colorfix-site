<?php
declare(strict_types=1);

namespace App\ERR\Services;

use App\ERR\Contracts\ErrorContext;
use App\ERR\Contracts\ErrorHandlerInterface;
use App\ERR\Contracts\ErrorHandlingResult;
use App\ERR\Repos\PdoErrorRepository;
use RuntimeException;

/**
 * Rechecks outstanding recovery work for a specific known error.
 * This is a manually invoked service, not a scheduler or HTTP endpoint.
 *
 * The caller must supply the trusted departmental handler. Only waiting
 * work is retried. Failed/unknown/running work stays for investigation.
 */
final class RecoveryRechecker
{
    public function __construct(
        private PdoErrorRepository $repository,
        private RecoveryExecutor $executor
    ) {}

    /**
     * @return array{examined:int,resolved:int,waiting:int,failed:int,skipped:int}
     */
    public function recheck(
        int $errNum,
        string $department,
        ErrorHandlerInterface $handler
    ): array {
        if ($errNum <= 0 || trim($department) === '') {
            throw new RuntimeException('Valid error number and department required.');
        }

        $tally = [
            'examined' => 0, 'resolved' => 0,
            'waiting' => 0, 'failed' => 0, 'skipped' => 0,
        ];
        $handledLogs = [];

        foreach ($this->repository->listWorking() as $working) {
            if ((string)$working['status'] !== 'waiting') {
                continue;
            }
            $logId = (int)$working['err_log_id'];
            if (isset($handledLogs[$logId])) {
                continue;
            }

            $log = $this->repository->findLog($logId);
            if ($log === null
                || (int)$log['err_num'] !== $errNum
                || (string)$log['department'] !== $department
                || (string)$log['handling_status'] !== 'waiting') {
                continue;
            }

            $handledLogs[$logId] = true;
            $tally['examined']++;

            $context = [];
            if (!empty($log['context_json'])) {
                try {
                    $decoded = json_decode((string)$log['context_json'], true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $context = $decoded;
                    }
                } catch (\JsonException) {
                    // Preserve original occurrence; recovery can still check health.
                }
            }

            $bookmark = null;
            if (!empty($working['bookmark_json'])) {
                try {
                    $decodedBookmark = json_decode(
                        (string)$working['bookmark_json'], true, 512, JSON_THROW_ON_ERROR
                    );
                    if (is_array($decodedBookmark)) {
                        $bookmark = $decodedBookmark;
                    }
                } catch (\JsonException) {
                    // Leave the recovery step visible if its bookmark cannot be read.
                    $tally['failed']++;
                    continue;
                }
            }

            $errorContext = new ErrorContext(
                logId: $logId,
                errNum: $errNum,
                department: $department,
                message: (string)$log['message'],
                component: $log['component'] === null ? null : (string)$log['component'],
                operation: $log['operation'] === null ? null : (string)$log['operation'],
                resourceType: $log['resource_type'] === null ? null : (string)$log['resource_type'],
                resourceId: $log['resource_id'] === null ? null : (int)$log['resource_id'],
                details: $context,
                bookmark: $bookmark
            );

            $outcome = $this->executor->run($errorContext, $handler);
            if ($outcome->status === ErrorHandlingResult::RESOLVED) {
                $tally['resolved']++;
            } elseif ($outcome->status === ErrorHandlingResult::WAITING) {
                $tally['waiting']++;
            } else {
                $tally['failed']++;
            }
        }

        return $tally;
    }
}
