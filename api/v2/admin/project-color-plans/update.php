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
    $projectId = isset($data['project_id']) ? (int)$data['project_id'] : null;
    $repo = new PdoProjectColorPlanRepository($pdo);
    color_plan_assert_plan_for_project($repo, $id, $projectId ?: null);

    $paletteType = color_plan_optional_string($data['palette_type'] ?? null) ?? 'exterior';
    if (!in_array($paletteType, ['exterior', 'interior', 'hoa'], true)) {
        workflow_respond(['ok' => false, 'error' => 'Invalid palette_type'], 400);
    }

    $repo->updatePlan($id, [
        'palette_type' => $paletteType,
        'nickname' => color_plan_optional_string($data['nickname'] ?? null),
        'area_name' => color_plan_optional_string($data['area_name'] ?? null),
        'scheme_title' => color_plan_optional_string($data['scheme_title'] ?? null),
        'revision_number' => max(1, (int)($data['revision_number'] ?? 1)),
        'issued_at' => color_plan_optional_datetime($data['issued_at'] ?? null),
    ]);
    workflow_respond(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
