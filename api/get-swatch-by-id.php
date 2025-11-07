<?php
require_once 'db.php'; // your PDO setup

header('Content-Type: application/json');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    echo json_encode(['error' => 'Missing or invalid id']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM swatch_view WHERE id = ?");
    $stmt->execute([$id]);
    $swatch = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($swatch) {
        echo json_encode($swatch);
    } else {
        echo json_encode(['error' => 'Color not found']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
