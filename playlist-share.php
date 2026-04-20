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
    http_response_code(404);
    exit('Playlist share target not found');
}

$_GET['id'] = (string)$match['watch_playlist_instance_id'];
require __DIR__ . '/share/playlist.php';
