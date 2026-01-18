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
    $stmt = $pdo->prepare('UPDATE playlist_items SET order_index = order_index + 10000 WHERE playlist_id = :playlist_id');
    $stmt->execute(['playlist_id' => $playlistId]);

    $orderIndex = 0;
    $keepIds = [];
    foreach ($items as $item) {
        $itemId = isset($item['playlist_item_id']) ? (int)$item['playlist_item_id'] : 0;
        $data = [
            'playlist_id' => $playlistId,
            'order_index' => $orderIndex,
            'ap_id' => isset($item['ap_id']) && $item['ap_id'] !== '' ? (int)$item['ap_id'] : null,
            'image_url' => $item['image_url'] ?? null,
            'title' => $item['title'] ?? null,
            'subtitle' => $item['subtitle'] ?? null,
            'subtitle_2' => $item['subtitle_2'] ?? null,
            'body' => $item['body'] ?? null,
            'item_type' => $item['item_type'] ?? 'normal',
            'layout' => $item['layout'] ?? 'default',
            'title_mode' => $item['title_mode'] ?? null,
            'star' => isset($item['star']) ? (int)(bool)$item['star'] : 1,
            'transition' => $item['transition'] ?? null,
            'duration_ms' => isset($item['duration_ms']) && $item['duration_ms'] !== '' ? (int)$item['duration_ms'] : null,
            'is_active' => isset($item['is_active']) ? (int)(bool)$item['is_active'] : 1,
        ];
        if ($hasExcludeFromThumbs) {
            $data['exclude_from_thumbs'] = isset($item['exclude_from_thumbs']) ? (int)(bool)$item['exclude_from_thumbs'] : 0;
        }

        $columns = [
            'playlist_id',
            'order_index',
            'ap_id',
            'image_url',
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
        ];
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
        $sql = "UPDATE playlist_items SET is_active = 0 WHERE playlist_id = ? AND playlist_item_id NOT IN ({$placeholders})";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$playlistId], $keepIds));
    } else {
        $stmt = $pdo->prepare("UPDATE playlist_items SET is_active = 0 WHERE playlist_id = ?");
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
