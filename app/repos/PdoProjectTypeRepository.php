<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoProjectTypeRepository
{
    public function __construct(private PDO $pdo) {}

    public function list(bool $activeOnly = false): array
    {
        $where = $activeOnly ? ' WHERE is_active = 1' : '';
        $stmt = $this->pdo->prepare("SELECT * FROM project_types{$where} ORDER BY sort_order ASC, name ASC, id ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM project_types WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM project_types WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO project_types (
                name,
                slug,
                is_active,
                sort_order
            ) VALUES (
                :name,
                :slug,
                :is_active,
                :sort_order
            )
        ");
        $stmt->execute([
            ':name' => $data['name'],
            ':slug' => $data['slug'],
            ':is_active' => (int)($data['is_active'] ?? 1),
            ':sort_order' => (int)($data['sort_order'] ?? 0),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        $allowed = ['name', 'slug', 'is_active', 'sort_order'];
        $set = [];
        $params = [':id' => $id];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $set[] = "{$key} = :{$key}";
            $params[":{$key}"] = in_array($key, ['is_active', 'sort_order'], true) ? (int)$value : $value;
        }
        if (!$set) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE project_types SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = :id');
        $stmt->execute($params);
    }
}
