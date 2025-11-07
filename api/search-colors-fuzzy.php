<?php

// Always emit JSON, never echo warnings in the body
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors','0');
ini_set('log_errors','1');

set_error_handler(function($sev, $msg, $file, $line) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>"PHP error: $msg",'at'=>"$file:$line"]);
  exit;
});
set_exception_handler(function($e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  exit;
});

require_once 'db.php';

ini_set('log_errors', 1);
ini_set('display_errors', 0);
ini_set('error_log', __DIR__ . '/php_error.log');

function log_error($msg) {
    error_log("[Search Fuzzy] $msg");
}

header('Content-Type: application/json');

$query = trim($_GET['q'] ?? '');

if ($query === '') {
    echo json_encode([]);
    exit;
}

// Log the query
try {
    $stmtLog = $pdo->prepare("
        INSERT INTO search_log (query, created_at)
        VALUES (:query, NOW())
    ");
    $stmtLog->execute(['query' => $query]);
} catch (Exception $e) {
    log_error("Failed to log search: " . $e->getMessage());
}

// Fuzzy match on name, code, brand_descr
try {
    $stmt = $pdo->prepare("
         SELECT 
            s.*  -- swatch_view contains all needed fields
        FROM swatch_view s
        WHERE 
            s.name COLLATE utf8_general_ci LIKE :like1
            OR s.code COLLATE utf8_general_ci LIKE :like2
        ORDER BY s.name, s.brand_name ASC
    ");

    $like = '%' . $query . '%';
    $stmt->execute([
        'like1' => $like,
        'like2' => $like
       // 'like3' => $like
    ]);

    $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $too_many = count($fetched) > 2000;
    $results = array_slice($fetched, 0, 2000);

    $response = [
        'results' => $results,
        'too_many' => $too_many
    ];
  error_log("RAW RESULT SAMPLE:");
if (!empty($results)) {
    error_log(print_r($results[0], true));
} else {
    error_log('[empty]');
}
    echo json_encode($response);
} catch (Exception $e) {
    log_error("DB error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error']);
}
