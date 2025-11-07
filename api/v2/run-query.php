<?php
declare(strict_types=1);

// CORS preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=UTF-8');
@ini_set('display_errors','0');
@ini_set('log_errors','1');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php'; // $pdo
require_once __DIR__ . '/../functions/filter-helpers.php'; // buildWhereClauseFromFilters()

// ---- tiny helpers -----------------------------------------------------------
$logFile = dirname(__DIR__, 1) . '/run-query-error.log';
$log = static function(string $msg) use ($logFile): void {
  @file_put_contents($logFile, '['.date('Y-m-d H:i:s')."] $msg\n", FILE_APPEND);
};
$extractNamedParamsFromQuery = static function(string $sql): array {
  preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $m);
  return array_values(array_unique($m[1] ?? []));
};
$jexit = static function(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES);
  exit;
};

/**
 * Remove inner ORDER BY (best-effort) and hoist trailing LIMIT/OFFSET
 * so our outer ORDER BY is authoritative and paging happens after sorting.
 */
function normalizeQueryForOuterOrder(string $sql): array {
  $limit = '';
  // Capture and remove trailing LIMIT ... [OFFSET ...] or LIMIT x,y
  if (preg_match('/\sLIMIT\s+\d+(?:\s*,\s*\d+|\s+OFFSET\s+\d+)?\s*$/i', $sql, $m)) {
    $limit = $m[0];
    $sql   = preg_replace('/\sLIMIT\s+\d+(?:\s*,\s*\d+|\s+OFFSET\s+\d+)?\s*$/i', '', $sql);
  }
  // Remove any trailing ORDER BY ... (best effort; we control stored SQL)
  $sql = preg_replace('/\sORDER\s+BY\s+.+$/is', '', $sql);
  return [$sql, $limit];
}

// ---- body -------------------------------------------------------------------
try {
  $raw  = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];

  $queryId = isset($data['query_id']) ? (int)$data['query_id'] : 0;
  if ($queryId <= 0) $jexit(400, ['error' => 'Missing or invalid query_id']);

  // 1) Fetch items for this query AND global inserts (17)
  $stmtItems = $pdo->prepare("
    SELECT *
      FROM items
     WHERE (query_id = :qid OR query_id = 17)
       AND is_active = 1
     ORDER BY
       CASE WHEN query_id = 17 THEN 1 ELSE 0 END,
       COALESCE(insert_position, 999999) ASC,
       id ASC
  ");
  $stmtItems->execute([':qid' => $queryId]);
  $itemResults = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

  // 2) Fetch the stored SQL row
  $stmtQuery = $pdo->prepare("SELECT * FROM sql_queries WHERE query_id = ?");
  $stmtQuery->execute([$queryId]);
  $queryRow = $stmtQuery->fetch(PDO::FETCH_ASSOC);

  if (!$queryRow || ($queryRow['query'] ?? '') === '') {
    $jexit(404, ['error' => 'Query not found or missing SQL']);
  }

  // 3) Build final SQL (+ filters)
  $queryText = (string)$queryRow['query'];

  $searchFilters = is_array($data['searchFilters'] ?? null) ? $data['searchFilters'] : [];
  [$finalQuery, $namedFromFilters] = buildWhereClauseFromFilters($searchFilters, $queryText);

  // Gather params BEFORE deciding group mode
  $paramsRaw = $data['params'] ?? [];
  $params    = is_array($paramsRaw) ? $paramsRaw : [];
  $params = $namedFromFilters + $params;

  if (!isset($params['group_mode'])) {
    $params['group_mode'] = 'hue'; // default
  }
  $groupMode = ($params['group_mode'] === 'lightness') ? 'lightness' : 'hue';

  // Are we dealing with swatches? (Only swatch queries have hcl_* / light_cat_*)
  $isSwatchQuery = (trim((string)($queryRow['item_type'] ?? '')) === 'swatch');

  // 4) If it's a swatch query, normalize and impose our outer ORDER BY.
  //    Otherwise, leave the stored SQL order alone.
  if ($isSwatchQuery) {
    [$innerSql, $innerLimit] = normalizeQueryForOuterOrder($finalQuery);

    // Wrap the inner query; join cd for Lightness sort if view only exposes light_cat_id
    $finalQuery  = "SELECT q.*,"
                 . " cd.name AS __light_cat_name_outer,"
                 . " cd.sort_order AS __light_cat_order_outer"
                 . " FROM ( $innerSql ) AS q"
                 . " LEFT JOIN category_definitions cd"
                 . "   ON cd.id = q.light_cat_id AND cd.type = 'Lightness' ";

    if ($groupMode === 'lightness') {
      // Lightness mode: group by Light/Medium/Dark then graduate light → dark
      $finalQuery .= " ORDER BY"
                   . "  COALESCE(q.light_cat_order, __light_cat_order_outer, 999),"
                   . "  q.hcl_l DESC,"   // lightest → darkest inside the band
                   . "  q.hcl_c ASC,"    // softer first (tie-breaker)
                   . "  q.hcl_h ASC";    // final tie-breaker
    } else {
      // Hue mode (legacy)
      $finalQuery .= " ORDER BY q.hue_cat_order, q.hcl_h ASC, q.hcl_l DESC";
    }

    if ($innerLimit) $finalQuery .= " $innerLimit";
  }

  // 5) Execute
  $usedNames = $extractNamedParamsFromQuery($finalQuery);
  $binds = array_intersect_key($params, array_flip($usedNames));

  $log("Final query: $finalQuery | Params: " . json_encode($binds));

  $stmt = $pdo->prepare($finalQuery);
  foreach ($binds as $k => $v) {
    $stmt->bindValue(':'.$k, $v, PDO::PARAM_STR);
  }
  $stmt->execute();
  $queryResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // 6) Safety: default item_type if missing
  foreach ($queryResults as &$r) { if (!isset($r['item_type'])) $r['item_type'] = 'unknown'; }
  foreach ($itemResults as &$i)  { if (!isset($i['item_type'])) $i['item_type'] = 'unknown'; }

  // 7) Response
  $jexit(200, [
    'success' => true,
    'meta' => [
      'meta_id'        => $queryRow['query_id'],
      'display'        => $queryRow['display'] ?? ($queryRow['name'] ?? ''),
      'description'    => $queryRow['description'] ?? '',
      'item_type'      => $queryRow['item_type'] ?? '',
      'type'           => $queryRow['type'] ?? '',
      'on_click_query' => $queryRow['on_click_query'] ?? '',
      'has_header'     => $queryRow['has_header'] ?? '',
      'header_title'   => $queryRow['header_title'] ?? '',
      'header_subtitle'=> $queryRow['header_subtitle'] ?? '',
      'header_content' => $queryRow['header_content'] ?? '',
      'params'         => $params, // includes group_mode
    ],
    'results'     => $queryResults,
    'inserts'     => $itemResults,
    'rowCount'    => count($queryResults),
    'insertCount' => count($itemResults),
  ]);

} catch (\PDOException $e) {
  $log('SQL error: '.$e->getMessage());
  $jexit(500, ['error' => 'Database error']);
} catch (\Throwable $e) {
  $log('General error: '.$e->getMessage());
  $jexit(500, ['error' => 'Unexpected error']);
}
