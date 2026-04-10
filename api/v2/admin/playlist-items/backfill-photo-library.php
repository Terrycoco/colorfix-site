<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Services\PlaylistPhotoLibrarySyncService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    respond(['ok' => false, 'error' => 'GET or POST only'], 405);
}

$payload = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw ?: '', true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
} else {
    $payload = $_GET;
}

$playlistId = isset($payload['playlist_id']) ? (int)$payload['playlist_id'] : 0;
$applyRaw = $payload['apply'] ?? false;
$apply = in_array($applyRaw, [true, 1, '1', 'true', 'yes', 'on'], true);

try {
    if ($apply) {
        $pdo->beginTransaction();
    }

    $service = new PlaylistPhotoLibrarySyncService($pdo);
    $result = $service->backfill($playlistId > 0 ? $playlistId : null, $apply);

    if ($apply && $pdo->inTransaction()) {
        $pdo->commit();
    }

    respond([
        'ok' => true,
        'apply' => $apply,
        'playlist_id' => $playlistId > 0 ? $playlistId : null,
        'result' => $result,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
