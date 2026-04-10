<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function parent_dir_key(string $relPath): string
{
    $path = trim($relPath);
    if ($path === '') {
        return '';
    }
    $path = preg_replace('/\?.*$/', '', $path) ?? $path;
    return rtrim((string)dirname($path), '/');
}

function usage_count_expr(string $alias = 'pl'): string
{
    return <<<SQL
        (
          SELECT COUNT(*) FROM playlist_items pi
          WHERE pi.photo_library_id = {$alias}.photo_library_id
        ) +
        (
          SELECT COUNT(*) FROM playlist_instance_set_items psi
          WHERE psi.photo_library_id = {$alias}.photo_library_id
        ) +
        (
          SELECT COUNT(*) FROM playlist_instances pinst
          WHERE pinst.intro_image_url = CONCAT('photo:', {$alias}.photo_library_id)
             OR pinst.intro_image_url LIKE CONCAT('photo:', {$alias}.photo_library_id, '|%')
        ) +
        (
          SELECT COUNT(*) FROM playlist_instances pinst
          WHERE pinst.share_image_url = CONCAT('photo:', {$alias}.photo_library_id)
             OR pinst.share_image_url LIKE CONCAT('photo:', {$alias}.photo_library_id, '|%')
        ) +
        (
          SELECT COUNT(*) FROM saved_palette_set_photos spsp
          WHERE spsp.photo_library_id = {$alias}.photo_library_id
        ) +
        (
          SELECT COUNT(*) FROM article_sections ars
          WHERE ars.asset_id = {$alias}.photo_library_id
        ) +
        (
          SELECT COUNT(*) FROM articles a
          WHERE a.hero_asset_id = {$alias}.photo_library_id
             OR a.hero_mobile_asset_id = {$alias}.photo_library_id
        ) +
        (
          SELECT COUNT(*) FROM photo_group_items pgi
          WHERE pgi.photo_library_id = {$alias}.photo_library_id
        )
    SQL;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

try {
    $usageExpr = usage_count_expr('pl');
    $sql = <<<SQL
        SELECT
            pl.photo_library_id,
            pl.source_type,
            pl.source_id,
            pl.title,
            pl.rel_path,
            pl.updated_at,
            {$usageExpr} AS usage_count
        FROM photo_library pl
        WHERE pl.rel_path IS NOT NULL
          AND pl.rel_path <> ''
          AND COALESCE(pl.is_inactive, 0) = 0
        ORDER BY pl.rel_path ASC, pl.photo_library_id ASC
    SQL;

    $stmt = $pdo->query($sql);
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $exactGroups = [];
    $beforeFolderGroups = [];

    foreach ($rows as $row) {
        $relPath = trim((string)($row['rel_path'] ?? ''));
        if ($relPath === '') {
            continue;
        }

        $item = [
            'photo_library_id' => (int)$row['photo_library_id'],
            'source_type' => (string)($row['source_type'] ?? ''),
            'source_id' => $row['source_id'] !== null ? (int)$row['source_id'] : null,
            'title' => (string)($row['title'] ?? ''),
            'rel_path' => $relPath,
            'updated_at' => $row['updated_at'] ?? null,
            'usage_count' => (int)($row['usage_count'] ?? 0),
        ];

        $exactGroups[$relPath][] = $item;

        if (($item['source_type'] ?? '') === 'saved_before') {
            $dirKey = parent_dir_key($relPath);
            if ($dirKey !== '') {
                $beforeFolderGroups[$dirKey][] = $item;
            }
        }
    }

    $items = [];

    foreach ($exactGroups as $relPath => $groupRows) {
        if (count($groupRows) < 2) {
            continue;
        }
        usort($groupRows, static fn(array $a, array $b): int => ($b['usage_count'] ?? 0) <=> ($a['usage_count'] ?? 0) ?: (($a['photo_library_id'] ?? 0) <=> ($b['photo_library_id'] ?? 0)));
        $items[] = [
            'match_type' => 'exact',
            'reason' => 'Exact duplicate file path',
            'group_key' => $relPath,
            'duplicate_count' => count($groupRows),
            'items' => $groupRows,
        ];
    }

    foreach ($beforeFolderGroups as $dirKey => $groupRows) {
        if (count($groupRows) < 2) {
            continue;
        }

        $distinctPaths = array_values(array_unique(array_map(static fn(array $row): string => (string)$row['rel_path'], $groupRows)));
        if (count($distinctPaths) < 2) {
            continue;
        }

        usort($groupRows, static fn(array $a, array $b): int => ($b['usage_count'] ?? 0) <=> ($a['usage_count'] ?? 0) ?: (($a['photo_library_id'] ?? 0) <=> ($b['photo_library_id'] ?? 0)));
        $items[] = [
            'match_type' => 'maybe_same',
            'reason' => 'May be the same before photo in the same source folder',
            'group_key' => $dirKey,
            'duplicate_count' => count($groupRows),
            'items' => $groupRows,
        ];
    }

    usort($items, static function (array $a, array $b): int {
        $typeScore = static fn(string $type): int => $type === 'exact' ? 0 : 1;
        $cmp = $typeScore((string)($a['match_type'] ?? '')) <=> $typeScore((string)($b['match_type'] ?? ''));
        if ($cmp !== 0) return $cmp;
        return (string)($a['group_key'] ?? '') <=> (string)($b['group_key'] ?? '');
    });

    respond([
        'ok' => true,
        'result' => [
            'group_count' => count($items),
            'items' => $items,
        ],
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
