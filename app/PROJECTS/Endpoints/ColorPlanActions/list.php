<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

use App\PROJECTS\Repos\PdoProjectColorPlanRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond(['ok' => false, 'error' => 'GET only'], 405);
    }
    $projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
    $repo = new PdoProjectColorPlanRepository($pdo);
    color_plan_assert_project_exists($repo, $projectId);

    workflow_respond([
        'ok' => true,
        'items' => array_map('color_plan_plan_payload', $repo->listForProject($projectId)),
    ]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
