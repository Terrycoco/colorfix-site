<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoProjectRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function listWithAssetCounts(array $filters = []): array
    {
        $where = [];
        $params = [];

        $status = trim((string)($filters['status'] ?? ''));
        $projectType = trim((string)($filters['project_type'] ?? ''));
        $query = trim((string)($filters['q'] ?? ''));

        if ($status !== '') {
            $where[] = 'p.status = :status';
            $params[':status'] = $status;
        }
        if ($projectType !== '') {
            $where[] = 'p.project_type = :project_type';
            $params[':project_type'] = $projectType;
        }
        if ($query !== '') {
            $where[] = '(p.title LIKE :q OR p.slug LIKE :q OR p.client_name LIKE :q)';
            $params[':q'] = '%' . $query . '%';
        }

        $sql = "
            SELECT
                p.id,
                p.slug,
                p.title,
                p.project_type,
                p.status,
                p.summary,
                p.notes,
                p.client_name,
                p.created_at,
                p.updated_at,
                pl.asset_type
            FROM projects p
            LEFT JOIN project_links pl ON pl.project_id = p.id
        ";

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY p.updated_at DESC, p.id DESC';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $projects = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (!isset($projects[$id])) {
                $projects[$id] = [
                    'id' => $id,
                    'slug' => (string)$row['slug'],
                    'title' => (string)$row['title'],
                    'project_type' => (string)$row['project_type'],
                    'status' => (string)$row['status'],
                    'summary' => $row['summary'] !== null ? (string)$row['summary'] : '',
                    'notes' => $row['notes'] !== null ? (string)$row['notes'] : '',
                    'client_name' => $row['client_name'] !== null ? (string)$row['client_name'] : '',
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                    'asset_counts' => [
                        'total' => 0,
                        'photo' => 0,
                        'playlist' => 0,
                        'article' => 0,
                        'pin' => 0,
                        'palette' => 0,
                    ],
                ];
            }

            $assetType = trim((string)($row['asset_type'] ?? ''));
            if ($assetType !== '') {
                $projects[$id]['asset_counts']['total']++;
                if (array_key_exists($assetType, $projects[$id]['asset_counts'])) {
                    $projects[$id]['asset_counts'][$assetType]++;
                }
            }
        }

        return array_values($projects);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'slug' => (string)$row['slug'],
            'title' => (string)$row['title'],
            'project_type' => (string)$row['project_type'],
            'status' => (string)$row['status'],
            'summary' => $row['summary'] !== null ? (string)$row['summary'] : '',
            'notes' => $row['notes'] !== null ? (string)$row['notes'] : '',
            'client_name' => $row['client_name'] !== null ? (string)$row['client_name'] : '',
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    public function insert(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO projects (
                slug,
                title,
                project_type,
                status,
                summary,
                notes,
                client_name
            ) VALUES (
                :slug,
                :title,
                :project_type,
                :status,
                :summary,
                :notes,
                :client_name
            )
        ");
        $stmt->execute([
            ':slug' => $data['slug'],
            ':title' => $data['title'],
            ':project_type' => $data['project_type'],
            ':status' => $data['status'],
            ':summary' => $data['summary'] ?? null,
            ':notes' => $data['notes'] ?? null,
            ':client_name' => $data['client_name'] ?? null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE projects
            SET slug = :slug,
                title = :title,
                project_type = :project_type,
                status = :status,
                summary = :summary,
                notes = :notes,
                client_name = :client_name
            WHERE id = :id
        ");
        $stmt->execute([
            ':slug' => $data['slug'],
            ':title' => $data['title'],
            ':project_type' => $data['project_type'],
            ':status' => $data['status'],
            ':summary' => $data['summary'] ?? null,
            ':notes' => $data['notes'] ?? null,
            ':client_name' => $data['client_name'] ?? null,
            ':id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM projects WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
