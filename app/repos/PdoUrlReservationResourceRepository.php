<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoUrlReservationResourceRepository
{
    public function __construct(private PDO $pdo) {}

    public function findColorPlan(int $colorPlanId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT cp.id,
                    cp.project_id,
                    cp.nickname,
                    cp.area_name,
                    cp.scheme_title
               FROM project_color_plans cp
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
