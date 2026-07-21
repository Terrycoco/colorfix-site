<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

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

$playlistId = isset($payload['playlist_id']) ? (int)$payload['playlist_id'] : 0;
if ($playlistId <= 0) {
    respond(['ok' => false, 'error' => 'playlist_id required'], 400);
}

try {
    $stmt = $pdo->prepare('SELECT playlist_id, title, is_active, is_public FROM playlists WHERE playlist_id = :playlist_id LIMIT 1');
    $stmt->execute(['playlist_id' => $playlistId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        respond(['ok' => false, 'error' => 'Playlist not found'], 404);
    }

    $stmt = $pdo->prepare(
        'UPDATE playlists
            SET is_public = 1,
                updated_at = NOW()
          WHERE playlist_id = :playlist_id'
    );
    $stmt->execute(['playlist_id' => $playlistId]);

    respond([
        'ok' => true,
        'item' => [
            'playlist_id' => $playlistId,
            'title' => (string)($row['title'] ?? ''),
            'is_active' => (int)($row['is_active'] ?? 0),
            'is_public' => 1,
        ],
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
