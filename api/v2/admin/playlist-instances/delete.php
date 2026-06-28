<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

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

$instanceId = isset($payload['playlist_instance_id']) ? (int)$payload['playlist_instance_id'] : 0;
if ($instanceId <= 0) {
    respond(['ok' => false, 'error' => 'playlist_instance_id required'], 400);
}

try {
    $repo = new PdoPlaylistInstanceRepository($pdo);
    $result = $repo->deleteIfUnlocked($instanceId);
    if (!empty($result['blocked'])) {
        respond([
            'ok' => false,
            'error' => 'Instance is locked or still in use.',
            'blockers' => $result['blockers'] ?? [],
        ], 409);
    }
    respond(['ok' => true, 'item' => $result]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
