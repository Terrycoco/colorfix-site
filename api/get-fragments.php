<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db.php'; // adjust path as needed

header('Content-Type: application/json');

try {
  $stmt = $pdo->query("SELECT * FROM sql_fragments ORDER BY type, name");
  $fragments = $stmt->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode($fragments);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(["error" => $e->getMessage()]);
}
