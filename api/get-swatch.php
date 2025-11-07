<?php
require_once 'db.php';
require_once __DIR__ . '/config.php'; // optional, for error logging

header('Content-Type: application/json');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid id']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM swatch_view WHERE id = ?");
    $stmt->execute([$id]);
    $swatch = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$swatch) {
        http_response_code(404);
        echo json_encode(['error' => 'Swatch not found']);
        exit;
    }

    echo json_encode($swatch);
} catch (Throwable $e) {
    logError("get-swatch.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
