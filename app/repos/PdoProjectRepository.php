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
        $experienceKey = trim((string)($filters['experience_key'] ?? ''));

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
        if ($experienceKey !== '') {
            $where[] = 'p.experience_key = :experience_key';
            $params[':experience_key'] = $experienceKey;
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
            INSERT INTO projects (
                property_id,
                project_type_id,
                name,
                status,
                experience_key,
                current_release,
                notes,
                project_painter_note
            ) VALUES (
                :property_id,
                :project_type_id,
                :name,
                :status,
                :experience_key,
                :current_release,
                :notes,
                :project_painter_note
            )
        ");
        $stmt->execute([
            ':property_id' => (int)$data['property_id'],
            ':project_type_id' => (int)$data['project_type_id'],
            ':name' => $data['name'] ?? null,
            ':status' => $data['status'] ?? 'prospect',
            ':experience_key' => $data['experience_key'] ?? 'concept',
            ':current_release' => $data['current_release'] ?? '1',
            ':notes' => $data['notes'] ?? null,
            ':project_painter_note' => $data['project_painter_note'] ?? null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        if (!$fields) {
            return;
        }

        $allowed = ['property_id', 'project_type_id', 'name', 'status', 'experience_key', 'current_release', 'notes', 'project_painter_note'];
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

    public function playlistExists(int $playlistId): bool
    {
        if ($playlistId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT playlist_id FROM playlists WHERE playlist_id = :playlist_id LIMIT 1');
        $stmt->execute([':playlist_id' => $playlistId]);
        return (bool)$stmt->fetchColumn();
    }

    public function attachPlaylist(int $projectId, int $playlistId): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO project_playlists (project_id, playlist_id)
             VALUES (:project_id, :playlist_id)'
        );
        $stmt->execute([
            ':project_id' => $projectId,
            ':playlist_id' => $playlistId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function removeProjectPlaylist(int $projectId, int $projectPlaylistId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM project_playlists
             WHERE project_playlist_id = :project_playlist_id
               AND project_id = :project_id'
        );
        $stmt->execute([
            ':project_playlist_id' => $projectPlaylistId,
            ':project_id' => $projectId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function listProjectPhotos(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                pp.project_photo_id,
                pp.photo_library_id,
                pp.role,
                pp.sort_order,
                pl.rel_path,
                pl.title,
                pl.alt_text
             FROM project_photos pp
             LEFT JOIN photo_library pl
               ON pl.photo_library_id = pp.photo_library_id
             WHERE pp.project_id = :id
             ORDER BY pp.sort_order ASC, pp.project_photo_id ASC"
        );
        $stmt->execute([':id' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listProjectPlaylists(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                pp.project_playlist_id,
                pp.playlist_id,
                p.title,
                p.slug,
                pp.created_at,
                pp.updated_at
             FROM project_playlists pp
             LEFT JOIN playlists p
               ON p.playlist_id = pp.playlist_id
             WHERE pp.project_id = :id
             ORDER BY pp.updated_at DESC, pp.project_playlist_id DESC"
        );
        $stmt->execute([':id' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function selectSql(): string
    {
        return "SELECT
                    p.id,
                    p.property_id,
                    p.project_type_id,
                    p.name,
                    p.status,
                    p.experience_key,
                    p.current_release,
                    p.notes,
                    p.project_painter_note,
                    p.created_at,
                    p.updated_at,
                    pt.name AS project_type_name,
                    pt.slug AS project_type_slug,
                    pr.name AS property_name,
                    pr.address_id,
                    pr.client_id,
                    c.name AS client_name,
                    c.first_name AS client_first_name,
                    c.last_name AS client_last_name,
                    c.email AS client_email,
                    a.street_1,
                    a.street_2,
                    a.city,
                    a.state,
                    a.postal_code,
                    a.country_code,
                    cp_link.playlist_id AS current_playlist_id,
                    cp.title AS current_playlist_title,
                    cp.slug AS current_playlist_slug,
                    cp_link.updated_at AS current_playlist_updated_at
                FROM projects p
                INNER JOIN properties pr ON pr.id = p.property_id
                LEFT JOIN clients c ON c.id = pr.client_id
                LEFT JOIN addresses a ON a.id = pr.address_id
                INNER JOIN project_types pt ON pt.id = p.project_type_id
                LEFT JOIN project_playlists cp_link
                  ON cp_link.project_playlist_id = (
                    SELECT pp2.project_playlist_id
                    FROM project_playlists pp2
                    WHERE pp2.project_id = p.id
                    ORDER BY pp2.updated_at DESC, pp2.project_playlist_id DESC
                    LIMIT 1
                  )
                LEFT JOIN playlists cp ON cp.playlist_id = cp_link.playlist_id";
    }
}