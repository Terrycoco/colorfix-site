<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoLegacyProjectLinkRepository
{
    public function __construct(private PDO $pdo) {}

    public function listByProjectId(int $projectId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                id,
                project_id,
                asset_type,
                asset_id,
                role,
                sort_order,
                notes,
                created_at
            FROM legacy_project_links
            WHERE project_id = :project_id
            ORDER BY asset_type ASC, sort_order ASC, id ASC
        ");
        $stmt->execute([':project_id' => $projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'project_id' => (int)$row['project_id'],
                'asset_type' => (string)$row['asset_type'],
                'asset_id' => (int)$row['asset_id'],
                'role' => $row['role'] !== null ? (string)$row['role'] : '',
                'sort_order' => (int)$row['sort_order'],
                'notes' => $row['notes'] !== null ? (string)$row['notes'] : '',
                'created_at' => $row['created_at'],
            ];
        }, $rows);
    }

    public function insert(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO legacy_project_links (
                project_id,
                asset_type,
                asset_id,
                role,
                sort_order,
                notes
            ) VALUES (
                :project_id,
                :asset_type,
                :asset_id,
                :role,
                :sort_order,
                :notes
            )
        ");
        $stmt->execute([
            ':project_id' => $data['project_id'],
            ':asset_type' => $data['asset_type'],
            ':asset_id' => $data['asset_id'],
            ':role' => $data['role'] ?? null,
            ':sort_order' => $data['sort_order'] ?? 0,
            ':notes' => $data['notes'] ?? null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, int $projectId, array $data): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE legacy_project_links
            SET asset_type = :asset_type,
                asset_id = :asset_id,
                role = :role,
                sort_order = :sort_order,
                notes = :notes
            WHERE id = :id
              AND project_id = :project_id
        ");
        $stmt->execute([
            ':asset_type' => $data['asset_type'],
            ':asset_id' => $data['asset_id'],
            ':role' => $data['role'] ?? null,
            ':sort_order' => $data['sort_order'] ?? 0,
            ':notes' => $data['notes'] ?? null,
            ':id' => $id,
            ':project_id' => $projectId,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM legacy_project_links WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
