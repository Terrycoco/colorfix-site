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
$jobId = isset($payload['asset_creator_job_id']) ? (int)$payload['asset_creator_job_id'] : 0;
$platform = trim((string)($payload['platform'] ?? ''));
$destinationKey = trim((string)($payload['destination_key'] ?? ''));

if ($instanceId <= 0) {
    respond(['ok' => false, 'error' => 'playlist_instance_id required'], 400);
}
if ($jobId <= 0) {
    respond(['ok' => false, 'error' => 'asset_creator_job_id required'], 400);
}

try {
    $repo = new PdoPlaylistInstanceRepository($pdo);
    $instance = $repo->getById($instanceId);
    if (!$instance) {
        respond(['ok' => false, 'error' => 'Playlist instance not found'], 404);
    }

    $notes = (string)($instance->instanceNotes ?? '');
    $required = [
        'publisher_environment=test',
        "asset_creator_job_id={$jobId}",
    ];
    if ($platform !== '') {
        $required[] = "publisher_platform={$platform}";
    }
    if ($destinationKey !== '') {
        $required[] = "publisher_destination={$destinationKey}";
    }

    foreach ($required as $needle) {
        if (!str_contains($notes, $needle)) {
            respond([
                'ok' => false,
                'error' => 'Refusing to deactivate: instance is not the matching publisher test instance.',
            ], 400);
        }
    }

    $instance->isActive = false;
    $instance->instanceNotes = trim($notes . "\nDeactivated by publisher setup on " . gmdate('Y-m-d H:i:s') . ' UTC');
    $repo->save($instance);

    respond([
        'ok' => true,
        'playlist_instance_id' => $instanceId,
        'status' => 'inactive',
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
