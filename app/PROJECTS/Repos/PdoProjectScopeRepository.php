<?php
declare(strict_types=1);

namespace App\PROJECTS\Repos;

use PDO;
use RuntimeException;

final class PdoProjectScopeRepository
{
    public function __construct(
        private PDO $pdo
    ) {}


    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByProjectId(
        int $projectId
    ): array {
        if ($projectId <= 0) {
            return [];
        }

        $stmt =
            $this->pdo->prepare(
                'SELECT
                    id,
                    project_id,
                    scope_kind,
                    title,
                    project_goal,
                    areas_covered,
                    scope_fee,
                    status,
                    created_at,
                    updated_at
                 FROM project_scopes
                 WHERE project_id = :project_id
                 ORDER BY
                    CASE
                        WHEN scope_kind = \'main\' THEN 0
                        ELSE 1
                    END,
                    id ASC'
            );

        $stmt->execute([
            ':project_id' =>
                $projectId,
        ]);

        return
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            )
            ?: [];
    }


    /**
     * @return array<string, mixed>|null
     */
    public function findById(
        int $scopeId
    ): ?array {
        if ($scopeId <= 0) {
            return null;
        }

        $stmt =
            $this->pdo->prepare(
                'SELECT
                    id,
                    project_id,
                    scope_kind,
                    title,
                    project_goal,
                    areas_covered,
                    scope_fee,
                    status,
                    created_at,
                    updated_at
                 FROM project_scopes
                 WHERE id = :scope_id
                 LIMIT 1'
            );

        $stmt->execute([
            ':scope_id' =>
                $scopeId,
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return
            $row
            ?: null;
    }


    /**
     * @return array<string, mixed>|null
     */
    public function findMainByProjectId(
        int $projectId
    ): ?array {
        if ($projectId <= 0) {
            return null;
        }

        $stmt =
            $this->pdo->prepare(
                'SELECT
                    id,
                    project_id,
                    scope_kind,
                    title,
                    project_goal,
                    areas_covered,
                    scope_fee,
                    status,
                    created_at,
                    updated_at
                 FROM project_scopes
                 WHERE project_id = :project_id
                   AND scope_kind = \'main\'
                 ORDER BY id ASC
                 LIMIT 1'
            );

        $stmt->execute([
            ':project_id' =>
                $projectId,
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return
            $row
            ?: null;
    }


    public function create(
        int $projectId,
        string $scopeKind,
        string $title,
        ?string $projectGoal,
        ?string $areasCovered,
        ?string $scopeFee,
        string $status
    ): int {
        if ($projectId <= 0) {
            throw new RuntimeException(
                'Valid project ID required.'
            );
        }

        $stmt =
            $this->pdo->prepare(
                'INSERT INTO project_scopes (
                    project_id,
                    scope_kind,
                    title,
                    project_goal,
                    areas_covered,
                    scope_fee,
                    status
                 ) VALUES (
                    :project_id,
                    :scope_kind,
                    :title,
                    :project_goal,
                    :areas_covered,
                    :scope_fee,
                    :status
                 )'
            );

        $stmt->execute([
            ':project_id' =>
                $projectId,

            ':scope_kind' =>
                $scopeKind,

            ':title' =>
                $title,

            ':project_goal' =>
                $projectGoal,

            ':areas_covered' =>
                $areasCovered,

            ':scope_fee' =>
                $scopeFee,

            ':status' =>
                $status,
        ]);

        return
            (int)$this->pdo
                ->lastInsertId();
    }


    public function update(
        int $scopeId,
        string $scopeKind,
        string $title,
        ?string $projectGoal,
        ?string $areasCovered,
        ?string $scopeFee,
        string $status
    ): bool {
        if ($scopeId <= 0) {
            throw new RuntimeException(
                'Valid scope ID required.'
            );
        }

        $stmt =
            $this->pdo->prepare(
                'UPDATE project_scopes
                    SET scope_kind = :scope_kind,
                        title = :title,
                        project_goal = :project_goal,
                        areas_covered = :areas_covered,
                        scope_fee = :scope_fee,
                        status = :status
                  WHERE id = :scope_id'
            );

        $stmt->execute([
            ':scope_id' =>
                $scopeId,

            ':scope_kind' =>
                $scopeKind,

            ':title' =>
                $title,

            ':project_goal' =>
                $projectGoal,

            ':areas_covered' =>
                $areasCovered,

            ':scope_fee' =>
                $scopeFee,

            ':status' =>
                $status,
        ]);

        return true;
    }
}
