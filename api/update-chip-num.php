<?php
// Parse JSON or x-www-form-urlencoded
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');


$input = file_get_contents('php://input');
$data = [];
if ($input) {
$json = json_decode($input, true);
if (is_array($json)) $data = $json;
}
// Allow query / form overrides
foreach (['id','name','chip_num'] as $k) {
if (isset($_POST[$k])) $data[$k] = $_POST[$k];
if (isset($_GET[$k])) $data[$k] = $_GET[$k];
}


$id = isset($data['id']) ? (int)$data['id'] : 0;
$name = isset($data['name']) ? trim((string)$data['name']) : '';
$chipRaw = isset($data['chip_num']) ? trim((string)$data['chip_num']) : '';


if ($chipRaw === '') { echo json_encode(['ok'=>false,'error'=>'chip_num required']); exit; }
$chip = mb_substr($chipRaw, 0, 10);


try {
if ($id > 0) {
$stmt = $pdo->prepare("UPDATE colors SET chip_num=:chip WHERE id=:id AND brand='de' LIMIT 1");
$stmt->execute([':chip'=>$chip, ':id'=>$id]);
$count = $stmt->rowCount();
} else {
if ($name === '') { echo json_encode(['ok'=>false,'error'=>'id or name required']); exit; }
$stmt = $pdo->prepare("UPDATE colors SET chip_num=:chip WHERE name=:name AND brand='de' LIMIT 1");
$stmt->execute([':chip'=>$chip, ':name'=>$name]);
$count = $stmt->rowCount();
}


echo json_encode([
'ok' => true,
'updated' => (int)$count,
'id' => $id,
'name' => $name,
'chip_num' => $chip
], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
http_response_code(500);
echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}

