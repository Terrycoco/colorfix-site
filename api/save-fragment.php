<?php
require_once 'db.php'; // your DB connection

header('Content-Type: application/json');

// Get JSON input
$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($data['name'], $data['text'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: name and text']);
    exit;
}

$name = trim($data['name']);
$text = trim($data['text']);

// Prepare and execute insert
$stmt = $pdo->prepare("INSERT INTO fragments (name, text) VALUES (?, ?)");
$stmt->execute([$name, $text]);

// Return the new ID
$newId = $pdo->lastInsertId();

echo json_encode([
    'success' => true,
    'id' => $newId,
    'name' => $name,
    'text' => $text
]);
