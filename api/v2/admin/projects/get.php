<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';

use App\Repos\PdoProjectRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        workflow_respond(['ok' => false, 'error' => 'id required'], 400);
    }

    $repo = new PdoProjectRepository($pdo);
    $row = $repo->findById($id);
    if (!$row) {
        workflow_respond(['ok' => false, 'error' => 'Project not found'], 404);
    }

    workflow_respond([
        'ok' => true,
        'project' => workflow_project_payload($row),
        'photos' => array_map(static fn(array $photo): array => [
            'project_photo_id' => (int)$photo['project_photo_id'],
            'photo_library_id' => (int)$photo['photo_library_id'],
            'role' => (string)($photo['role'] ?? ''),
            'sort_order' => (int)($photo['sort_order'] ?? 0),
            'rel_path' => (string)($photo['rel_path'] ?? ''),
            'title' => (string)($photo['title'] ?? ''),
            'alt_text' => (string)($photo['alt_text'] ?? ''),
        ], $repo->listProjectPhotos($id)),
        'playlists' => array_map(static fn(array $playlist): array => [
            'project_playlist_id' => (int)$playlist['project_playlist_id'],
            'playlist_id' => (int)$playlist['playlist_id'],
            'title' => (string)($playlist['title'] ?? ''),
            'slug' => (string)($playlist['slug'] ?? ''),
            'created_at' => $playlist['created_at'] ?? null,
            'updated_at' => $playlist['updated_at'] ?? null,
        ], $repo->listProjectPlaylists($id)),
    ]);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
