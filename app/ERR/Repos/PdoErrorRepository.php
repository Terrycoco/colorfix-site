<?php
declare(strict_types=1);

namespace App\ERR\Repos;

use PDO;
use InvalidArgumentException;
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
    public function startStep(
        int $logId,
        string $stepKey,
        ?string $detail = null,
        ?array $bookmark = null
    ): void
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
                bookmark_json = COALESCE(VALUES(bookmark_json), bookmark_json),
                started_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'log_id' => $logId,
            'step_key' => $stepKey,
            'detail' => $detail,
            'bookmark_json' => $bookmark === null ? null : json_encode(
                $bookmark,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
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

    /** Return all three ERR admin grids from the shared repository. */
    public function report(): array
    {
        $working = $this->pdo->query(
            'SELECT w.id, w.err_log_id, w.step_key, w.status, w.attempt_count,
                    w.detail, w.bookmark_json, w.started_at, w.updated_at,
                    l.err_num, l.department, l.component, l.operation,
                    l.resource_type, l.resource_id, l.message,
                    l.created_at AS occurred_at,
                    c.description, c.explanation
             FROM err_working w
             JOIN err_log l ON l.id = w.err_log_id
             LEFT JOIN err_codes c ON c.err_num = l.err_num
             ORDER BY w.started_at DESC, w.id DESC
             LIMIT 500'
        )->fetchAll(PDO::FETCH_ASSOC);

        $history = $this->pdo->query(
            'SELECT id, err_num, department, component, operation,
                    resource_type, resource_id, message, technical_detail,
                    context_json, handling_status, handling_result,
                    created_at, resolved_at
             FROM err_log ORDER BY id DESC LIMIT 500'
        )->fetchAll(PDO::FETCH_ASSOC);

        $codes = $this->pdo->query(
            'SELECT err_num, department, code, description, explanation,
                    severity, context_type, handler_key, handling_mode,
                    notify_policy, is_active, created_at
             FROM err_codes ORDER BY err_num ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'ok' => true,
            'working' => $working,
            'history' => $history,
            'codes' => $codes,
        ];
    }

    /** Apply a narrow, validated edit from an ERR drawer. */
    public function save(string $view, int $id, array $changes): void
    {
        $config = match ($view) {
            'working' => ['err_working', 'id', ['detail']],
            'history' => ['err_log', 'id', ['handling_result']],
            'codes' => ['err_codes', 'err_num', [
                'description', 'explanation', 'severity', 'context_type',
                'handling_mode', 'notify_policy', 'is_active',
            ]],
            default => throw new InvalidArgumentException('Unknown ERR view.'),
        };

        if ($id <= 0 || !$changes) {
            throw new InvalidArgumentException('A record and at least one change are required.');
        }

        [$table, $pk, $allowed] = $config;
        foreach ($changes as $key => $value) {
            if (!in_array($key, $allowed, true) || !is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Field not editable: ' . $key);
            }
            if (is_string($value) && strlen($value) > 16000) {
                throw new InvalidArgumentException('Field value is too long.');
            }
        }

        if ($view === 'codes') {
            foreach (['description', 'explanation'] as $field) {
                if (array_key_exists($field, $changes) && trim((string)$changes[$field]) === '' && $field === 'description') {
                    throw new InvalidArgumentException('Description is required.');
                }
            }
            $values = [
                'severity' => ['info', 'warning', 'error', 'critical'],
                'context_type' => ['interactive', 'background', 'both'],
                'handling_mode' => ['automatic', 'manual', 'log_only'],
                'notify_policy' => ['none', 'once_per_incident', 'on_failure', 'always'],
            ];
            foreach ($values as $field => $permitted) {
                if (isset($changes[$field]) && !in_array((string)$changes[$field], $permitted, true)) {
                    throw new InvalidArgumentException('Invalid ' . $field . '.');
                }
            }
            if (array_key_exists('is_active', $changes)) {
                if (!in_array((string)$changes['is_active'], ['0', '1'], true)) {
                    throw new InvalidArgumentException('Invalid active flag.');
                }
            }
        }

        $assignments = [];
        $params = [':id' => $id];
        foreach ($changes as $field => $value) {
            $assignments[] = "`{$field}` = :{$field}";
            $params[':' . $field] = $value;
        }
        $sql = "UPDATE `{$table}` SET " . implode(', ', $assignments) . " WHERE `{$pk}` = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        // A no-op update can have rowCount 0; confirm the record is real.
        $exists = $this->pdo->prepare("SELECT 1 FROM `{$table}` WHERE `{$pk}` = :id");
        $exists->execute([':id' => $id]);
        if (!$exists->fetchColumn()) {
            throw new RuntimeException('ERR record no longer exists.');
        }
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
