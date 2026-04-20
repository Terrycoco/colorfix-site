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

try {
    $slugSelect = columnExists($pdo, 'playlists', 'slug') ? 'slug' : 'NULL AS slug';
    $headlineSelect = columnExists($pdo, 'playlists', 'headline') ? 'headline' : 'NULL AS headline';
    $indexableSelect = columnExists($pdo, 'playlists', 'indexable') ? 'indexable' : '1 AS indexable';
    $publishedAtSelect = columnExists($pdo, 'playlists', 'published_at') ? 'published_at' : 'NULL AS published_at';
    $updatedAtSelect = columnExists($pdo, 'playlists', 'updated_at') ? 'updated_at' : 'NULL AS updated_at';

    $sql = <<<SQL
        SELECT
            playlist_id,
            title,
            type,
            is_active,
            {$slugSelect},
            {$headlineSelect},
            {$indexableSelect},
            {$publishedAtSelect},
            {$updatedAtSelect}
        FROM playlists
        ORDER BY playlist_id ASC
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
