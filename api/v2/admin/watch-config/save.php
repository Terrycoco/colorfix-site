<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../functions/watch-config.php';

use App\Repos\PdoPlaylistInstanceRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$playlistInstanceId = isset($payload['playlist_instance_id']) ? (int)$payload['playlist_instance_id'] : 0;
$query = $payload['query'] ?? [];
if (!is_array($query)) {
    $query = [];
}

if ($playlistInstanceId <= 0) {
    respond(['ok' => false, 'error' => 'playlist_instance_id required'], 400);
}

$repo = new PdoPlaylistInstanceRepository($pdo);
$instance = $repo->getById($playlistInstanceId);
if (!$instance || !$instance->isActive) {
    respond(['ok' => false, 'error' => 'Playlist instance not found'], 404);
}

$normalizedQuery = [];
foreach ($query as $key => $value) {
    $name = trim((string)$key);
    if ($name === '' || $name === 'id') {
      continue;
    }
    if ($value === null) {
      continue;
    }
    $text = trim((string)$value);
    if ($text === '') {
      continue;
    }
    $normalizedQuery[$name] = $text;
}

if (!saveWatchConfig($playlistInstanceId, $normalizedQuery, $pdo ?? null)) {
    respond(['ok' => false, 'error' => 'Failed to write watch config'], 500);
}

respond([
    'ok' => true,
    'item' => [
        'playlist_instance_id' => $playlistInstanceId,
        'query' => $normalizedQuery,
    ],
]);
