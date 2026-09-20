<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/admin/project-color-plans/_helpers.php';

use App\PROJECTS\Repos\PdoProjectColorPlanRepository;
use App\PROJECTS\Repos\PdoProjectRepository;
use App\Repos\PdoUrlReservationRepository;
use App\Services\UrlReservationService;
use App\Services\UrlReservations\UrlReservationRegistryFactory;

function painter_specs_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function painter_specs_viewer_form_payload(array $row): array
{
    return [
        'scheme_title' => (string)($row['scheme_title'] ?? ''),
        'overall_painter_note' => (string)($row['overall_painter_note'] ?? ''),
    ];
}

function painter_specs_viewer_photo_payload(array $row): array
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
        painter_specs_respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $token = trim((string)($_GET['reservation_token'] ?? $_GET['token'] ?? ''));
    if ($token === '') {
        painter_specs_respond(['ok' => false, 'error' => 'reservation_token required'], 400);
    }

    $reservationService = new UrlReservationService(
        new PdoUrlReservationRepository($pdo),
        UrlReservationRegistryFactory::create($pdo)
    );
    $reserved = $reservationService->resolveReservation($token);
    $reservation = is_array($reserved['reservation'] ?? null) ? $reserved['reservation'] : [];
    $resolution = is_array($reserved['resolution'] ?? null) ? $reserved['resolution'] : [];

    if (($reservation['type_key'] ?? '') !== 'project_experience') {
        painter_specs_respond(['ok' => false, 'error' => 'Painter specs unavailable'], 404);
    }
    $experienceKey = strtolower(trim((string)($resolution['experience_key'] ?? $reservation['experience_key'] ?? '')));
    if ($experienceKey !== 'painter') {
        painter_specs_respond(['ok' => false, 'error' => 'Painter specs unavailable'], 404);
    }
    $projectId = (int)($resolution['project_id'] ?? $resolution['destination']['project_id'] ?? 0);
    if ($projectId <= 0) {
        painter_specs_respond(['ok' => false, 'error' => 'Project not found'], 404);
    }

    $projectRepo = new PdoProjectRepository($pdo);
    $project = $projectRepo->findById($projectId);
    if (!$project) {
        painter_specs_respond(['ok' => false, 'error' => 'Project not found'], 404);
    }

    $planRepo = new PdoProjectColorPlanRepository($pdo);
    $plans = array_map(static function (array $row): array {
        $payload = color_plan_plan_payload($row);
        $payload['members'] = array_map('color_plan_member_payload', $row['members'] ?? []);
        $painterViewer = $row['painter_viewer'] ?? null;
        $payload['painter_viewer'] = [
            'form' => painter_specs_viewer_form_payload($painterViewer['row'] ?? []),
            'photos' => array_map('painter_specs_viewer_photo_payload', $painterViewer['photos'] ?? []),
        ];
        return $payload;
    }, $planRepo->listForProjectWithMembers($projectId));

    painter_specs_respond([
        'ok' => true,
        'data' => [
            'reservation' => [
                'token' => $token,
                'public_url' => $reservation['public_url'] ?? '',
            ],
            'project' => [
                'id' => (int)$project['id'],
                'name' => (string)($project['project_name'] ?? ''),
                'property_name' => (string)($project['property_name'] ?? ''),
                'client_name' => (string)($project['client_name'] ?? ''),
                'street_1' => (string)($project['street_1'] ?? ''),
                'street_2' => (string)($project['street_2'] ?? ''),
                'city' => (string)($project['city'] ?? ''),
                'state' => (string)($project['state'] ?? ''),
                'postal_code' => (string)($project['postal_code'] ?? ''),
                'country_code' => (string)($project['country_code'] ?? ''),
            ],
            'plans' => $plans,
        ],
    ]);
} catch (Throwable) {
    painter_specs_respond(['ok' => false, 'error' => 'Painter specs unavailable'], 404);
}
