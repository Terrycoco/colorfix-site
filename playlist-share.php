<?php
declare(strict_types=1);

use App\Repos\PdoPlaylistRepository;

require_once __DIR__ . '/api/autoload.php';
require_once __DIR__ . '/api/db.php';

$playlistId = (int)($_GET['playlist_id'] ?? 0);
if ($playlistId <= 0) {
    http_response_code(404);
    exit('Missing playlist id');
}

$repo = new PdoPlaylistRepository($pdo);
$match = $repo->findSeoLandingByPlaylistId($playlistId);

if (!$match || empty($match['watch_playlist_instance_id'])) {
    $stmt = $pdo->prepare(
        'SELECT pi.playlist_instance_id
           FROM playlists p
           JOIN playlist_instances pi
             ON pi.playlist_id = p.playlist_id
          WHERE p.playlist_id = :playlist_id
            AND p.is_active = 1
            AND pi.is_active = 1
            AND pi.share_enabled = 1
          ORDER BY pi.playlist_instance_id ASC
          LIMIT 1'
    );
    $stmt->execute(['playlist_id' => $playlistId]);
    $instanceId = (int)($stmt->fetchColumn() ?: 0);
    if ($instanceId <= 0) {
        http_response_code(404);
        exit('Playlist share target not found');
    }
    $match = ['watch_playlist_instance_id' => $instanceId];
}

$_GET['id'] = (string)$match['watch_playlist_instance_id'];
require __DIR__ . '/share/playlist.php';
