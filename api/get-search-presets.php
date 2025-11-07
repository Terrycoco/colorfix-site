<?php


// Handle OPTIONS preflight request and exit early
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}


//header('Content-Type: application/json');
require_once 'db.php'; // Your DB connection script

try {
    $stmt = $pdo->prepare("SELECT * FROM color_search_presets ORDER BY display_order, id");
    $stmt->execute();
    $presets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($presets);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
