<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Repos\PdoPlaylistInstanceSetRepository;
use App\Repos\PdoPlaylistInstanceSetItemRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$handle = trim((string)($_GET['handle'] ?? ''));
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$setRepo = new PdoPlaylistInstanceSetRepository($pdo);
$set = null;
if ($id > 0) {
    $set = $setRepo->getById($id);
} elseif ($handle !== '') {
    $set = $setRepo->getByHandle($handle);
}

if (!$set) {
    respond(['ok' => false, 'error' => 'Set not found'], 404);
}

$itemRepo = new PdoPlaylistInstanceSetItemRepository($pdo);
$items = $itemRepo->listBySetId((int)$set->id);

$rows = array_map(static function ($item) {
    return [
        'id' => $item->id,
        'playlist_instance_id' => $item->playlistInstanceId,
        'item_type' => $item->itemType,
        'target_set_id' => $item->targetSetId,
        'title' => $item->title,
        'photo_url' => $item->photoUrl,
        'sort_order' => $item->sortOrder,
    ];
}, $items);

respond([
    'ok' => true,
    'set' => [
        'id' => $set->id,
        'handle' => $set->handle,
        'title' => $set->title,
        'subtitle' => $set->subtitle,
        'context' => $set->context,
        'items' => $rows,
    ],
]);
