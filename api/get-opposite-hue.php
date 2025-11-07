<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

set_error_handler(function($severity, $message, $file, $line) {
  http_response_code(500);
  echo json_encode(['error' => "PHP error: $message at $file:$line"]);
  exit;
});
set_exception_handler(function($e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
  exit;
});
@error_log('tol='.($_GET['tol'] ?? 'null'));

require_once 'db.php'; // defines $pdo (PDO)

// ---- ids (GET or JSON POST) ----
$ids = [];
if (isset($_GET['ids'])) {
  $raw = $_GET['ids'];
  if (is_array($raw)) {
    foreach ($raw as $v) {
      $ids = array_merge($ids, preg_split('/\s*,\s*/', (string)$v, -1, PREG_SPLIT_NO_EMPTY));
    }
  } else {
    $ids = preg_split('/\s*,\s*/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
  }
} else {
  $json = file_get_contents('php://input');
  if ($json) {
    $data = json_decode($json, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($data['ids']) && is_array($data['ids'])) {
      $ids = $data['ids'];
    }
  }
}

// tolerance (degrees)
$tol = isset($_GET['tol']) ? max(0, (int)$_GET['tol']) : 0;

// sanitize ids: ints > 0, de-dupe, preserve order
$seen = [];
$clean = [];
foreach ($ids as $v) {
  $n = (int)$v;
  if ($n > 0 && !isset($seen[$n])) {
    $seen[$n] = true;
    $clean[] = $n;
  }
}
$ids = $clean;

if (count($ids) === 0) {
  echo json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$fieldList    = implode(',', $ids);

// ---- optional brand filter (GET or JSON POST) ----
$brandCodes = [];
if (isset($_GET['brands'])) {
  $brandCodes = explode(',', (string)$_GET['brands']);
} else {
  if (!isset($data)) {
    $json = $json ?? file_get_contents('php://input');
    $data = $json ? json_decode($json, true) : null;
  }
  if (is_array($data) && isset($data['brands'])) {
    $brandCodes = is_array($data['brands']) ? $data['brands'] : explode(',', (string)$data['brands']);
  }
}
$brandCodes = array_values(array_unique(array_filter(array_map(
  fn($s) => strtolower(trim((string)$s)),
  $brandCodes
))));

$brandClause = '';
$brandParams = [];
if (!empty($brandCodes)) {
  $phB = implode(',', array_fill(0, count($brandCodes), '?'));
  $brandClause = " AND LOWER(sv.brand) IN ($phB)";
  $brandParams = $brandCodes;
}

// ---- join condition: opposite of seed hue (± tol) ----
$target = 'MOD(bc.hue_r + 180, 360)';
$joinCond = $tol > 0
  ? "ABS(MOD((sv.hcl_h - $target + 540), 360) - 180) <= ?"
  : "MOD(ROUND(sv.hcl_h), 360) = $target";

// ---- query ----
$sql = "
  SELECT
    sv.*,
    CONCAT('Opposites for ', bc.header_name) AS group_header,
    bc.group_order AS group_order
  FROM (
    SELECT
      hue_r,
      MIN(FIELD(id, $fieldList)) AS group_order,
      SUBSTRING_INDEX(GROUP_CONCAT(name ORDER BY FIELD(id, $fieldList)), ',', 1) AS header_name
    FROM (
      SELECT id, name, MOD(ROUND(hcl_h), 360) AS hue_r
      FROM swatch_view
      WHERE id IN ($placeholders)
    ) AS seeds
    GROUP BY hue_r
  ) AS bc
  JOIN swatch_view AS sv
    ON $joinCond
   AND sv.id NOT IN ($fieldList)
   $brandClause
  ORDER BY bc.group_order, sv.hcl_c DESC, sv.hcl_l ASC
";

$stmt = $pdo->prepare($sql);

// params order = seed ids, (tol if used), brand codes
$params = $ids;
if ($tol > 0) { $params[] = $tol; }
$params = array_merge($params, $brandParams);

$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
