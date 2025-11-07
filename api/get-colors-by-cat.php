<?php
header("Content-Type: application/json");

require_once 'db.php';

$category = $_GET['category'] ?? '';

try {
    if ($category === '' || strtolower($category) === 'show all' || strtolower($category) === 'all') {
        $stmt = $pdo->query("
            SELECT *
            FROM colors
            ORDER BY hcl_h ASC
        ");
        $colors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.*
            FROM colors c
            JOIN color_category cc ON cc.color_id = c.id
            JOIN category_definitions cd ON cd.id = cc.category_id
            WHERE cd.name = :category
            ORDER BY c.hcl_l DESC, c.hcl_c ASC
        ");
        $stmt->execute(['category' => $category]);
        $colors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

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
