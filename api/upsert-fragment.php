<?php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php'; // your DB connection

header('Content-Type: application/json');

// Get JSON input
$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($data['name'], $data['text'], $data['type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: name, text, or type']);
    exit;
}

$name = trim($data['name']);
$text = trim($data['text']);
$type = trim($data['type']);
$id = isset($data['id']) && is_numeric($data['id']) ? intval($data['id']) : null;

if ($id) {
    // Update existing fragment
    $stmt = $pdo->prepare("UPDATE sql_fragments SET name = ?, text = ?, type = ? WHERE id = ?");
    $stmt->execute([$name, $text, $type, $id]);

    echo json_encode([
        'success' => true,
        'id' => $id,
        'name' => $name,
        'text' => $text,
        'type' => $type,
        'action' => 'updated'
    ]);
} else {
    // Insert new fragment
    $stmt = $pdo->prepare("INSERT INTO sql_fragments (name, text, type) VALUES (?, ?, ?)");
    $stmt->execute([$name, $text, $type]);
    $newId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'id' => $newId,
        'name' => $name,
        'text' => $text,
        'type' => $type,
        'action' => 'inserted'
    ]);
}
