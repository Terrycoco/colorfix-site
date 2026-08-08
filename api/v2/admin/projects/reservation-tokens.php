<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';

use App\Repos\PdoProjectRepository;
use App\Repos\PdoUrlReservationRepository;
use App\Repos\PdoUrlReservationResourceRepository;
use App\Services\UrlReservationService;
use App\Services\UrlReservations\UrlReservationRegistryFactory;

const PROJECT_RESERVATION_EXPERIENCES = ['public', 'concept', 'client', 'painter'];

function project_reservation_service(PDO $pdo): UrlReservationService
{
    return new UrlReservationService(
        new PdoUrlReservationRepository($pdo),
        UrlReservationRegistryFactory::create(new PdoUrlReservationResourceRepository($pdo)),
        workflow_public_base_url()
    );
}

function workflow_public_base_url(): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'colorfix.terrymarr.com'));
    return 'https://' . ($host !== '' ? $host : 'colorfix.terrymarr.com');
}

function project_reservation_payload(UrlReservationService $service, int $projectId): array
{
    $items = [];
    foreach (PROJECT_RESERVATION_EXPERIENCES as $experienceKey) {
        $reservation = $service->getActiveProjectExperienceReservation($projectId, $experienceKey);
        $items[$experienceKey] = [
            'experience_key' => $experienceKey,
            'reservation' => $reservation,
        ];
    }
    return $items;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $projectRepo = new PdoProjectRepository($pdo);
    $service = project_reservation_service($pdo);

    if ($method === 'GET') {
        $projectId = (int)($_GET['project_id'] ?? 0);
        if ($projectId <= 0 || !$projectRepo->findById($projectId)) {
            workflow_respond(['ok' => false, 'error' => 'Project not found'], 404);
        }
        workflow_respond([
            'ok' => true,
            'items' => project_reservation_payload($service, $projectId),
        ]);
    }

    if ($method !== 'POST') {
        workflow_respond(['ok' => false, 'error' => 'GET or POST only'], 405);
    }

    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) {
        workflow_respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }
    $projectId = (int)($data['project_id'] ?? 0);
    $experienceKey = strtolower(trim((string)($data['experience_key'] ?? '')));
    $regenerate = (bool)($data['regenerate'] ?? false);
    if ($projectId <= 0 || !$projectRepo->findById($projectId)) {
        workflow_respond(['ok' => false, 'error' => 'Project not found'], 404);
    }
    if (!in_array($experienceKey, PROJECT_RESERVATION_EXPERIENCES, true)) {
        workflow_respond(['ok' => false, 'error' => 'Invalid experience'], 400);
    }

    $created = $service->reserveProjectExperienceAdminToken($projectId, $experienceKey, $regenerate);
    workflow_respond([
        'ok' => true,
        'reservation' => $created,
        'items' => project_reservation_payload($service, $projectId),
    ]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
