<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';

use App\Repos\PdoProjectRepository;
use App\Repos\PdoPropertyRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        workflow_respond(['ok' => false, 'error' => 'id required'], 400);
    }

    $property = (new PdoPropertyRepository($pdo))->findById($id);
    if (!$property) {
        workflow_respond(['ok' => false, 'error' => 'Property not found'], 404);
    }

    $projects = array_map(
        'workflow_project_payload',
        (new PdoProjectRepository($pdo))->list(['property_id' => $id], 200)
    );

    workflow_respond([
        'ok' => true,
        'property' => workflow_property_payload($property),
        'projects' => $projects,
    ]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
