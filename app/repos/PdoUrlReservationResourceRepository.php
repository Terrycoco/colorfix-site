<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoUrlReservationResourceRepository
{
    public function __construct(private PDO $pdo) {}

    public function findProject(int $projectId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.id,
                    p.name,
                    p.status,
                    p.experience_key,
                    pp.playlist_id,
                    pl.title AS playlist_title
               FROM projects p
               LEFT JOIN project_playlists pp
                 ON pp.project_id = p.id
               LEFT JOIN playlists pl
                 ON pl.playlist_id = pp.playlist_id
              WHERE p.id = :id
              ORDER BY pp.project_playlist_id ASC
              LIMIT 1"
        );
        $stmt->execute([':id' => $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findColorPlan(int $colorPlanId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT cp.id,
                    cp.project_id,
                    cp.nickname,
                    cp.area_name,
                    cp.scheme_title,
                    p.name AS project_name
               FROM project_color_plans cp
               INNER JOIN projects p
                 ON p.id = cp.project_id
              WHERE cp.id = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $colorPlanId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function colorPlanViewerExists(int $colorPlanId, string $viewerKey): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM project_color_plan_viewers WHERE project_color_plan_id = :id AND viewer_key = :viewer_key LIMIT 1'
        );
        $stmt->execute([
            ':id' => $colorPlanId,
            ':viewer_key' => $viewerKey,
        ]);
        return (bool)$stmt->fetchColumn();
    }
}
