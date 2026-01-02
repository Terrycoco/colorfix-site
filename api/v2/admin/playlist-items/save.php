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

$orderIndex = 0;
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

    if ($itemId > 0) {
        $sql = <<<SQL
            UPDATE playlist_items
            SET
              order_index = :order_index,
              ap_id = :ap_id,
              image_url = :image_url,
              title = :title,
              subtitle = :subtitle,
              subtitle_2 = :subtitle_2,
              body = :body,
              item_type = :item_type,
              layout = :layout,
              title_mode = :title_mode,
              star = :star,
              transition = :transition,
              duration_ms = :duration_ms,
              is_active = :is_active
            WHERE playlist_item_id = :playlist_item_id
              AND playlist_id = :playlist_id
            SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($data, [
            'playlist_item_id' => $itemId,
        ]));
    } else {
        $sql = <<<SQL
            INSERT INTO playlist_items (
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
              is_active
            ) VALUES (
              :playlist_id,
              :order_index,
              :ap_id,
              :image_url,
              :title,
              :subtitle,
              :subtitle_2,
              :body,
              :item_type,
              :layout,
              :title_mode,
              :star,
              :transition,
              :duration_ms,
              :is_active
            )
            SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        $item['playlist_item_id'] = (int)$pdo->lastInsertId();
    }

    $orderIndex++;
}

respond(['ok' => true]);
