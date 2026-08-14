<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/_helpers.php';

use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexPlaylistViewerLinker;
use App\REX\Services\RexReservationRelationships;
use App\REX\Services\RexReserver;
use App\REX\Services\RexTokenGenerator;
use App\Repos\PdoPaletteViewerRepository;
use App\Repos\PdoPlaylistRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond(['ok' => false, 'error' => 'POST only'], 405);
    }

    $input = rex_admin_json_input();
    $playlistId = isset($input['playlist_id']) && (int)$input['playlist_id'] > 0
        ? (int)$input['playlist_id']
        : null;

    $rexRepo = new PdoRexReservationRepository($pdo);
    $service = new RexPlaylistViewerLinker(
        new PdoPlaylistRepository($pdo),
        new PdoPaletteViewerRepository($pdo),
        $rexRepo,
        new RexReservationRelationships($rexRepo),
        new RexReserver($rexRepo, new RexTokenGenerator()),
    );

    workflow_respond([
        'ok' => true,
        'result' => $service->linkAll($playlistId),
    ]);
} catch (InvalidArgumentException $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
