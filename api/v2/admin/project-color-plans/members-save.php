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
    $planId = isset($data['project_color_plan_id']) ? (int)$data['project_color_plan_id'] : 0;
    $repo = new PdoProjectColorPlanRepository($pdo);
    color_plan_assert_plan_for_project($repo, $planId);
    $rows = is_array($data['members'] ?? null) ? $data['members'] : [];
    $deleteIds = is_array($data['delete_ids'] ?? null) ? array_values(array_unique(array_map('intval', $data['delete_ids']))) : [];
    $repo->saveMembers($planId, array_map(static function (mixed $row): array {
        $row = is_array($row) ? $row : [];
        return [
            'id' => isset($row['id']) ? (int)$row['id'] : null,
            'color_id' => isset($row['color_id']) ? (int)$row['color_id'] : 0,
            'role_name' => color_plan_optional_string($row['role_name'] ?? null),
            'sheen' => color_plan_optional_string($row['sheen'] ?? null),
            'note' => color_plan_optional_string($row['note'] ?? null),
            'order_index' => isset($row['order_index']) ? (int)$row['order_index'] : 0,
        ];
    }, $rows), $deleteIds);
    workflow_respond(['ok' => true]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
