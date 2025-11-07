<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';

header('Content-Type: application/json');

$logFile = __DIR__ . '/save-query-error.log';

function logError($message) {
  global $logFile;
  file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $message\n", FILE_APPEND);
}

// Decode input
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
  logError("JSON decode failed: $raw");
  http_response_code(400);
  echo json_encode(['error' => 'Invalid JSON input.']);
  exit;
}

// Extract & sanitize fields
$id               = isset($data['query_id']) ? intval($data['query_id']) : null;
$name             = trim($data['name'] ?? '');
$display          = trim($data['display'] ?? '');
$type             = trim($data['type'] ?? 'custom');
$active           = isset($data['active']) ? intval($data['active']) : 1;
$sort_order       = floatval($data['sort_order'] ?? 0);
$query            = trim($data['query'] ?? '');
$notes            = trim($data['notes'] ?? '');
$description      = trim($data['description'] ?? '');
$item_type        = trim($data['item_type'] ?? '');
$on_click_query   = isset($data['on_click_query']) ? intval($data['on_click_query']) : null;
$on_click_url     = trim($data['on_click_url'] ?? '');
$parent_id        = isset($data['parent_id']) ? intval($data['parent_id']) : null;
$color            = trim($data['color'] ?? '');
$image_url        = trim($data['image_url'] ?? '');
$pinnable         = isset($data['pinnable']) ? intval($data['pinnable']) : 0;
$has_header       = isset($data['has_header']) ? intval($data['has_header']) : 0;
$header_title      = trim($data['header_title'] ?? '');
$header_subtitle  = trim($data['header_subtitle'] ?? '');
$header_content      = trim($data['header_content'] ?? '');

// Validation
if ($name === '' || $query === '') {
  logError("Missing required fields: name or query. Input: " . json_encode($data));
  http_response_code(400);
  echo json_encode(['error' => 'Name and query are required.']);
  exit;
}

try {
  if ($id) {
    // Update
    $stmt = $pdo->prepare("
      UPDATE sql_queries
      SET name = ?, display = ?, type = ?, active = ?, sort_order = ?, query = ?, notes = ?,
          description = ?, item_type = ?, on_click_query = ?, on_click_url = ?, parent_id = ?,
          color = ?, image_url = ?, pinnable = ?, has_header = ?, header_title = ?, header_subtitle = ?, header_content = ?
      WHERE query_id = ?
    ");
    $stmt->execute([
      $name, $display, $type, $active, $sort_order, $query, $notes,
      $description, $item_type, $on_click_query, $on_click_url, $parent_id,
      $color, $image_url, $pinnable, $has_header, $header_title, $header_subtitle, $header_content, $id
    ]);
  } else {
    // Insert
    $stmt = $pdo->prepare("
      INSERT INTO sql_queries
      (name, display, type, active, sort_order, query, notes, description, item_type,
       on_click_query, on_click_url, parent_id, color, image_url, pinnable, has_header, header_title, header_subtitle, header_content)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,?,?, ?)
    ");
    $stmt->execute([
      $name, $display, $type, $active, $sort_order, $query, $notes, $description, $item_type,
      $on_click_query, $on_click_url, $parent_id, $color, $image_url, $pinnable, $has_header, $header_title, $header_subtitle, $header_content
    ]);
    $id = $pdo->lastInsertId();
  }

  echo json_encode(['success' => true, 'id' => $id]);

} catch (PDOException $e) {
  logError("SQL error: " . $e->getMessage() . " | Input: " . json_encode($data));
  http_response_code(500);
  echo json_encode(['error' => 'Database error. See log.']);
}
