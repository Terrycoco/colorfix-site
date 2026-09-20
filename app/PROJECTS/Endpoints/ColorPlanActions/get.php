<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

use App\PROJECTS\Repos\PdoProjectColorPlanRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond(['ok' => false, 'error' => 'GET only'], 405);
    }
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
    $repo = new PdoProjectColorPlanRepository($pdo);
    $plan = color_plan_assert_plan_for_project($repo, $id, $projectId ?: null);

    workflow_respond([
        'ok' => true,
        'plan' => color_plan_plan_payload($plan),
        'members' => array_map('color_plan_member_payload', $repo->membersForPlan($id)),
    ]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
