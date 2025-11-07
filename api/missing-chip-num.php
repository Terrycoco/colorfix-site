<?php
// Lists Dunn-Edwards colors that don't have a chip number yet.
// Optional query params:
// q — substring match on name (case-insensitive)
// limit — default 200 (max 1000)
// offset — default 0
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');


$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
if ($limit <= 0 || $limit > 1000) $limit = 200;
if ($offset < 0) $offset = 0;


$sql = "SELECT id, name, brand, code, chip_num
FROM colors
WHERE brand = 'de' AND is_stain = 0
AND (chip_num IS NULL)
";
$args = [];
if ($q !== '') {
$sql .= " AND name LIKE :q ";
$args[':q'] = "%$q%";
}
$sql .= " ORDER BY name LIMIT :limit OFFSET :offset";


try {
$stmt = $pdo->prepare($sql);
foreach ($args as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


// quick count for UI (rough; not filtered on q for perf unless needed)
$totalStmt = $pdo->query("SELECT COUNT(*) AS c FROM colors WHERE brand='de' AND is_stain = 0 AND  (chip_num IS NULL)");
$totalMissing = (int)($totalStmt->fetchColumn());


echo json_encode([
'ok' => true,
'missing_total' => $totalMissing,
'limit' => $limit,
'offset' => $offset,
'q' => $q,
'rows' => $rows
], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
http_response_code(500);
echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}