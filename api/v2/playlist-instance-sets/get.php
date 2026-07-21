<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');

$startedAt = microtime(true);
$timing = [];
$markTiming = static function (string $label) use (&$timing, $startedAt): void {
    $timing[$label] = round((microtime(true) - $startedAt) * 1000, 1);
};

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
$audience = trim((string)($_GET['audience'] ?? $_GET['aud'] ?? ''));
$audienceLower = strtolower($audience);
$isAdmin = (isset($_COOKIE['cf_admin']) && $_COOKIE['cf_admin'] === '1')
    || (isset($_COOKIE['cf_admin_global']) && $_COOKIE['cf_admin_global'] === '1');
$privateAudience = $audienceLower !== '' && $audienceLower !== 'any' && $audienceLower !== 'public';
$explicitPublicOnly = isset($_GET['include_private']) && (string)$_GET['include_private'] === '0';
$includePrivate = $privateAudience
    || ($isAdmin && !$explicitPublicOnly);

header(
    $includePrivate
        ? 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
        : 'Cache-Control: public, max-age=120, stale-while-revalidate=300'
);

$setRepo = new PdoPlaylistInstanceSetRepository($pdo);
$set = null;
if ($id > 0) {
    $set = $setRepo->getById($id);
} elseif ($handle !== '') {
    $set = $setRepo->getByHandle($handle);
}
$markTiming('load_set');

if (!$set) {
    respond(['ok' => false, 'error' => 'Set not found'], 404);
}

$itemRepo = new PdoPlaylistInstanceSetItemRepository($pdo);
$items = $itemRepo->listBySetId((int)$set->id);
$markTiming('load_items');

