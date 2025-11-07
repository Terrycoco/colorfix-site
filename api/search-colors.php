<?php
ini_set('log_errors', 1);
ini_set('display_errors', 0); // Hide errors from browser
ini_set('error_log', __DIR__ . '/colorfix-error.log');
error_log("✔️ search-colors.php started at " . date('c'));
header("Content-Type: application/json");

require_once 'db.php';

// Optional write test
file_put_contents(__DIR__ . '/test-write.txt', "PHP write test at " . date('c') . "\n", FILE_APPEND);

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $input = json_decode(file_get_contents("php://input"), true);

    $name     = $input['name']     ?? null;
    $brand    = $input['brand']    ?? null;
    $category = $input['category'] ?? null;
    $minHue   = $input['hueMin']   ?? null;
    $maxHue   = $input['hueMax']   ?? null;
  //  $lGroup  = $input['lGroup'] ?? null;
  //  $cGroup  = $input['cGroup'] ?? null;


    $conditions = [];
    $params = [];

    // Build base query
    $sql = "
        SELECT c.id, c.name, c.code, c.brand, c.r, c.g, c.b,
            c.hcl_l, c.hcl_c, c.hcl_h, c.hue_cats, c.neutral_cats
        FROM colors c
    ";

    // Add joins only if needed
    if ($category) {
        $sql .= "
            INNER JOIN color_category cc ON c.id = cc.color_id
            INNER JOIN category_definitions cat ON cc.category_id = cat.id
        ";
    }

    // Start WHERE clause
    $sql .= " WHERE 1=1";

    if ($name) {
        $sql .= " AND LOWER(c.name) LIKE :name";
        $params[':name'] = '%' . strtolower($name) . '%';
    }

    if ($brand) {
        $sql .= " AND LOWER(c.brand) = :brand";
        $params[':brand'] = strtolower($brand);
    }

    if ($category) {
        $sql .= " AND cat.name = :category";
        $params[':category'] = $category;
    }

    if ($minHue !== null && $maxHue !== null) {
        $sql .= " AND c.hcl_h BETWEEN :minHue AND :maxHue";
        $params[':minHue'] = (float)$minHue;
        $params[':maxHue'] = (float)$maxHue;
    }
    if ($lGroup) {
        $sql .= " AND c.l_group = :lGroup";
        $params[':lGroup'] = $lGroup;
        }

    if ($cGroup) {
        $sql .= " AND c.c_group = :cGroup";
        $params[':cGroup'] = $cGroup;
    }

    $sql .= " ORDER BY c.hcl_l DESC";

    // Optional logging
    $logEntry = "SQL: " . $sql . "\n" . "Params: " . print_r($params, true) . "\n\n";
    file_put_contents('./search-debug.log', $logEntry, FILE_APPEND);

    // Run query
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
?>