<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';

header('Content-Type: application/json');

$logFile = __DIR__ . '/upsert-filter-error.log';

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

file_put_contents(__DIR__ . '/debug-upsert-filter.json', json_encode($data, JSON_PRETTY_PRINT));

try {
  $stmt = $pdo->prepare("
    INSERT INTO filter_definitions (
      tablename, fieldname, name, label
    ) VALUES (
      :tablename, :fieldname, :name, :label
    )
    ON DUPLICATE KEY UPDATE
      name = VALUES(name),
      label = VALUES(label)
  ");

  $stmt->execute([
    ':tablename' => $data['tablename'],
    ':fieldname' => $data['fieldname'],
    ':name' => $data['name'],
    ':label' => $data['label']
  ]);

  // Return the updated row
  $stmt = $pdo->prepare("
    SELECT * FROM filter_definitions
    WHERE tablename = ? AND fieldname = ?
  ");
  $stmt->execute([$data['tablename'], $data['fieldname']]);
  $filter = $stmt->fetch(PDO::FETCH_ASSOC);

  echo json_encode([
    'success' => true,
    'filter' => $filter
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
