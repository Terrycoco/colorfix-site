<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

class PdoHoaRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getAll(): array
    {
        $sql = "
            SELECT *
            FROM hoas
            ORDER BY name ASC
        ";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM hoas
            WHERE id = :id
        ");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function insert(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO hoas (
                name,
                city,
                state,
                hoa_type,
                eligibility_status,
                reason_not_eligible,
                source,
                notes
            ) VALUES (
                :name,
                :city,
                :state,
                :hoa_type,
                :eligibility_status,
                :reason_not_eligible,
                :source,
                :notes
            )
        ");

        $stmt->execute([
            'name' => $data['name'],
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'hoa_type' => $data['hoa_type'] ?? 'unknown',
            'eligibility_status' => $data['eligibility_status'] ?? 'potential',
            'reason_not_eligible' => $data['reason_not_eligible'] ?? null,
            'source' => $data['source'] ?? 'other',
            'notes' => $data['notes'] ?? null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE hoas
            SET
                name = :name,
                city = :city,
                state = :state,
                hoa_type = :hoa_type,
                eligibility_status = :eligibility_status,
                reason_not_eligible = :reason_not_eligible,
                source = :source,
                notes = :notes
            WHERE id = :id
        ");

        $stmt->execute([
            'id' => $id,
            'name' => $data['name'],
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'hoa_type' => $data['hoa_type'] ?? 'unknown',
            'eligibility_status' => $data['eligibility_status'] ?? 'potential',
            'reason_not_eligible' => $data['reason_not_eligible'] ?? null,
            'source' => $data['source'] ?? 'other',
            'notes' => $data['notes'] ?? null,
        ]);
    }
}
