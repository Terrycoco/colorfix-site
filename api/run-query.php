<?php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';
require_once __DIR__ . '/functions/filter-helpers.php';

header('Content-Type: application/json');

$logFile = __DIR__ . '/run-query-error.log';

function logError($msg) {
    global $logFile;
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $queryId = isset($data['query_id']) ? intval($data['query_id']) : 0;

    if (!$queryId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid query_id']);
        exit;
    }

    // Step 1: Fetch full query row. ---- 17 is for global inserts
        $stmtItems = $pdo->prepare("
            SELECT * FROM items 
            WHERE (query_id = ? OR query_id = 17) 
            AND is_active = 1
        ");
        $stmtItems->execute([$queryId]);
        $itemResults = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // ✅ Fetch the actual query row from sql_queries
        $stmtQuery = $pdo->prepare("SELECT * FROM sql_queries WHERE query_id = ?");
        $stmtQuery->execute([$queryId]);
        $queryRow = $stmtQuery->fetch(PDO::FETCH_ASSOC);


        if (!$queryRow || empty($queryRow['query'])) {
            http_response_code(404);
            echo json_encode(['error' => 'Query not found or missing SQL']);
            exit;
        }

  
   // Step 2: Run the contained query (with or without params)
     $queryText = $queryRow['query'];

            // Optional filters passed from front end
           $searchFilters = is_array($data['searchFilters'] ?? null) ? $data['searchFilters'] : [];


            // Add WHERE clause if needed
           list($finalQuery, $namedParams) = buildWhereClauseFromFilters($searchFilters, $queryText);


            $paramsRaw = $data['params'] ?? [];
            $params = is_array($paramsRaw) ? $paramsRaw : [];

            // Named params only — merge cleanly
            $params = array_merge($params, $namedParams);

            function extractNamedParamsFromQuery($queryText) {
                preg_match_all('/:([a-zA-Z0-9_]+)/', $queryText, $matches);
                return array_unique($matches[1]);
            }

            // Log for safety
            logError("Final query: $finalQuery | Params: " . json_encode($params));



      logError("Final query: $finalQuery | Params: " . json_encode($params));
       $stmt = $pdo->prepare($finalQuery);
        $usedParams = extractNamedParamsFromQuery($finalQuery);
        $filteredParams = array_intersect_key($params, array_flip($usedParams));
        $stmt->execute($filteredParams);
        $queryResults = $stmt->fetchAll(PDO::FETCH_ASSOC);




    // Assign a default item_type if missing (for safety)
    foreach ($queryResults as &$row) {
        if (!isset($row['item_type'])) {
            $row['item_type'] = 'unknown';
        }
    }
    foreach ($itemResults as &$item) {
        if (!isset($item['item_type'])) {
            $item['item_type'] = 'unknown';
        }
    }



    // Step 5: Respond
   echo json_encode([
    'success' => true,
   'meta' => [
        'meta_id' => $queryRow['query_id'],
        'display' => $queryRow['display'] ?? $queryRow['name'] ?? '',
        'description' => $queryRow['description'] ?? '',
        'item_type' => $queryRow['item_type'] ?? '',
        'type' => $queryRow['type'] ?? '',
        'on_click_query' => $queryRow['on_click_query'] ?? '',
        'has_header' => $queryRow['has_header'] ?? '',
        'header_title' => $queryRow['header_title'] ?? '',
        'header_subtitle' => $queryRow['header_subtitle'] ?? '',
        'header_content' => $queryRow['header_content'] ?? '',
        'params' => $params  // ✅ Echo back input params for use in frontend
    ],
    'results' => $queryResults,
    'inserts' => $itemResults,
    'rowCount' => count($queryResults),
    'insertCount' => count($itemResults)
]);

} catch (PDOException $e) {
    logError("SQL error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (Exception $e) {
    logError("General error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unexpected error']);
}
