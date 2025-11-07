<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';

header('Content-Type: application/json');

// 🔽 Error log path
$logFile = __DIR__ . '/upsert-item-error.log';

function logError($msg) {
    global $logFile;
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
  http_response_code(400);
  logError("Invalid JSON received");
  echo json_encode(['error' => 'Invalid JSON']);
  exit;
}

file_put_contents(__DIR__ . '/debug-upsert.json', json_encode($data, JSON_PRETTY_PRINT));


try {
 if (!empty($data['id'])) {
  $stmt = $pdo->prepare("
    UPDATE items SET
      handle = :handle,
      title = :title,
      subtitle = :subtitle,
      display = :display,
      description = :description,
      image_url = :image_url,
      body = :body,
      target_url = :target_url,
      query_id = :query_id,
      item_type = :item_type,
      is_clickable = :is_clickable,
      is_pinnable = :is_pinnable,
      is_active = :is_active,
      color = :color,
      insert_position = :insert_position
    WHERE id = :id
  ");

  $stmt->execute([
    ':id' => $data['id'],
    ':handle' => $data['handle'],
    ':title' => $data['title'],
    ':subtitle' => $data['subtitle'],
    ':display' => $data['display'],
    ':description' => $data['description'],
    ':image_url' => $data['image_url'],
    ':body' => $data['body'],
    ':target_url' => $data['target_url'],
    ':query_id' => $data['query_id'],
    ':item_type' => $data['item_type'],
    ':is_clickable' => $data['is_clickable'],
    ':is_pinnable' => $data['is_pinnable'],
    ':is_active' => $data['is_active'],
    ':color' => $data['color'],
    ':insert_position' => $data['insert_position']
  ]);
} else {
  $stmt = $pdo->prepare("
    INSERT INTO items (
      handle, title, subtitle, display, description, image_url, body, target_url,
      query_id, item_type, is_clickable, is_pinnable, is_active, color, insert_position
    ) VALUES (
      :handle, :title, :subtitle, :display, :description, :image_url, :body, :target_url,
      :query_id, :item_type, :is_clickable, :is_pinnable, :is_active, :color, :insert_position
    )
  ");

  $stmt->execute([
    ':handle' => $data['handle'],
    ':title' => $data['title'],
    ':subtitle' => $data['subtitle'],
    ':display' => $data['display'],
    ':description' => $data['description'],
    ':image_url' => $data['image_url'],
    ':body' => $data['body'],
    ':target_url' => $data['target_url'],
    ':query_id' => $data['query_id'],
    ':item_type' => $data['item_type'],
    ':is_clickable' => $data['is_clickable'],
    ':is_pinnable' => $data['is_pinnable'],
    ':is_active' => $data['is_active'],
    ':color' => $data['color'],
    ':insert_position' => $data['insert_position']
  ]);
}

  $id = $data['id'] ?? $pdo->lastInsertId();
  $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
  $stmt->execute([$id]);
  $item = $stmt->fetch(PDO::FETCH_ASSOC);

  echo json_encode([
    'success' => true,
    'item' => $item
  ]);

} catch (PDOException $e) {
  logError("PDOException: " . $e->getMessage());
  logError("With data: " . json_encode($data));
  http_response_code(500);
  echo json_encode(['error' => 'Database error']);

} catch (Exception $e) {
  logError("Exception: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'Unexpected error']);
}
