<?php
require_once 'db.php';

header('Content-Type: application/json');

try {
  $stmt = $pdo->prepare("SELECT * FROM filter_definitions ORDER BY id DESC");
  $stmt->execute();
  $filters = $stmt->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode($filters);
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
