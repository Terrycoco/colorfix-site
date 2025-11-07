<?php
require_once 'db.php';

header("Content-Type: application/json");

try {
    $stmt = $pdo->query("SELECT hcl_hue, r, g, b, hex, hcl_l, hcl_c FROM hcl_rgb_lookup ORDER BY hcl_hue ASC");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $results
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
