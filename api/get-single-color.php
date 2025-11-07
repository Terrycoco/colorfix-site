<?php
header("Content-Type: application/json");
require_once 'db.php'; // DB connection

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_GET['id'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing color ID'
    ]);
    exit;
}

$id = intval($_GET['id']);

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch main color info and lookup data
    $sql = "
        SELECT
            c.*,
            co.name AS brand_name,
            co.base_url,
            lc.name AS light_cat,
            lc.description AS light_cat_descr,
            cc.name AS chroma_cat,
            cc.description AS chroma_cat_descr
        FROM colors c
        JOIN company co ON c.brand = co.code
        LEFT JOIN category_definitions lc ON c.light_cat_id = lc.id
        LEFT JOIN category_definitions cc ON c.chroma_cat_id = cc.id
        WHERE c.id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $id]);
    $color = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$color) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Color not found'
        ]);
        exit;
    }

    // Fetch other category_definitions assigned to this color
    $sql2 = "
        SELECT
            cd.id,
            cd.name,
            cd.type,
            cd.hue_min,
            cd.hue_max,
            cd.chroma_min,
            cd.chroma_max,
            cd.light_min,
            cd.light_max,
            cd.notes,
            cd.description
        FROM color_category cc
        JOIN category_definitions cd ON cc.category_id = cd.id
        WHERE cc.color_id = :id
    ";
    $stmt2 = $pdo->prepare($sql2);
    $stmt2->execute(['id' => $id]);
    $categories = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // Attach categories array to the color
    $color['cats'] = $categories;

    echo json_encode([
        'status' => 'success',
        'data' => $color
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
