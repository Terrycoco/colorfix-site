<?php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

$st = $pdo->query("SELECT id, name, notes, hue_min, hue_max, chroma_min, chroma_max, light_min, light_max
                   FROM category_definitions ORDER BY name ASC");
echo json_encode(['success'=>true, 'categories'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
