<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60, stale-while-revalidate=180');

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

try {
    $slugSelect = columnExists($pdo, 'playlists', 'slug') ? 'p.slug' : 'NULL AS slug';
    $headlineSelect = columnExists($pdo, 'playlists', 'headline') ? 'p.headline' : 'NULL AS headline';
    $publicSelect = columnExists($pdo, 'playlists', 'is_public') ? 'p.is_public' : '0 AS is_public';
    $retiredWhere = columnExists($pdo, 'playlists', 'is_retired') ? 'WHERE COALESCE(p.is_retired, 0) = 0' : '';
    $indexableSelect = columnExists($pdo, 'playlists', 'indexable') ? 'p.indexable' : '1 AS indexable';
    $publishedAtSelect = columnExists($pdo, 'playlists', 'published_at') ? 'p.published_at' : 'NULL AS published_at';
    $updatedAtSelect = columnExists($pdo, 'playlists', 'updated_at') ? 'p.updated_at' : 'NULL AS updated_at';

    $sql = <<<SQL
        SELECT
            p.playlist_id,
            p.title,
            p.type,
            p.is_active,
            {$publicSelect},
            {$slugSelect},
            {$headlineSelect},
            {$indexableSelect},
            {$publishedAtSelect},
            {$updatedAtSelect},
            shareable.playlist_instance_id AS watch_playlist_instance_id
        FROM playlists p
        LEFT JOIN (
            SELECT playlist_id, MIN(playlist_instance_id) AS playlist_instance_id
            FROM playlist_instances
            WHERE is_active = 1
              AND share_enabled = 1
            GROUP BY playlist_id
        ) shareable
          ON shareable.playlist_id = p.playlist_id
        {$retiredWhere}
        ORDER BY p.playlist_id ASC
        SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    respond([
        'ok' => true,
        'items' => $rows,
    ]);
} catch (\Throwable $e) {
    respond([
        'ok' => false,
        'error' => 'Failed to load playlists. The playlist SEO migration may not be applied yet.',
    ], 500);
}
