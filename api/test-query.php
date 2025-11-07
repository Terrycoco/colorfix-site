<?php
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');  // puts it in the same folder as your script



require_once 'db.php'; // adjust if your DB connect file is named differently

header('Content-Type: application/json');

try {
  // Get the raw SQL from the POST body
  $input = json_decode(file_get_contents('php://input'), true);
  $sql = trim($input['sql'] ?? '');
    error_log('RAW SQL: [' . $sql . ']');
    error_log('ORD VALUES: [' . implode(',', array_map('ord', str_split(substr($sql, 0, 10)))) . ']');


    if (!$sql || stripos(ltrim($sql), 'select') !== 0) {
        throw new Exception('Only SELECT queries are allowed.');
    }


  // Prepare and run the SQL
  $stmt = $pdo->prepare($sql);
  $stmt->execute();

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $count = count($rows);

  echo json_encode([
    'success' => true,
    'count' => $count,
    'rows' => $rows,
  ]);
} catch (Exception $e) {
  echo json_encode([
    'success' => false,
    'error' => $e->getMessage(),
  ]);
}
