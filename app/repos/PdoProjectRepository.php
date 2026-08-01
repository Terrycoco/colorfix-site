<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoProjectRepository
{
    public function __construct(private PDO $pdo) {}

    public function list(array $filters = [], int $limit = 200): array
    {
        $where = [];
        $params = [];

        $propertyId = (int)($filters['property_id'] ?? 0);
        $projectTypeId = (int)($filters['project_type_id'] ?? 0);
        $status = trim((string)($filters['status'] ?? ''));

        if ($propertyId > 0) {
            $where[] = 'p.property_id = :property_id';
            $params[':property_id'] = $propertyId;
        }
        if ($projectTypeId > 0) {
            $where[] = 'p.project_type_id = :project_type_id';
            $params[':project_type_id'] = $projectTypeId;
        }
        if ($status !== '') {
            $where[] = 'p.status = :status';
            $params[':status'] = $status;
        }

        $limit = max(1, min(1000, $limit));
        $sql = $this->selectSql();
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY p.updated_at DESC, p.id DESC LIMIT {$limit}";

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
            INSERT INTO projects (
                property_id,
                project_type_id,
                name,
                status,
                notes
            ) VALUES (
                :property_id,
                :project_type_id,
                :name,
                :status,
                :notes
            )
        ");
        $stmt->execute([
            ':property_id' => (int)$data['property_id'],
            ':project_type_id' => (int)$data['project_type_id'],
            ':name' => $data['name'] ?? null,
            ':status' => $data['status'] ?? 'prospect',
            ':notes' => $data['notes'] ?? null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        if (!$fields) {
            return;
        }

        $allowed = ['property_id', 'project_type_id', 'name', 'status', 'notes'];
        $set = [];
        $params = [':id' => $id];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $set[] = "{$key} = :{$key}";
            $params[":{$key}"] = in_array($key, ['property_id', 'project_type_id'], true) ? (int)$value : $value;
        }
        if (!$set) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE projects SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = :id');
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM projects WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    private function selectSql(): string
    {
        return "SELECT
                    p.id,
                    p.property_id,
                    p.project_type_id,
                    p.name,
                    p.status,
                    p.notes,
                    p.created_at,
                    p.updated_at,
                    pt.name AS project_type_name,
                    pt.slug AS project_type_slug,
                    pr.name AS property_name,
                    pr.client_id,
                    a.street_1,
                    a.street_2,
                    a.city,
                    a.state,
                    a.postal_code,
                    a.country_code
                FROM projects p
                INNER JOIN properties pr ON pr.id = p.property_id
                INNER JOIN addresses a ON a.id = pr.address_id
                INNER JOIN project_types pt ON pt.id = p.project_type_id";
    }
}
