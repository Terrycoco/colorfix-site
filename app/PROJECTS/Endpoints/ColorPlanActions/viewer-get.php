<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

use App\PROJECTS\Repos\PdoProjectColorPlanRepository;

function viewer_form_payload(array $row): array
{
    return [
        'title' => (string)($row['concept_title'] ?? ''),
        'challenge' => (string)($row['challenge'] ?? ''),
        'design_direction' => (string)($row['design_direction'] ?? ''),
        'scheme_title' => (string)($row['scheme_title'] ?? ''),
        'final_design_description' => (string)($row['final_design_description'] ?? ''),
        'overall_painter_note' => (string)($row['overall_painter_note'] ?? ''),
    ];
}

function viewer_photo_payload(array $row): array
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
    $planId = isset($_GET['project_color_plan_id']) ? (int)$_GET['project_color_plan_id'] : 0;
    $repo = new PdoProjectColorPlanRepository($pdo);
    color_plan_assert_plan_for_project($repo, $planId);

    $viewers = [
        'concept' => ['form' => [], 'photos' => []],
        'client' => ['form' => [], 'photos' => []],
        'painter' => ['form' => [], 'photos' => []],
    ];
    foreach ($repo->viewersForPlan($planId) as $key => $viewer) {
        if (!isset($viewers[$key])) {
            continue;
        }
        $viewers[$key] = [
            'form' => viewer_form_payload($viewer['row'] ?? []),
            'photos' => array_map('viewer_photo_payload', $viewer['photos'] ?? []),
        ];
    }

    workflow_respond(['ok' => true, 'viewers' => $viewers]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
