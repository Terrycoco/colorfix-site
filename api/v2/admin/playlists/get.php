<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    $sql = <<<SQL
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
          AND COLUMN_NAME = :column
        SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'table' => $table,
        'column' => $column,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$playlistId = isset($_GET['playlist_id']) ? (int)$_GET['playlist_id'] : 0;
if ($playlistId <= 0) {
    respond(['ok' => false, 'error' => 'playlist_id required'], 400);
}

$sql = <<<SQL
    SELECT playlist_id, title, type, is_active
    FROM playlists
    WHERE playlist_id = :playlist_id
    LIMIT 1
    SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute(['playlist_id' => $playlistId]);
$playlist = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$playlist) {
    respond(['ok' => false, 'error' => 'Playlist not found'], 404);
}

$hasExcludeFromThumbs = columnExists($pdo, 'playlist_items', 'exclude_from_thumbs');
$excludeSelect = $hasExcludeFromThumbs ? 'exclude_from_thumbs' : '0 AS exclude_from_thumbs';
$itemSql = <<<SQL
    SELECT
      playlist_item_id,
      playlist_id,
      order_index,
      ap_id,
      image_url,
      title,
      subtitle,
      subtitle_2,
      body,
      item_type,
      layout,
      title_mode,
      star,
      transition,
      duration_ms,
      {$excludeSelect},
      is_active
    FROM playlist_items
    WHERE playlist_id = :playlist_id
      AND is_active = 1
    ORDER BY order_index ASC
    SQL;

$stmt = $pdo->prepare($itemSql);
$stmt->execute(['playlist_id' => $playlistId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

respond([
    'ok' => true,
    'playlist' => $playlist,
    'items' => $items,
]);
