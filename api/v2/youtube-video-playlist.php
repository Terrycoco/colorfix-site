<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Services\YoutubeVideoExperienceService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$playlistId = (int)($_GET['playlist_id'] ?? $_GET['id'] ?? 0);
if ($playlistId <= 0) {
    respond(['ok' => false, 'error' => 'playlist_id required'], 400);
}

try {
    $service = new YoutubeVideoExperienceService($pdo);
    respond([
        'ok' => true,
        'data' => $service->buildVideoPlanFromPlaylist($playlistId),
    ]);
} catch (RuntimeException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 404);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
