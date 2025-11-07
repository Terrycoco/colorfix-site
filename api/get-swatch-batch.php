<?php
require_once 'db.php';
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    // Read POST body as JSON
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['ids']) || !is_array($input['ids']) || count($input['ids']) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid ids']);
        exit;
    }

    // Sanitize IDs to integers
    $ids = array_map('intval', $input['ids']);

    // Build placeholders and query
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT * FROM swatch_view WHERE id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($results);
} catch (Throwable $e) {
    logError("get-swatch-batch.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
