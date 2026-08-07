<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/_helpers.php';

use App\Repos\PdoProjectColorPlanRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond(['ok' => false, 'error' => 'POST only'], 405);
    }
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) {
        workflow_respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $projectId = isset($data['project_id']) ? (int)$data['project_id'] : 0;
    $repo = new PdoProjectColorPlanRepository($pdo);
    color_plan_assert_project_exists($repo, $projectId);
    color_plan_assert_plan_for_project($repo, $id, $projectId);

    workflow_respond([
        'ok' => true,
        'deleted' => $repo->deletePlan($id, $projectId),
    ]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
