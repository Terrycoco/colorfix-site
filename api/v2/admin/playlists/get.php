<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPlaylistRepository;

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

function playlistSeoColumnsPresent(PDO $pdo): bool {
    foreach (['slug', 'headline', 'page_title', 'meta_description', 'dek', 'intro_html', 'body_html', 'hero_image_id', 'hero_image_url', 'hero_alt', 'indexable', 'published_at'] as $column) {
        if (!columnExists($pdo, 'playlists', $column)) {
            return false;
        }
    }
    return true;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$playlistId = isset($_GET['playlist_id']) ? (int)$_GET['playlist_id'] : 0;
if ($playlistId <= 0) {
    respond(['ok' => false, 'error' => 'playlist_id required'], 400);
}

try {
    if (!playlistSeoColumnsPresent($pdo)) {
        respond(['ok' => false, 'error' => 'Playlist SEO migration has not been applied yet'], 500);
    }

    $repo = new PdoPlaylistRepository($pdo);
    $playlist = $repo->getAdminRowById($playlistId);
} catch (\Throwable $e) {
    respond(['ok' => false, 'error' => 'Failed to load playlist. Playlist SEO migration may be missing.'], 500);
}

if (!$playlist) {
    respond(['ok' => false, 'error' => 'Playlist not found'], 404);
}

$hasExcludeFromThumbs = columnExists($pdo, 'playlist_items', 'exclude_from_thumbs');
$hasPhotoLibraryId = columnExists($pdo, 'playlist_items', 'photo_library_id');
$hasSavedPaletteSetId = columnExists($pdo, 'playlist_items', 'saved_palette_set_id');
$hasIsShareImage = columnExists($pdo, 'playlist_items', 'is_share_image');
$hasSite = columnExists($pdo, 'playlist_items', 'site');
$hasYt = columnExists($pdo, 'playlist_items', 'yt');
$hasPin = columnExists($pdo, 'playlist_items', 'pin');
$hasAnalyzerRole = columnExists($pdo, 'playlist_items', 'analyzer_role');
$hasFinderStart = columnExists($pdo, 'playlist_items', 'finder_start');
$excludeSelect = $hasExcludeFromThumbs ? 'exclude_from_thumbs' : '0 AS exclude_from_thumbs';
$photoSelect = $hasPhotoLibraryId ? 'photo_library_id' : 'NULL AS photo_library_id';
$savedPaletteSetSelect = $hasSavedPaletteSetId ? 'saved_palette_set_id' : 'NULL AS saved_palette_set_id';
$shareImageSelect = $hasIsShareImage ? 'is_share_image' : '0 AS is_share_image';
$siteSelect = $hasSite ? 'site' : '1 AS site';
$ytSelect = $hasYt ? 'yt' : '1 AS yt';
$pinSelect = $hasPin ? 'pin' : '1 AS pin';
$analyzerRoleSelect = $hasAnalyzerRole ? 'analyzer_role' : "'ignore' AS analyzer_role";
$finderStartSelect = $hasFinderStart ? 'finder_start' : "'auto' AS finder_start";
$itemSql = <<<SQL
    SELECT
      playlist_item_id,
      playlist_id,
      order_index,
      ap_id,
      palette_hash,
      image_url,
      {$photoSelect},
      {$savedPaletteSetSelect},
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
      {$shareImageSelect},
      {$siteSelect},
      {$ytSelect},
      {$pinSelect},
      {$analyzerRoleSelect},
      {$finderStartSelect},
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
