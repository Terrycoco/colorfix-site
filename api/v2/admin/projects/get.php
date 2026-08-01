<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoLegacyProjectLinkRepository;
use App\Repos\PdoLegacyProjectRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

try {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        respond(['ok' => false, 'error' => 'id required'], 400);
    }

    $projectRepo = new PdoLegacyProjectRepository($pdo);
    $linkRepo = new PdoLegacyProjectLinkRepository($pdo);
    $project = $projectRepo->findById($id);
    if (!$project) {
        respond(['ok' => false, 'error' => 'Project not found'], 404);
    }
    respond(['ok' => true, 'project' => $project, 'links' => $linkRepo->listByProjectId($id)]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
