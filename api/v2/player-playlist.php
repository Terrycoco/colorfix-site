<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Services\PlayerExperienceService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$playlistInstanceId = (int)($_GET['playlist_instance_id'] ?? 0);
$start = isset($_GET['start']) ? (int)$_GET['start'] : null;
$mode = trim((string)($_GET['mode'] ?? ''));
$addGroupId = isset($_GET['add_cta_group']) ? (int)$_GET['add_cta_group'] : null;

if ($playlistInstanceId <= 0) {
    respond(['ok' => false, 'error' => 'playlist_instance_id required'], 400);
}

try {
    $service = new PlayerExperienceService($pdo);
    $plan = $service->buildPlaybackPlanFromInstance($playlistInstanceId, $start, $mode, $addGroupId);

    respond([
        'ok'   => true,
        'data' => $plan,
    ]);
} catch (RuntimeException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 404);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
