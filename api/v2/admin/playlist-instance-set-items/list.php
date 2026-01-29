<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPlaylistInstanceSetItemRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$setId = isset($_GET['set_id']) ? (int)$_GET['set_id'] : 0;
if ($setId <= 0) {
    respond(['ok' => false, 'error' => 'set_id required'], 400);
}

$repo = new PdoPlaylistInstanceSetItemRepository($pdo);
$items = $repo->listBySetId($setId);

$rows = array_map(static function ($item) {
    return [
        'id' => $item->id,
        'playlist_instance_set_id' => $item->setId,
        'playlist_instance_id' => $item->playlistInstanceId,
        'item_type' => $item->itemType,
        'target_set_id' => $item->targetSetId,
        'title' => $item->title,
        'photo_url' => $item->photoUrl,
        'sort_order' => $item->sortOrder,
    ];
}, $items);

respond(['ok' => true, 'items' => $rows]);
