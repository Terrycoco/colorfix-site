<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
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

$playlistId = trim((string)($_GET['playlist_id'] ?? $_GET['playlist'] ?? $_GET['id'] ?? ''));
$start = isset($_GET['start']) ? (int)$_GET['start'] : null;

if ($playlistId === '') {
    respond(['ok' => false, 'error' => 'playlist_id required'], 400);
}

try {
    $service = new PlayerExperienceService();
    $plan = $service->buildPlaybackPlan($playlistId, $start);
    respond(['ok' => true, 'data' => $plan]);
} catch (RuntimeException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 404);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
