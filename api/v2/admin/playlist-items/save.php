<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Services\PlaylistPhotoLibrarySyncService;

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

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$playlistId = isset($payload['playlist_id']) ? (int)$payload['playlist_id'] : 0;
$items = $payload['items'] ?? [];

if ($playlistId <= 0) {
    respond(['ok' => false, 'error' => 'playlist_id required'], 400);
}
if (!is_array($items)) {
    respond(['ok' => false, 'error' => 'items must be array'], 400);
}

try {
    $pdo->beginTransaction();
    $hasExcludeFromThumbs = columnExists($pdo, 'playlist_items', 'exclude_from_thumbs');
    $hasPhotoLibraryId = columnExists($pdo, 'playlist_items', 'photo_library_id');
    $hasSavedPaletteSetId = columnExists($pdo, 'playlist_items', 'saved_palette_set_id');
    $hasIsShareImage = columnExists($pdo, 'playlist_items', 'is_share_image');
    $hasSite = columnExists($pdo, 'playlist_items', 'site');
    $hasYt = columnExists($pdo, 'playlist_items', 'yt');
    $hasAnalyzerRole = columnExists($pdo, 'playlist_items', 'analyzer_role');
    $selectedShareIndex = null;
    foreach ($items as $idx => $candidate) {
        $candidateHasPhoto = (
            (isset($candidate['photo_library_id']) && $candidate['photo_library_id'] !== '')
            || trim((string)($candidate['image_url'] ?? '')) !== ''
        );
        if ($candidateHasPhoto && !empty($candidate['is_share_image'])) {
            $selectedShareIndex = $idx;
            break;
        }
    }
    $playlistPhotoSync = new PlaylistPhotoLibrarySyncService($pdo);
    $stmt = $pdo->prepare('UPDATE playlist_items SET order_index = order_index + 10000 WHERE playlist_id = :playlist_id');
    $stmt->execute(['playlist_id' => $playlistId]);

    $orderIndex = 0;
    $keepIds = [];
    foreach ($items as $item) {
        $itemId = isset($item['playlist_item_id']) ? (int)$item['playlist_item_id'] : 0;
        $hasPhoto = (
            (isset($item['photo_library_id']) && $item['photo_library_id'] !== '')
            || trim((string)($item['image_url'] ?? '')) !== ''
        );
        $data = [
            'playlist_id' => $playlistId,
            'order_index' => $orderIndex,
            'ap_id' => isset($item['ap_id']) && $item['ap_id'] !== '' ? (int)$item['ap_id'] : null,
            'palette_hash' => isset($item['palette_hash']) && $item['palette_hash'] !== '' ? (string)$item['palette_hash'] : null,
            'image_url' => $item['image_url'] ?? null,
            'photo_library_id' => isset($item['photo_library_id']) && $item['photo_library_id'] !== '' ? (int)$item['photo_library_id'] : null,
            'saved_palette_set_id' => isset($item['saved_palette_set_id']) && $item['saved_palette_set_id'] !== '' ? (int)$item['saved_palette_set_id'] : null,
            'title' => $item['title'] ?? null,
            'subtitle' => $item['subtitle'] ?? null,
            'subtitle_2' => $item['subtitle_2'] ?? null,
            'body' => $item['body'] ?? null,
            'item_type' => $item['item_type'] ?? 'non-palette',
            'layout' => $item['layout'] ?? 'default',
            'title_mode' => $item['title_mode'] ?? null,
            'star' => isset($item['star']) ? (int)(bool)$item['star'] : 1,
            'transition' => $item['transition'] ?? null,
            'duration_ms' => isset($item['duration_ms']) && $item['duration_ms'] !== '' ? (int)$item['duration_ms'] : null,
            'is_active' => isset($item['is_active']) ? (int)(bool)$item['is_active'] : 1,
        ];
        if ($hasSite) {
            $data['site'] = array_key_exists('site', $item) ? (int)(bool)$item['site'] : 1;
        }
        if ($hasYt) {
            $data['yt'] = array_key_exists('yt', $item) ? (int)(bool)$item['yt'] : 1;
        }
        if ($hasAnalyzerRole) {
            $role = strtolower(trim((string)($item['analyzer_role'] ?? 'ignore')));
            $data['analyzer_role'] = in_array($role, ['ignore', 'before', 'after'], true) ? $role : 'ignore';
        }
        if ($hasExcludeFromThumbs) {
            $data['exclude_from_thumbs'] = isset($item['exclude_from_thumbs']) ? (int)(bool)$item['exclude_from_thumbs'] : 0;
        }
        if ($hasIsShareImage) {
            $data['is_share_image'] = ($hasPhoto && $selectedShareIndex === $orderIndex) ? 1 : 0;
        }
        $data = $playlistPhotoSync->normalizeItemForSave($data);
        if (!$hasPhotoLibraryId) {
            unset($data['photo_library_id']);
        }
        if (!$hasSavedPaletteSetId) {
            unset($data['saved_palette_set_id']);
        }
        if (!$hasIsShareImage) {
            unset($data['is_share_image']);
        }

        $columns = [
            'playlist_id',
            'order_index',
            'ap_id',
            'palette_hash',
            'image_url',
            'photo_library_id',
            'saved_palette_set_id',
            'title',
            'subtitle',
            'subtitle_2',
            'body',
            'item_type',
            'layout',
            'title_mode',
            'star',
            'transition',
            'duration_ms',
            'is_active',
            'is_share_image',
            'site',
            'yt',
            'analyzer_role',
        ];
        if (!$hasPhotoLibraryId) {
            $columns = array_values(array_filter($columns, fn($col) => $col !== 'photo_library_id'));
        }
        if (!$hasSavedPaletteSetId) {
            $columns = array_values(array_filter($columns, fn($col) => $col !== 'saved_palette_set_id'));
        }
        if (!$hasIsShareImage) {
            $columns = array_values(array_filter($columns, fn($col) => $col !== 'is_share_image'));
        }
        if (!$hasSite) {
            $columns = array_values(array_filter($columns, fn($col) => $col !== 'site'));
        }
        if (!$hasYt) {
            $columns = array_values(array_filter($columns, fn($col) => $col !== 'yt'));
        }
        if (!$hasAnalyzerRole) {
            $columns = array_values(array_filter($columns, fn($col) => $col !== 'analyzer_role'));
        }
        if ($hasExcludeFromThumbs) {
            $columns[] = 'exclude_from_thumbs';
        }
        $updateColumns = array_values(array_filter($columns, fn($col) => $col !== 'playlist_id'));
        $setSql = implode(",\n                  ", array_map(fn($col) => "{$col} = :{$col}", $updateColumns));
        $insertColumns = $columns;
        $insertSqlCols = implode(",\n                  ", $insertColumns);
        $insertSqlVals = implode(",\n                  ", array_map(fn($col) => ":{$col}", $insertColumns));

        if ($itemId > 0) {
            $sql = <<<SQL
                UPDATE playlist_items
                SET
                  {$setSql}
                WHERE playlist_item_id = :playlist_item_id
                  AND playlist_id = :playlist_id
                SQL;
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($data, [
                'playlist_item_id' => $itemId,
            ]));
            $keepIds[] = $itemId;
        } else {
            $sql = <<<SQL
                INSERT INTO playlist_items (
                  {$insertSqlCols}
                ) VALUES (
                  {$insertSqlVals}
                )
                SQL;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($data);
            $item['playlist_item_id'] = (int)$pdo->lastInsertId();
            $keepIds[] = (int)$item['playlist_item_id'];
        }

        $orderIndex++;
    }

    if ($keepIds) {
        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $sql = "DELETE FROM playlist_items WHERE playlist_id = ? AND playlist_item_id NOT IN ({$placeholders})";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$playlistId], $keepIds));
    } else {
        $stmt = $pdo->prepare("DELETE FROM playlist_items WHERE playlist_id = ?");
        $stmt->execute([$playlistId]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}

respond(['ok' => true]);
