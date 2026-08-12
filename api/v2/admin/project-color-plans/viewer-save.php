<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/_helpers.php';

use App\Repos\PdoProjectColorPlanRepository;
use App\Services\ProjectViewerRexService;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond(['ok' => false, 'error' => 'POST only'], 405);
    }
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) {
        workflow_respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }
    $planId = isset($data['project_color_plan_id']) ? (int)$data['project_color_plan_id'] : 0;
    $viewerKey = color_plan_optional_string($data['viewer_key'] ?? null) ?? '';
    $repo = new PdoProjectColorPlanRepository($pdo);
    color_plan_assert_plan_for_project($repo, $planId);
    if (!in_array($viewerKey, ['concept', 'client', 'painter'], true)) {
        workflow_respond(['ok' => false, 'error' => 'Invalid viewer_key'], 400);
    }

    $form = is_array($data['form'] ?? null) ? $data['form'] : [];
    $photos = is_array($data['photos'] ?? null) ? $data['photos'] : [];
    $repo->saveViewer($planId, $viewerKey, [
        'title' => color_plan_optional_string($form['title'] ?? null),
        'concept_title' => color_plan_optional_string($form['concept_title'] ?? null),
        'challenge' => color_plan_optional_string($form['challenge'] ?? null),
        'design_direction' => color_plan_optional_string($form['design_direction'] ?? null),
        'scheme_title' => color_plan_optional_string($form['scheme_title'] ?? null),
        'final_design_description' => color_plan_optional_string($form['final_design_description'] ?? null),
        'overall_painter_note' => color_plan_optional_string($form['overall_painter_note'] ?? null),
    ], array_map(static function (mixed $row): array {
        $row = is_array($row) ? $row : [];
        return [
            'photo_library_id' => isset($row['photo_library_id']) && (int)$row['photo_library_id'] > 0 ? (int)$row['photo_library_id'] : null,
            'rel_path' => color_plan_optional_string($row['rel_path'] ?? null),
            'photo_type' => color_plan_optional_string($row['photo_type'] ?? null) ?? 'FULL',
            'order_index' => isset($row['order_index']) ? (int)$row['order_index'] : 0,
        ];
    }, $photos));

    $rexPayload = null;
    $rexWarning = null;
    try {
        $rexPayload = (new ProjectViewerRexService($pdo))->ensureColorPlanViewer(
            $planId,
            $viewerKey
        );
    } catch (Throwable $rexError) {
        $rexWarning = $rexError->getMessage();
    }

    $response = [
        'ok' => true,
        'rex' => $rexPayload,
    ];
    if ($rexWarning !== null && trim($rexWarning) !== '') {
        $response['rex_warning'] = $rexWarning;
    } elseif (is_array($rexPayload) && !empty($rexPayload['relationship']['warning'])) {
        $response['rex_warning'] = $rexPayload['relationship']['warning'];
    }

    workflow_respond($response);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
