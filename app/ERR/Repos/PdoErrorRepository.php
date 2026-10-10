<?php
declare(strict_types=1);

namespace App\ERR\Repos;

use PDO;
use RuntimeException;

/**
 * Database access for the shared ERR department.
 *
 * No handler dispatch, recovery policy, notification, or business
 * state changes belong here. Accepts the application's existing PDO.
 */
final class PdoErrorRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    /** @return array<string, mixed>|null */
    public function findCode(int $errNum): ?array
    {
        if ($errNum <= 0) {
            throw new RuntimeException('Error number must be positive.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM err_codes WHERE err_num = :err_num LIMIT 1'
        );
        $stmt->execute(['err_num' => $errNum]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findLog(int $logId): ?array
    {
        $this->requirePositive($logId, 'Error log ID');
        $stmt = $this->pdo->prepare(
            'SELECT * FROM err_log WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $logId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Record an occurrence. An unknown error can use err_num=null.
     *
     * @param array<string, mixed>|null $context
     * @return int Newly created err_log.id
     */
    public function createLog(
        ?int $errNum,
        string $department,
        string $message,
        ?string $component = null,
        ?string $operation = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?string $technicalDetail = null,
        ?array $context = null,
        string $handlingStatus = 'pending'
    ): int {
        if ($errNum !== null) {
            $this->requirePositive($errNum, 'Error number');
        }
        if ($resourceId !== null) {
            $this->requirePositive($resourceId, 'Resource ID');
        }
        $this->requireText($department, 'Department');
        $this->requireText($message, 'Error message');
        $this->requireText($handlingStatus, 'Handling status');

        $stmt = $this->pdo->prepare(
            'INSERT INTO err_log
                (err_num, department, component, operation, resource_type,
                 resource_id, message, technical_detail, context_json,
                 handling_status)
             VALUES
                (:err_num, :department, :component, :operation, :resource_type,
                 :resource_id, :message, :technical_detail, :context_json,
                 :handling_status)'
        );
        $stmt->execute([
            'err_num' => $errNum,
            'department' => $department,
            'component' => $component,
            'operation' => $operation,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'message' => $message,
            'technical_detail' => $technicalDetail,
            'context_json' => $context === null ? null : json_encode(
                $context,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'handling_status' => $handlingStatus,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Update ERR's recorded outcome, not the owning resource's status. */
    public function updateHandling(
        int $logId,
        string $status,
        ?string $result = null,
        bool $resolved = false
    ): void {
        $this->requirePositive($logId, 'Error log ID');
        $this->requireText($status, 'Handling status');

        $stmt = $this->pdo->prepare(
            'UPDATE err_log
             SET handling_status = :status,
                 handling_result = :result,
                 resolved_at = CASE WHEN :resolved = 1 THEN CURRENT_TIMESTAMP ELSE NULL END
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'result' => $result,
            'resolved' => $resolved ? 1 : 0,
            'id' => $logId,
        ]);
    }

    /**
     * Record an unfinished step BEFORE attempting it.
     * Reusing a step key updates its existing outstanding record.
     */
    public function startStep(int $logId, string $stepKey, ?string $detail = null): void
    {
        $this->requirePositive($logId, 'Error log ID');
        $this->requireText($stepKey, 'Step key');

        $stmt = $this->pdo->prepare(
            'INSERT INTO err_working
                (err_log_id, step_key, status, attempt_count, detail)
             VALUES (:log_id, :step_key, \'running\', 1, :detail)
             ON DUPLICATE KEY UPDATE
                status = \'running\',
                attempt_count = attempt_count + 1,
                detail = VALUES(detail),
                started_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'log_id' => $logId,
            'step_key' => $stepKey,
            'detail' => $detail,
        ]);
    }

    /** Keep failed/waiting steps available for inspection or manual clearing. */
    public function markStep(
        int $logId,
        string $stepKey,
        string $status,
        ?string $detail = null
    ): void {
        $this->requirePositive($logId, 'Error log ID');
        $this->requireText($stepKey, 'Step key');
        if (!in_array($status, ['failed', 'waiting', 'unknown'], true)) {
            throw new RuntimeException('Step status must be failed, waiting, or unknown.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE err_working
             SET status = :status, detail = :detail
             WHERE err_log_id = :log_id AND step_key = :step_key'
        );
        $stmt->execute([
            'status' => $status,
            'detail' => $detail,
            'log_id' => $logId,
            'step_key' => $stepKey,
        ]);
    }

    /** A completed step is removed from the temporary work table. */
    public function completeStep(int $logId, string $stepKey): void
    {
        $this->requirePositive($logId, 'Error log ID');
        $this->requireText($stepKey, 'Step key');
        $stmt = $this->pdo->prepare(
            'DELETE FROM err_working WHERE err_log_id = :log_id AND step_key = :step_key'
        );
        $stmt->execute(['log_id' => $logId, 'step_key' => $stepKey]);
    }

    /** @return list<array<string, mixed>> */
    public function listWorking(?int $logId = null): array
    {
        if ($logId !== null) {
            $this->requirePositive($logId, 'Error log ID');
        }
        $sql = 'SELECT * FROM err_working';
        if ($logId !== null) {
            $sql .= ' WHERE err_log_id = :log_id';
        }
        $sql .= ' ORDER BY started_at ASC, id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($logId === null ? [] : ['log_id' => $logId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Explicit admin action only; never called automatically by recovery. */
    public function clearWorkingStep(int $workingId): void
    {
        $this->requirePositive($workingId, 'Working ID');
        $stmt = $this->pdo->prepare('DELETE FROM err_working WHERE id = :id');
        $stmt->execute(['id' => $workingId]);
    }

    private function requirePositive(int $number, string $label): void
    {
        if ($number <= 0) {
            throw new RuntimeException("{$label} must be positive.");
        }
    }

    private function requireText(string $value, string $label): void
    {
        if (trim($value) === '') {
            throw new RuntimeException("{$label} cannot be empty.");
        }
    }
}
