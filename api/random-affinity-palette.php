<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');
require_once 'db.php';
header('Content-Type: application/json');

try {
   $seed = microtime(true);
$stmt = $pdo->prepare("
    SELECT id, name, code, brand, r, g, b, hcl_h, hcl_c, hcl_l, collection
    FROM colors
    WHERE collection LIKE '%Affinity%'
    ORDER BY RAND($seed)
    LIMIT 5
");

    $stmt->execute();
    $colors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'colors' => $colors]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
