<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/_helpers.php';

use App\Repos\PdoProjectColorPlanRepository;

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
