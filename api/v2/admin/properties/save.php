<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';

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
    if ($name === null) {
        workflow_respond(['ok' => false, 'error' => 'Property name required'], 400);
    }

    // Property owns location facts only. Client belongs to Project.
    $payload = [
        'name' => $name,
        'notes' => workflow_optional_string($data['notes'] ?? null),
    ];

    $repo = new PdoPropertyRepository($pdo);
    if ($id > 0) {
        if (!$repo->findById($id)) {
            workflow_respond(['ok' => false, 'error' => 'Property not found'], 404);
        }
        $repo->update($id, $payload);
        workflow_respond(['ok' => true, 'id' => $id]);
    }

    workflow_respond(['ok' => true, 'id' => $repo->create($payload)]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