$photoIds = [];
$targetSetIds = [];
$playlistInstanceIds = [];
$playlistIds = [];
foreach ($items as $item) {
    if (($item->photoLibraryId ?? null) !== null) {
        $photoIds[(int)$item->photoLibraryId] = true;
    }
    if (($item->itemType ?? 'instance') === 'set' && ($item->targetSetId ?? null) !== null) {
        $targetSetIds[(int)$item->targetSetId] = true;
    }
    if (($item->playlistInstanceId ?? null) !== null) {
        $playlistInstanceIds[(int)$item->playlistInstanceId] = true;
    }
    if (($item->playlistId ?? null) !== null) {
        $playlistIds[(int)$item->playlistId] = true;
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
$markTiming('load_explicit_photos');

$fallbackPhotoByPlaylistId = [];
if ($playlistIds) {
    $ids = array_keys($playlistIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT pi.playlist_id,
                pi.photo_library_id,
                pl.rel_path,
                pl.updated_at
           FROM playlist_items pi
           JOIN photo_library pl
             ON pl.photo_library_id = pi.photo_library_id
          WHERE pi.playlist_id IN ({$placeholders})
            AND pi.is_active = 1
            AND pi.photo_library_id IS NOT NULL
            AND pi.photo_library_id > 0
          ORDER BY
            pi.playlist_id ASC,
            CASE WHEN pi.item_type = 'intro' THEN 0 ELSE 1 END,
            pi.order_index ASC,
            pi.playlist_item_id ASC"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $playlistId = (int)$row['playlist_id'];
        if (isset($fallbackPhotoByPlaylistId[$playlistId])) {
            continue;
        }
        $relPath = trim((string)($row['rel_path'] ?? ''));
        if ($relPath === '') {
            continue;
        }
        $updatedAt = trim((string)($row['updated_at'] ?? ''));
        $stamp = $updatedAt !== '' ? strtotime($updatedAt) : false;
        $fallbackPhotoByPlaylistId[$playlistId] = [
            'photo_library_id' => (int)$row['photo_library_id'],
            'photo_url' => ($stamp && $stamp > 0)
                ? ($relPath . (str_contains($relPath, '?') ? '&' : '?') . 'v=' . $stamp)
                : $relPath,
        ];
    }
}
$markTiming('load_fallback_photos');

$targetSetMetaById = [];
if ($targetSetIds) {
    $ids = array_keys($targetSetIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, title, subtitle, updated_at FROM playlist_instance_sets WHERE id IN ({$placeholders})");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $updatedAt = trim((string)($row['updated_at'] ?? ''));
        $stamp = $updatedAt !== '' ? strtotime($updatedAt) : false;
        $targetSetMetaById[(int)$row['id']] = [
            'title' => (string)($row['title'] ?? ''),
            'subtitle' => (string)($row['subtitle'] ?? ''),
            'version' => ($stamp && $stamp > 0) ? (string)$stamp : '',
        ];
    }
}
$markTiming('load_target_sets');

$slugByPlaylistInstanceId = [];
$displaySubtitleByPlaylistInstanceId = [];
$isPublicByPlaylistInstanceId = [];
if ($playlistInstanceIds) {
    $ids = array_keys($playlistInstanceIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT
            pi.playlist_instance_id,
            pi.slug,
            pi.display_subtitle,
            pi.is_active,
            pi.share_enabled,
            p.is_active AS playlist_is_active,
            p.is_public AS playlist_is_public
         FROM playlist_instances pi
         LEFT JOIN playlists p
           ON p.playlist_id = pi.playlist_id
         WHERE pi.playlist_instance_id IN ({$placeholders})"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $playlistInstanceId = (int)$row['playlist_instance_id'];
        $slugByPlaylistInstanceId[$playlistInstanceId] = $row['slug'] !== null ? (string)$row['slug'] : null;
        $displaySubtitleByPlaylistInstanceId[$playlistInstanceId] = $row['display_subtitle'] !== null ? (string)$row['display_subtitle'] : '';
        $isPublicByPlaylistInstanceId[$playlistInstanceId] =
            (int)($row['is_active'] ?? 0) === 1
            && (int)($row['share_enabled'] ?? 0) === 1
            && (int)($row['playlist_is_active'] ?? 0) === 1
            && ($includePrivate || (int)($row['playlist_is_public'] ?? 0) === 1);
    }
}
$markTiming('load_instances');

$instanceByPlaylistId = [];
if ($playlistIds) {
    $ids = array_keys($playlistIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = <<<SQL
        SELECT
            pi.playlist_instance_id,
            pi.playlist_id,
            pi.slug,
            pi.display_subtitle,
            pi.audience,
            pi.is_active,
            pi.share_enabled
        FROM playlist_instances pi
        JOIN playlists p
          ON p.playlist_id = pi.playlist_id
        WHERE pi.playlist_id IN ({$placeholders})
          AND pi.is_active = 1
          AND pi.share_enabled = 1
          AND p.is_active = 1
          AND (? = 1 OR p.is_public = 1)
        ORDER BY
          CASE
            WHEN ? <> '' AND pi.audience = ? THEN 0
            WHEN pi.audience = 'any' OR pi.audience IS NULL OR pi.audience = '' THEN 1
            ELSE 2
          END,
          pi.playlist_instance_id ASC
        SQL;
    $params = array_merge($ids, [$includePrivate ? 1 : 0, $audience, $audience]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $playlistId = (int)$row['playlist_id'];
        if (isset($instanceByPlaylistId[$playlistId])) {
            continue;
        }
        $instanceByPlaylistId[$playlistId] = [
            'playlist_instance_id' => (int)$row['playlist_instance_id'],
            'playlist_slug' => $row['slug'] !== null ? (string)$row['slug'] : null,
            'display_subtitle' => $row['display_subtitle'] !== null ? (string)$row['display_subtitle'] : '',
            'audience' => $row['audience'] !== null ? (string)$row['audience'] : null,
        ];
    }
}
$markTiming('load_instances_by_playlist');

$rows = array_values(array_filter(array_map(static function ($item) use ($photoUrlById, $fallbackPhotoByPlaylistId, $targetSetMetaById, $slugByPlaylistInstanceId, $displaySubtitleByPlaylistInstanceId, $isPublicByPlaylistInstanceId, $instanceByPlaylistId) {
    $targetSetMeta = ($item->itemType === 'set' && $item->targetSetId)
        ? ($targetSetMetaById[(int)$item->targetSetId] ?? null)
        : null;
    $playlistId = $item->playlistId !== null ? (int)$item->playlistId : null;
    $resolvedInstance = ($item->itemType === 'playlist' && $playlistId !== null)
        ? ($instanceByPlaylistId[$playlistId] ?? null)
        : null;
    if ($item->itemType === 'playlist' && $resolvedInstance === null) {
        return null;
    }
    $playlistInstanceId = $resolvedInstance
        ? (int)$resolvedInstance['playlist_instance_id']
        : ($item->playlistInstanceId !== null ? (int)$item->playlistInstanceId : null);
    if ($item->itemType !== 'playlist' && $playlistInstanceId !== null && empty($isPublicByPlaylistInstanceId[$playlistInstanceId])) {
        return null;
    }
    $slug = $playlistInstanceId !== null ? trim((string)($slugByPlaylistInstanceId[$playlistInstanceId] ?? '')) : '';
    if ($slug === '' && $resolvedInstance) {
        $slug = trim((string)($resolvedInstance['playlist_slug'] ?? ''));
    }
    $displaySubtitle = $playlistInstanceId !== null ? (string)($displaySubtitleByPlaylistInstanceId[$playlistInstanceId] ?? '') : '';
    if ($displaySubtitle === '' && $resolvedInstance) {
        $displaySubtitle = (string)($resolvedInstance['display_subtitle'] ?? '');
    }
    $fallbackPhoto = $playlistId !== null ? ($fallbackPhotoByPlaylistId[$playlistId] ?? null) : null;
    $photoLibraryId = $item->photoLibraryId ?: ($fallbackPhoto['photo_library_id'] ?? null);
    $photoUrl = $item->photoLibraryId
        ? ($photoUrlById[(int)$item->photoLibraryId] ?? $item->photoUrl)
        : (($fallbackPhoto['photo_url'] ?? '') !== '' ? (string)$fallbackPhoto['photo_url'] : $item->photoUrl);

    return [
        'id' => $item->id,
        'playlist_instance_id' => $playlistInstanceId,
        'playlist_slug' => $slug !== '' ? $slug : null,
        'player_url' => $playlistInstanceId !== null
            ? '/playlist/' . ($slug !== '' ? $slug : (string)$playlistInstanceId)
            : null,
        'playlist_id' => $playlistId,
        'item_type' => $item->itemType,
        'target_set_id' => $item->targetSetId,
        'target_set_version' => $item->targetSetId !== null
            ? (string)($targetSetMeta['version'] ?? '')
            : '',
        'title' => $item->title !== '' ? $item->title : (string)($targetSetMeta['title'] ?? ''),
        'subtitle' => $item->subtitle !== ''
            ? $item->subtitle
            : ($item->itemType === 'set' ? (string)($targetSetMeta['subtitle'] ?? '') : $displaySubtitle),
        'photo_url' => $photoUrl,
        'photo_library_id' => $photoLibraryId,
        'sort_order' => $item->sortOrder,
    ];
}, $items)));
$markTiming('map_rows');

$setUpdatedAt = trim((string)($set->updatedAt ?? ''));
$setStamp = $setUpdatedAt !== '' ? strtotime($setUpdatedAt) : false;
$setVersion = ($setStamp && $setStamp > 0) ? (string)$setStamp : '';

$payload = [
    'ok' => true,
    'set' => [
        'id' => $set->id,
        'handle' => $set->handle,
        'title' => $set->title,
        'subtitle' => $set->subtitle,
        'context' => $set->context,
        'updated_at' => $set->updatedAt,
        'version' => $setVersion,
        'end_cta' => [
            'label' => $set->endCtaLabel ?: 'Explore ColorFix',
            'url' => $set->endCtaUrl ?: '/',
            'enabled' => $set->endCtaEnabled,
        ],
        'items' => $rows,
    ],
];
if (isset($_GET['debug_timing']) && (string)$_GET['debug_timing'] !== '0') {
    $markTiming('total');
    $payload['timing_ms'] = $timing;
}

respond($payload);
