<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$freshRequest = isset($_GET['fresh']) && (string)$_GET['fresh'] !== '0';
if ($freshRequest) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
} else {
    header('Cache-Control: public, max-age=60, stale-while-revalidate=180');
}

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Services\PlayerExperienceService;
use App\Repos\PdoPlaylistInstanceRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$playlistInstanceId = (int)($_GET['playlist_instance_id'] ?? 0);
$playlistSlug = trim((string)($_GET['playlist_slug'] ?? $_GET['slug'] ?? ''));
$start = isset($_GET['start']) ? (int)$_GET['start'] : null;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$position = null;
if (isset($_GET['position'])) {
    $position = (int)$_GET['position'];
} elseif (isset($_GET['pos'])) {
    $position = (int)$_GET['pos'];
}
$playlistItemId = 0;
foreach (['playlist_item_id', 'slide_id', 'item_id'] as $key) {
    if (isset($_GET[$key])) {
        $playlistItemId = (int)$_GET[$key];
        break;
    }
}
$photoLibraryId = 0;
foreach (['photo_library_id', 'photo_id'] as $key) {
    if (isset($_GET[$key])) {
        $photoLibraryId = (int)$_GET[$key];
        break;
    }
}
$mode = trim((string)($_GET['mode'] ?? ''));
$addGroupId = isset($_GET['add_cta_group']) ? (int)$_GET['add_cta_group'] : null;
$debugTiming = isset($_GET['debug_timing']) && (string)$_GET['debug_timing'] !== '0';

if ($playlistInstanceId <= 0 && $playlistSlug === '') {
    respond([
        'ok' => false,
        'error' => 'playlist_instance_id or playlist_slug required',
        'code' => 'playlist_unavailable',
    ], 400);
}

try {
    if ($playlistInstanceId <= 0 && $playlistSlug !== '') {
        $repo = new PdoPlaylistInstanceRepository($pdo);
        $playlistInstanceId = $repo->findIdBySlug($playlistSlug) ?? 0;
        if ($playlistInstanceId <= 0) {
            throw new RuntimeException('Playlist unavailable');
        }
    }

    $service = new PlayerExperienceService($pdo);
    $plan = $service->buildPlaybackPlanFromInstance($playlistInstanceId, $start, $mode, $addGroupId, [
        'offset' => $offset,
        'position' => $position,
        'playlist_item_id' => $playlistItemId,
        'photo_library_id' => $photoLibraryId,
    ]);

    $payload = [
        'ok'   => true,
        'data' => $plan,
    ];
    if ($debugTiming) {
        $payload['timing_ms'] = $service->getLastTiming();
    }

    respond($payload);
} catch (RuntimeException $e) {
    respond([
        'ok' => false,
        'error' => 'Playlist unavailable',
        'code' => 'playlist_unavailable',
    ], 404);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
