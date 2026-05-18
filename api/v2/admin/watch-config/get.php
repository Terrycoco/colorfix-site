<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../functions/watch-config.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$config = loadWatchConfig($pdo ?? null);
$playlistInstanceId = (int)($config['playlist_instance_id'] ?? 0);
$slug = null;
$url = $playlistInstanceId > 0 ? "/playlist/{$playlistInstanceId}" : '';

if ($playlistInstanceId > 0) {
    $stmt = $pdo->prepare(
        "SELECT slug
         FROM playlist_instances pi
         WHERE pi.playlist_instance_id = :id
         LIMIT 1"
    );
    $stmt->execute(['id' => $playlistInstanceId]);
    $foundSlug = $stmt->fetchColumn();
    if (is_string($foundSlug) && trim($foundSlug) !== '') {
        $slug = trim($foundSlug);
        $url = "/playlist/{$slug}";
    }
}

respond([
    'ok' => true,
    'item' => [
        'playlist_instance_id' => $playlistInstanceId,
        'playlist_slug' => $slug,
        'player_url' => $url,
        'query' => is_array($config['query'] ?? null) ? $config['query'] : [],
    ],
]);
