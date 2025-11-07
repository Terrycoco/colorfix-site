<?php
require_once 'db.php';

header('Content-Type: application/json');

try {
  $stmt = $pdo->prepare("SELECT * FROM items ORDER BY id DESC");
  $stmt->execute();
  $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode($items);
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
