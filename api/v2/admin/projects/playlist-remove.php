<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';

use App\Repos\PdoProjectRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond(['ok' => false, 'error' => 'POST only'], 405);
    }

    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) {
        workflow_respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $projectId = isset($data['project_id']) ? (int)$data['project_id'] : 0;
    $projectPlaylistId = isset($data['project_playlist_id']) ? (int)$data['project_playlist_id'] : 0;

    $repo = new PdoProjectRepository($pdo);

    if ($projectId <= 0 || !$repo->findById($projectId)) {
        workflow_respond(['ok' => false, 'error' => 'Project required'], 400);
    }
    if ($projectPlaylistId <= 0) {
        workflow_respond(['ok' => false, 'error' => 'Project playlist link required'], 400);
    }

    $removed = $repo->removeProjectPlaylist($projectId, $projectPlaylistId);

    workflow_respond([
        'ok' => true,
        'removed' => $removed,
    ]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
