<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Services\PlaylistPhotoLibrarySyncService;
use App\Repos\PdoPlaylistRepository;
use App\REX\Services\RexPlaylistExperienceSyncService;

function respond(array $payload, int $status = 200): void
{
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

$playlistId = isset($payload['playlist_id'])
    ? (int)$payload['playlist_id']
    : 0;

$items = $payload['items'] ?? [];

if ($playlistId <= 0) {
    respond(['ok' => false, 'error' => 'playlist_id required'], 400);
}

if (!is_array($items)) {
    respond(['ok' => false, 'error' => 'items must be array'], 400);
}

try {
    $pdo->beginTransaction();

    $playlistPhotoSync = new PlaylistPhotoLibrarySyncService($pdo);
    $repo = new PdoPlaylistRepository($pdo);

    $repo->saveAdminItems(
        $playlistId,
        $items,
        [$playlistPhotoSync, 'normalizeItemForSave']
    );

    /*
     * Playlist items are now the current source of truth.
     *
     * Keep every permanent REX identity, then make the mutable
     * REX relationship graph match what this saved Playlist
     * currently wants for Public / Concept / Client.
     *
     * RexPlaylistExperienceSyncService detects the existing
     * transaction and participates in it rather than committing
     * independently.
     */
    $rexSync = new RexPlaylistExperienceSyncService($pdo);
    $rexResult = $rexSync->syncPlaylist($playlistId);

    $pdo->commit();

    respond([
        'ok' => true,
        'rex_sync' => $rexResult,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
