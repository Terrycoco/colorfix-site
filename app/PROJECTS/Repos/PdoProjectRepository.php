<?php
declare(strict_types=1);

namespace App\PROJECTS\Repos;

use PDO;
use RuntimeException;

final class PdoProjectRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAdminRows(): array
    {
        $stmt = $this->pdo->query(
            'SELECT
                p.id,
                p.client_id,
                p.property_id,
                p.playlist_id,
                c.name AS client_name,
                pr.name AS property_name,
                pl.title AS playlist_title
             FROM projects p
             INNER JOIN clients c
               ON c.id = p.client_id
             INNER JOIN properties pr
               ON pr.id = p.property_id
             INNER JOIN playlists pl
               ON pl.playlist_id = p.playlist_id
             ORDER BY p.id DESC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(
        int $projectId
    ): ?array {
        if ($projectId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                p.id,
                p.client_id,
                p.property_id,
                p.playlist_id,
                c.name AS client_name,
                pr.name AS property_name,
                pl.title AS playlist_title
             FROM projects p
             INNER JOIN clients c
               ON c.id = p.client_id
             INNER JOIN properties pr
               ON pr.id = p.property_id
             INNER JOIN playlists pl
               ON pl.playlist_id = p.playlist_id
             WHERE p.id = :project_id
             LIMIT 1'
        );

        $stmt->execute([
            ':project_id' => $projectId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function create(
        int $clientId,
        int $propertyId,
        int $playlistId
    ): int {
        $this->validateIds(
            $clientId,
            $propertyId,
            $playlistId
        );

        $stmt = $this->pdo->prepare(
            'INSERT INTO projects (
                client_id,
                property_id,
                playlist_id
             ) VALUES (
                :client_id,
                :property_id,
                :playlist_id
             )'
        );

        $stmt->execute([
            ':client_id' => $clientId,
            ':property_id' => $propertyId,
            ':playlist_id' => $playlistId,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(
        int $projectId,
        int $clientId,
        int $propertyId,
        int $playlistId
    ): bool {
        if ($projectId <= 0) {
            throw new RuntimeException(
                'Valid project ID required.'
            );
        }

        $this->validateIds(
            $clientId,
            $propertyId,
            $playlistId
        );

        $stmt = $this->pdo->prepare(
            'UPDATE projects
                SET client_id = :client_id,
                    property_id = :property_id,
                    playlist_id = :playlist_id
              WHERE id = :project_id'
        );

        $stmt->execute([
            ':project_id' => $projectId,
            ':client_id' => $clientId,
            ':property_id' => $propertyId,
            ':playlist_id' => $playlistId,
        ]);

        return $stmt->rowCount() >= 0;
    }

    public function deleteById(
        int $projectId
    ): int {
        if ($projectId <= 0) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM projects
              WHERE id = :project_id'
        );

        $stmt->execute([
            ':project_id' => $projectId,
        ]);

        return $stmt->rowCount();
    }

    public function playlistInUseByAnotherProject(
        int $playlistId,
        ?int $excludeProjectId = null
    ): bool {
        if ($playlistId <= 0) {
            return false;
        }

        $sql =
            'SELECT 1
               FROM projects
              WHERE playlist_id = :playlist_id';

        $params = [
            ':playlist_id' => $playlistId,
        ];

        if (
            $excludeProjectId !== null
            && $excludeProjectId > 0
        ) {
            $sql .=
                ' AND id <> :exclude_project_id';

            $params[
                ':exclude_project_id'
            ] = $excludeProjectId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare(
            $sql
        );

        $stmt->execute(
            $params
        );

        return (bool)$stmt->fetchColumn();
    }

    private function validateIds(
        int $clientId,
        int $propertyId,
        int $playlistId
    ): void {
        if ($clientId <= 0) {
            throw new RuntimeException(
                'Valid client ID required.'
            );
        }

        if ($propertyId <= 0) {
            throw new RuntimeException(
                'Valid property ID required.'
            );
        }

        if ($playlistId <= 0) {
            throw new RuntimeException(
                'Valid playlist ID required.'
            );
        }
    }
}
