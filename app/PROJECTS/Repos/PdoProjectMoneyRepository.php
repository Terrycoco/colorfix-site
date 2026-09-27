<?php
declare(strict_types=1);

namespace App\PROJECTS\Repos;

use PDO;
use RuntimeException;

final class PdoProjectMoneyRepository
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
                entry_date,
                entry_type,
                description,
                amount,
                miles,
                notes,
                created_at,
                updated_at
             FROM project_money
             WHERE project_id = :project_id
             ORDER BY entry_date DESC, id DESC'
        );

        $stmt->execute([
            ':project_id' => $projectId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $entryId): ?array
    {
        if ($entryId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                id,
                project_id,
                entry_date,
                entry_type,
                description,
                amount,
                miles,
                notes,
                created_at,
                updated_at
             FROM project_money
             WHERE id = :entry_id
             LIMIT 1'
        );

        $stmt->execute([
            ':entry_id' => $entryId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function create(
        int $projectId,
        string $entryDate,
        string $entryType,
        string $description,
        ?float $amount = null,
        ?float $miles = null,
        ?string $notes = null
    ): int {
        $this->validateEntry($projectId, $entryDate, $entryType, $description, $amount, $miles);

        $stmt = $this->pdo->prepare(
            'INSERT INTO project_money (
                project_id,
                entry_date,
                entry_type,
                description,
                amount,
                miles,
                notes
             ) VALUES (
                :project_id,
                :entry_date,
                :entry_type,
                :description,
                :amount,
                :miles,
                :notes
             )'
        );

        $stmt->execute([
            ':project_id' => $projectId,
            ':entry_date' => $entryDate,
            ':entry_type' => $entryType,
            ':description' => trim($description),
            ':amount' => $amount,
            ':miles' => $miles,
            ':notes' => $this->nullableText($notes),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(
        int $entryId,
        int $projectId,
        string $entryDate,
        string $entryType,
        string $description,
        ?float $amount = null,
        ?float $miles = null,
        ?string $notes = null
    ): void {
        if ($entryId <= 0) {
            throw new RuntimeException('Valid money entry ID required.');
        }

        $this->validateEntry($projectId, $entryDate, $entryType, $description, $amount, $miles);

        $stmt = $this->pdo->prepare(
            'UPDATE project_money
                SET entry_date = :entry_date,
                    entry_type = :entry_type,
                    description = :description,
                    amount = :amount,
                    miles = :miles,
                    notes = :notes
              WHERE id = :entry_id
                AND project_id = :project_id'
        );

        $stmt->execute([
            ':entry_id' => $entryId,
            ':project_id' => $projectId,
            ':entry_date' => $entryDate,
            ':entry_type' => $entryType,
            ':description' => trim($description),
            ':amount' => $amount,
            ':miles' => $miles,
            ':notes' => $this->nullableText($notes),
        ]);
    }

    public function delete(int $entryId, int $projectId): void
    {
        if ($entryId <= 0 || $projectId <= 0) {
            throw new RuntimeException('Valid money entry and Project IDs required.');
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM project_money
             WHERE id = :entry_id
               AND project_id = :project_id'
        );

        $stmt->execute([
            ':entry_id' => $entryId,
            ':project_id' => $projectId,
        ]);
    }

    private function validateEntry(
        int $projectId,
        string $entryDate,
        string $entryType,
        string $description,
        ?float $amount,
        ?float $miles
    ): void {
        if ($projectId <= 0) {
            throw new RuntimeException('Valid Project ID required.');
        }

        if (trim($entryDate) === '') {
            throw new RuntimeException('Entry date is required.');
        }

        if (!in_array($entryType, ['payment', 'expense', 'mileage'], true)) {
            throw new RuntimeException('Invalid money entry type.');
        }

        if (trim($description) === '') {
            throw new RuntimeException('Description is required.');
        }

        if (in_array($entryType, ['payment', 'expense'], true) && $amount === null) {
            throw new RuntimeException('Amount is required for payments and expenses.');
        }

        if ($entryType === 'mileage' && $miles === null) {
            throw new RuntimeException('Miles are required for mileage entries.');
        }
    }

    private function nullableText(?string $value): ?string
    {
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }
}
