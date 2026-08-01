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

$items = $repo->getAdminItemRows($playlistId);

respond([
    'ok' => true,
    'playlist' => $playlist,
    'items' => $items,
]);
