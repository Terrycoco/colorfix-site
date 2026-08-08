<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/_helpers.php';

use App\Repos\PdoProjectColorPlanRepository;

function project_specs_viewer_form_payload(array $row): array
{
    return [
        'scheme_title' => (string)($row['scheme_title'] ?? ''),
        'overall_painter_note' => (string)($row['overall_painter_note'] ?? ''),
    ];
}

function project_specs_viewer_photo_payload(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'photo_library_id' => isset($row['photo_library_id']) && $row['photo_library_id'] !== null ? (int)$row['photo_library_id'] : null,
        'rel_path' => (string)($row['rel_path'] ?? ''),
        'photo_type' => (string)($row['photo_type'] ?? 'FULL'),
        'order_index' => (int)($row['order_index'] ?? 0),
        'photo_title' => (string)($row['photo_title'] ?? ''),
        'photo_updated_at' => $row['photo_updated_at'] ?? null,
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
    $repo = new PdoProjectColorPlanRepository($pdo);
    color_plan_assert_project_exists($repo, $projectId);

    $plans = array_map(static function (array $row): array {
        $payload = color_plan_plan_payload($row);
        $payload['members'] = array_map('color_plan_member_payload', $row['members'] ?? []);
        $painterViewer = $row['painter_viewer'] ?? null;
        $payload['painter_viewer'] = [
            'form' => project_specs_viewer_form_payload($painterViewer['row'] ?? []),
            'photos' => array_map('project_specs_viewer_photo_payload', $painterViewer['photos'] ?? []),
        ];
        return $payload;
    }, $repo->listForProjectWithMembers($projectId));

    workflow_respond([
        'ok' => true,
        'items' => $plans,
    ]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
