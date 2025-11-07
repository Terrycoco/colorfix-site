<?php
// OPTIONS preflight (same as your other simple endpoints)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json; charset=UTF-8');

// If PHP ever throws, return JSON (not blank/HTML)
set_error_handler(function($sev, $msg, $file, $line) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>"PHP error: $msg", 'at'=>"$file:$line"], JSON_UNESCAPED_SLASHES);
    exit;
});
set_exception_handler(function($e){
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
    exit;
});

require_once 'db.php'; // must provide $pdo

// Make sure PDO throws (if db.php didn’t already)
if (isset($pdo) && $pdo instanceof PDO) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// POST only
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false, 'error'=>'Use POST']);
    exit;
}

// Read JSON body
$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'Invalid JSON']);
    exit;
}

// Accept ids: array or "1,2,3"
$ids = $in['ids'] ?? [];
if (is_string($ids)) {
    $ids = array_map('intval', array_filter(array_map('trim', explode(',', $ids))));
} elseif (is_array($ids)) {
    $ids = array_values(array_unique(array_map('intval', $ids)));
} else {
    $ids = [];
}
if (count($ids) < 2 || count($ids) > 6) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'Provide 2–6 ids', 'got'=>count($ids)]);
    exit;
}

// Resolve ids -> hex6 from swatch_view and colors (uppercase)
$ph = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT UPPER(hex6) AS hex6 FROM swatch_view WHERE id IN ($ph)
        UNION
        SELECT UPPER(hex6) AS hex6 FROM colors WHERE id IN ($ph)";
$stmt = $pdo->prepare($sql);
// bind both sets in order
$k = 1;
foreach ($ids as $id) $stmt->bindValue($k++, $id, PDO::PARAM_INT);
foreach ($ids as $id) $stmt->bindValue($k++, $id, PDO::PARAM_INT);
$stmt->execute();
$hexes = array_values(array_unique(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN, 0), function($h){
    return is_string($h) && preg_match('/^[0-9A-Fa-f]{6}$/', $h);
})));
$hexes = array_map('strtoupper', $hexes);

if (count($hexes) < 2) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'Unable to resolve 2+ colors from ids', 'resolved_hexes'=>$hexes, 'ids'=>$ids]);
    exit;
}

// Build canonical unordered pairs (lo, hi)
$pairs = [];
$seen  = [];
for ($i=0; $i<count($hexes); $i++) {
    for ($j=$i+1; $j<count($hexes); $j++) {
        $a = $hexes[$i]; $b = $hexes[$j];
        if ($a === $b) continue;
        $lo = ($a <= $b) ? $a : $b;
        $hi = ($a <= $b) ? $b : $a;
        $key = $lo . ':' . $hi;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $pairs[] = [$lo, $hi];
    }
}
if (!$pairs) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'No pairs after canonicalization', 'resolved_hexes'=>$hexes]);
    exit;
}

// Insert new pairs only; do not touch existing created_at
$attempted = count($pairs);
$inserted  = 0;
$skipped   = 0;

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare("INSERT IGNORE INTO color_friends (hex1, hex2) VALUES (?, ?)");
    foreach ($pairs as [$lo, $hi]) {
        // final guard
        if (!preg_match('/^[0-9A-F]{6}$/', $lo) || !preg_match('/^[0-9A-F]{6}$/', $hi)) {
            throw new RuntimeException("Bad pair: $lo,$hi");
        }
        $ins->execute([$lo, $hi]);
        if ($ins->rowCount() === 1) $inserted++; else $skipped++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'DB insert failed', 'detail'=>$e->getMessage()]);
    exit;
}

// Done
echo json_encode([
    'ok' => true,
    'ids' => $ids,
    'resolved_hexes' => $hexes,
    'pairs' => $pairs,
    'attempted_pairs' => $attempted,
    'inserted_pairs' => $inserted,
    'skipped_pairs' => $skipped
], JSON_UNESCAPED_SLASHES);
