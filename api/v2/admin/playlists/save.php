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
$title = trim((string)($payload['title'] ?? ''));
$type = trim((string)($payload['type'] ?? ''));
$isActive = isset($payload['is_active']) ? (int)(bool)$payload['is_active'] : 1;

if ($title === '') {
    respond(['ok' => false, 'error' => 'title required'], 400);
}
if ($type === '') {
    respond(['ok' => false, 'error' => 'type required'], 400);
}

if ($playlistId > 0) {
    $sql = <<<SQL
        UPDATE playlists
        SET title = :title,
            type = :type,
            is_active = :is_active
        WHERE playlist_id = :playlist_id
        SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'playlist_id' => $playlistId,
        'title' => $title,
        'type' => $type,
        'is_active' => $isActive,
    ]);
} else {
    $sql = <<<SQL
        INSERT INTO playlists (title, type, is_active)
        VALUES (:title, :type, :is_active)
        SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'title' => $title,
        'type' => $type,
        'is_active' => $isActive,
    ]);
    $playlistId = (int)$pdo->lastInsertId();
}

respond([
    'ok' => true,
    'playlist_id' => $playlistId,
]);
