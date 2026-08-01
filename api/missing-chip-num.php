<?php
// Lists colors for chip-number cleanup across brands.
// Optional query params:
// q — substring match on name or code
// chip — substring match on existing chip_num
// brand — company code, or blank for all brands
// missing_only — 1 to show only colors without chip_num, 0 to include all
// limit — default 200 (max 1000)
// offset — default 0
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$chip = isset($_GET['chip']) ? trim((string)$_GET['chip']) : '';
$brand = isset($_GET['brand']) ? strtolower(trim((string)$_GET['brand'])) : '';
$missingOnly = !isset($_GET['missing_only']) || (string)$_GET['missing_only'] !== '0';
$missingOnly = $chip !== '' ? false : $missingOnly;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
if ($limit <= 0 || $limit > 1000) $limit = 200;
if ($offset < 0) $offset = 0;

$where = ["COALESCE(is_stain, 0) = 0"];
$args = [];

if ($brand !== '') {
    $where[] = "LOWER(brand) = :brand";
    $args[':brand'] = $brand;
}

if ($missingOnly) {
    $where[] = "(chip_num IS NULL OR chip_num = '')";
}

if ($q !== '') {
    $where[] = "(name LIKE :q_name OR code LIKE :q_code)";
    $args[':q_name'] = "%$q%";
    $args[':q_code'] = "%$q%";
}

if ($chip !== '') {
    $where[] = "chip_num LIKE :chip";
    $args[':chip'] = "%$chip%";
}

$whereSql = implode(' AND ', $where);
$sql = "
    SELECT id, name, brand, code, chip_num
    FROM colors
    WHERE {$whereSql}
    ORDER BY brand, name
    LIMIT :limit OFFSET :offset
";
$countSql = "SELECT COUNT(*) FROM colors WHERE {$whereSql}";
$missingWhere = ["COALESCE(is_stain, 0) = 0", "(chip_num IS NULL OR chip_num = '')"];
$missingArgs = [];
if ($brand !== '') {
    $missingWhere[] = "LOWER(brand) = :brand";
    $missingArgs[':brand'] = $brand;
}
$missingSql = "SELECT COUNT(*) FROM colors WHERE " . implode(' AND ', $missingWhere);

try {
    $stmt = $pdo->prepare($sql);
    foreach ($args as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $pdo->prepare($countSql);
    foreach ($args as $k => $v) $countStmt->bindValue($k, $v, PDO::PARAM_STR);
    $countStmt->execute();
    $matchingTotal = (int)$countStmt->fetchColumn();

    $missingStmt = $pdo->prepare($missingSql);
    foreach ($missingArgs as $k => $v) $missingStmt->bindValue($k, $v, PDO::PARAM_STR);
    $missingStmt->execute();
    $totalMissing = (int)$missingStmt->fetchColumn();

    $brandStmt = $pdo->query("
        SELECT c.code, c.name, COUNT(colors.id) AS color_count,
               SUM(CASE WHEN colors.chip_num IS NULL OR colors.chip_num = '' THEN 1 ELSE 0 END) AS missing_count
        FROM company c
        JOIN colors ON colors.brand = c.code
        WHERE COALESCE(colors.is_stain, 0) = 0
        GROUP BY c.code, c.name
        ORDER BY c.name
    ");
    $brands = $brandStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'missing_total' => $totalMissing,
        'matching_total' => $matchingTotal,
        'limit' => $limit,
        'offset' => $offset,
        'q' => $q,
        'chip' => $chip,
        'brand' => $brand,
        'missing_only' => $missingOnly,
        'brands' => $brands,
        'rows' => $rows
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
