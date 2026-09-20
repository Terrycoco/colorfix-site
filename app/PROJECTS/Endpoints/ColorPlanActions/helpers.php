<?php
declare(strict_types=1);

use App\PROJECTS\Repos\PdoProjectColorPlanRepository;

function workflow_respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function color_plan_optional_string(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    $normalized = trim((string)$value);
    return $normalized !== '' ? $normalized : null;
}

function color_plan_optional_datetime(mixed $value): ?string
{
    $value = color_plan_optional_string($value);
    if ($value === null) {
        return null;
    }
    $time = strtotime($value);
    if ($time === false) {
        throw new InvalidArgumentException('Invalid date/time');
    }
    return date('Y-m-d H:i:s', $time);
}

function color_plan_plan_payload(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'project_id' => (int)$row['project_id'],
        'palette_type' => (string)($row['palette_type'] ?? 'exterior'),
        'nickname' => (string)($row['nickname'] ?? ''),
        'notes' => (string)($row['notes'] ?? ''),
        'private_notes' => (string)($row['private_notes'] ?? ''),
        'area_name' => (string)($row['area_name'] ?? ''),
        'scheme_title' => (string)($row['scheme_title'] ?? ''),
        'revision_number' => (int)($row['revision_number'] ?? 1),
        'issued_at' => $row['issued_at'] ?? null,
        'locked_at' => $row['locked_at'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function color_plan_member_payload(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'project_color_plan_id' => (int)$row['project_color_plan_id'],
        'color_id' => (int)$row['color_id'],
        'role_name' => (string)($row['role_name'] ?? ''),
        'sheen' => (string)($row['sheen'] ?? ''),
        'note' => (string)($row['note'] ?? ''),
        'order_index' => (int)($row['order_index'] ?? 0),
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'color' => [
            'id' => (int)$row['color_id'],
            'name' => (string)($row['color_name'] ?? ''),
            'code' => (string)($row['color_code'] ?? ''),
            'brand' => (string)($row['color_brand'] ?? ''),
            'brand_name' => (string)($row['color_brand_name'] ?? $row['color_brand'] ?? ''),
            'hex6' => (string)($row['hex6'] ?? ''),
            'r' => isset($row['r']) ? (int)$row['r'] : null,
            'g' => isset($row['g']) ? (int)$row['g'] : null,
            'b' => isset($row['b']) ? (int)$row['b'] : null,
            'hcl_l' => isset($row['hcl_l']) ? (float)$row['hcl_l'] : null,
        ],
    ];
}

function color_plan_assert_project_exists(PdoProjectColorPlanRepository $repo, int $projectId): void
{
    if ($projectId <= 0) {
        workflow_respond(['ok' => false, 'error' => 'project_id required'], 400);
    }
    if (!$repo->projectExists($projectId)) {
        workflow_respond(['ok' => false, 'error' => 'Project not found'], 404);
    }
}

function color_plan_assert_plan_for_project(PdoProjectColorPlanRepository $repo, int $planId, ?int $projectId = null): array
{
    if ($planId <= 0) {
        workflow_respond(['ok' => false, 'error' => 'id required'], 400);
    }
    $row = $repo->findPlan($planId, $projectId);
    if (!$row) {
        workflow_respond(['ok' => false, 'error' => 'Color Plan not found'], 404);
    }
    return $row;
}
