<?php
header("Content-Type: application/json");

require_once 'db.php'; // DB connection

// Enable error reporting during development
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Only return summary fields needed for search + swatch display
    $sql = "
        SELECT 
            id, name, code, brand, r, g, b,
            hcl_h, hcl_c, hcl_l,
            hue_cats, neutral_cats
        FROM colors
        ORDER BY name ASC;
    ";

    $stmt = $pdo->query($sql);
    $colors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $colors
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
