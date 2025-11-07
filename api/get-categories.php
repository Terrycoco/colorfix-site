<?php
require_once 'db.php';

//header("Access-Control-Allow-Origin: *"); // Allows all domains (or replace * with a specific origin)
//header("Access-Control-Allow-Headers: Content-Type");
//header("Content-Type: application/json");

$sql = "SELECT 
          id, 
          name, 
          type, 
          description, 
          hue_min, 
          hue_max,
          chroma_min,
          chroma_max,
          light_min,
          light_max,
          wheel_text_color 
          FROM category_definitions
          where calc_only = 0
          and active=1
          ORDER BY hue_min ASC";


try {
    $stmt = $pdo->query($sql);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $categories
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}




