<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';

use App\Repos\PdoProjectRepository;
use App\Repos\PdoProjectTypeRepository;
use App\Repos\PdoPropertyRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond(['ok' => false, 'error' => 'POST only'], 405);
    }

    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) {
        workflow_respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $name = workflow_optional_string($data['name'] ?? null);
    $propertyId = isset($data['property_id']) ? (int)$data['property_id'] : 0;
    $projectTypeId = isset($data['project_type_id']) ? (int)$data['project_type_id'] : 0;
    $experienceKey = workflow_validate_experience_key($data['experience_key'] ?? 'concept');

    if ($name === null) {
        workflow_respond(['ok' => false, 'error' => 'Project name required'], 400);
    }
    if ($propertyId <= 0 || !(new PdoPropertyRepository($pdo))->findById($propertyId)) {
        workflow_respond(['ok' => false, 'error' => 'Property required'], 400);
    }
    if ($projectTypeId <= 0 || !(new PdoProjectTypeRepository($pdo))->findById($projectTypeId)) {
        workflow_respond(['ok' => false, 'error' => 'Project type required'], 400);
    }

    $repo = new PdoProjectRepository($pdo);
    $payload = [
        'property_id' => $propertyId,
        'project_type_id' => $projectTypeId,
        'name' => $name,
        'status' => workflow_optional_string($data['status'] ?? null) ?? 'prospect',
        'experience_key' => $experienceKey,
        'notes' => workflow_optional_string($data['notes'] ?? null),
    ];

    if ($id > 0) {
        $repo->update($id, $payload);
        workflow_respond(['ok' => true, 'id' => $id]);
    }

    workflow_respond(['ok' => true, 'id' => $repo->create($payload)]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
