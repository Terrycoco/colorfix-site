<?php
declare(strict_types=1);

namespace App\PROJECTS\Repos;

use PDO;
use RuntimeException;

final class PdoProjectActivityRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByProjectId(int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                id,
                project_id,
                activity_date,
                description,
                hours,
                miles,
                amount,
                entry_type,
                event_type,
                resource_type,
                resource_id,
                created_at,
                updated_at
             FROM project_activity
             WHERE project_id = :project_id
             ORDER BY activity_date DESC, created_at DESC, id DESC'
        );

        $stmt->execute([
            ':project_id' => $projectId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $activityId): ?array
    {
        if ($activityId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                id,
                project_id,
                activity_date,
                description,
                hours,
                miles,
                amount,
                entry_type,
                event_type,
                resource_type,
                resource_id,
                created_at,
                updated_at
             FROM project_activity
             WHERE id = :activity_id
             LIMIT 1'
        );

        $stmt->execute([
            ':activity_id' => $activityId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Creates a manual Project Activity entry.
     *
     * @return array<string, mixed>
     */
    public function createManual(
        int $projectId,
        string $activityDate,
        string $description,
        ?float $hours = null,
        ?float $miles = null,
        ?float $amount = null
    ): array {
        if ($projectId <= 0) {
            throw new RuntimeException('Valid project ID required.');
        }

        $description = trim($description);

        if ($description === '') {
            throw new RuntimeException('Activity description required.');
        }

        if (trim($activityDate) === '') {
            throw new RuntimeException('Activity date required.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO project_activity (
                project_id,
                activity_date,
                description,
                hours,
                miles,
                amount,
                entry_type
             ) VALUES (
                :project_id,
                :activity_date,
                :description,
                :hours,
                :miles,
                :amount,
                \'manual\'
             )'
        );

        $stmt->execute([
            ':project_id' => $projectId,
            ':activity_date' => $activityDate,
            ':description' => $description,
            ':hours' => $hours,
            ':miles' => $miles,
            ':amount' => $amount,
        ]);

        return $this->requireById(
            (int)$this->pdo->lastInsertId()
        );
    }

    /**
     * Creates a system-generated Project Activity event.
     *
     * @return array<string, mixed>
     */
    public function createSystem(
        int $projectId,
        string $description,
        string $eventType,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?string $activityDate = null
    ): array {
        if ($projectId <= 0) {
            throw new RuntimeException('Valid project ID required.');
        }

        $description = trim($description);
        $eventType = trim($eventType);

        if ($description === '') {
            throw new RuntimeException('Activity description required.');
        }

        if ($eventType === '') {
            throw new RuntimeException('System event type required.');
        }

        $activityDate = $activityDate ?: date('Y-m-d');

        $stmt = $this->pdo->prepare(
            'INSERT INTO project_activity (
                project_id,
                activity_date,
                description,
                entry_type,
                event_type,
                resource_type,
                resource_id
             ) VALUES (
                :project_id,
                :activity_date,
                :description,
                \'system\',
                :event_type,
                :resource_type,
                :resource_id
             )'
        );

        $stmt->execute([
            ':project_id' => $projectId,
            ':activity_date' => $activityDate,
            ':description' => $description,
            ':event_type' => $eventType,
            ':resource_type' => $resourceType,
            ':resource_id' => $resourceId,
        ]);

        return $this->requireById(
            (int)$this->pdo->lastInsertId()
        );
    }

    /**
     * Updates a manual Project Activity entry.
     *
     * @return array<string, mixed>
     */
    public function updateManual(
        int $activityId,
        string $activityDate,
        string $description,
        ?float $hours = null,
        ?float $miles = null,
        ?float $amount = null
    ): array {
        if ($activityId <= 0) {
            throw new RuntimeException('Valid activity ID required.');
        }

        $description = trim($description);

        if ($description === '') {
            throw new RuntimeException('Activity description required.');
        }

        if (trim($activityDate) === '') {
            throw new RuntimeException('Activity date required.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE project_activity
                SET activity_date = :activity_date,
                    description = :description,
                    hours = :hours,
                    miles = :miles,
                    amount = :amount
              WHERE id = :activity_id
                AND entry_type = \'manual\''
        );

        $stmt->execute([
            ':activity_id' => $activityId,
            ':activity_date' => $activityDate,
            ':description' => $description,
            ':hours' => $hours,
            ':miles' => $miles,
            ':amount' => $amount,
        ]);

        $activity = $this->findById($activityId);

        if (!$activity) {
            throw new RuntimeException('Project activity was not found.');
        }

        if (($activity['entry_type'] ?? '') !== 'manual') {
            throw new RuntimeException(
                'System-generated project activity cannot be edited manually.'
            );
        }

        return $activity;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireById(int $activityId): array
    {
        $activity = $this->findById($activityId);

        if (!$activity) {
            throw new RuntimeException(
                'Project activity could not be loaded.'
            );
        }

        return $activity;
    }
}