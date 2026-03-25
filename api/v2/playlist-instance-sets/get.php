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

$photoIds = [];
$targetSetIds = [];
foreach ($items as $item) {
    if (($item->photoLibraryId ?? null) !== null) {
        $photoIds[(int)$item->photoLibraryId] = true;
    }
    if (($item->itemType ?? 'instance') === 'set' && ($item->targetSetId ?? null) !== null) {
        $targetSetIds[(int)$item->targetSetId] = true;
    }
}

$photoUrlById = [];
if ($photoIds) {
    $ids = array_keys($photoIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT photo_library_id, rel_path, updated_at FROM photo_library WHERE photo_library_id IN ({$placeholders})");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $relPath = trim((string)($row['rel_path'] ?? ''));
        $updatedAt = trim((string)($row['updated_at'] ?? ''));
        if ($relPath === '') {
            continue;
        }
        $stamp = $updatedAt !== '' ? strtotime($updatedAt) : false;
        $photoUrlById[(int)$row['photo_library_id']] =
            ($stamp && $stamp > 0)
                ? ($relPath . (str_contains($relPath, '?') ? '&' : '?') . 'v=' . $stamp)
                : $relPath;
    }
}

$targetSetMetaById = [];
if ($targetSetIds) {
    $ids = array_keys($targetSetIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, title, subtitle FROM playlist_instance_sets WHERE id IN ({$placeholders})");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $targetSetMetaById[(int)$row['id']] = [
            'title' => (string)($row['title'] ?? ''),
            'subtitle' => (string)($row['subtitle'] ?? ''),
        ];
    }
}

$rows = array_map(static function ($item) use ($photoUrlById, $targetSetMetaById) {
    $targetSetMeta = ($item->itemType === 'set' && $item->targetSetId)
        ? ($targetSetMetaById[(int)$item->targetSetId] ?? null)
        : null;
    return [
        'id' => $item->id,
        'playlist_instance_id' => $item->playlistInstanceId,
        'playlist_id' => $item->playlistId,
        'item_type' => $item->itemType,
        'target_set_id' => $item->targetSetId,
        'title' => $item->title !== '' ? $item->title : (string)($targetSetMeta['title'] ?? ''),
        'subtitle' => $item->itemType === 'set'
            ? (string)($targetSetMeta['subtitle'] ?? '')
            : $item->subtitle,
        'photo_url' => $item->photoLibraryId ? ($photoUrlById[(int)$item->photoLibraryId] ?? $item->photoUrl) : $item->photoUrl,
        'photo_library_id' => $item->photoLibraryId,
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
