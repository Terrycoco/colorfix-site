<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoPropertyRepository
{
    public function __construct(private PDO $pdo) {}

    public function list(array $filters = [], int $limit = 200): array
    {
        $where = [];
        $params = [];

        $clientId = (int)($filters['client_id'] ?? 0);
        if ($clientId > 0) {
            $where[] = 'p.client_id = :client_id';
            $params[':client_id'] = $clientId;
        }

        $limit = max(1, min(1000, $limit));
        $sql = $this->selectSql();
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY p.name ASC, p.id ASC LIMIT {$limit}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare($this->selectSql() . ' WHERE p.id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO properties (
                address_id,
                client_id,
                name,
                notes
            ) VALUES (
                :address_id,
                :client_id,
                :name,
                :notes
            )
        ");
        $stmt->execute([
            ':address_id' => isset($data['address_id']) && (int)$data['address_id'] > 0 ? (int)$data['address_id'] : null,
            ':client_id' => isset($data['client_id']) ? (int)$data['client_id'] : null,
            ':name' => $data['name'] ?? null,
            ':notes' => $data['notes'] ?? null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        $allowed = ['address_id', 'client_id', 'name', 'notes'];
        $set = [];
        $params = [':id' => $id];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $set[] = "{$key} = :{$key}";
            $params[":{$key}"] = in_array($key, ['address_id', 'client_id'], true) && $value !== null ? (int)$value : $value;
        }
        if (!$set) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE properties SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = :id');
        $stmt->execute($params);
    }

    private function selectSql(): string
    {
        return "SELECT
                    p.id,
                    p.address_id,
                    p.client_id,
                    p.name,
                    p.notes,
                    p.created_at,
                    p.updated_at,
                    a.street_1,
                    a.street_2,
                    a.city,
                    a.state,
                    a.postal_code,
                    a.country_code,
                    c.name AS client_name,
                    c.first_name AS client_first_name,
                    c.last_name AS client_last_name,
                    c.email AS client_email
                FROM properties p
                LEFT JOIN addresses a ON a.id = p.address_id
                LEFT JOIN clients c ON c.id = p.client_id";
    }
}
