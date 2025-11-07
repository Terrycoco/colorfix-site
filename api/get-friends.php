<?php
// /api/get-friends.php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php'; // must provide $pdo (PDO::ERRMODE_EXCEPTION)

// pull in cf_closest_matches()
$__closest = __DIR__ . '/functions/closestMatch.php';
if (!@file_exists($__closest)) $__closest = __DIR__ . '/closestMatch.php';
require_once $__closest;





header('Content-Type: application/json; charset=utf-8');
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';

const GF_LOG = __DIR__ . '/logs/get-friends.log';
function log_event_friends($lvl, $msg, $ctx = []) {
  $dir = dirname(GF_LOG);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @error_log(
    json_encode(['ts'=>date('c'),'lvl'=>$lvl,'msg'=>$msg,'ctx'=>$ctx], JSON_UNESCAPED_SLASHES) . PHP_EOL,
    3,
    GF_LOG
  );
}

// ---- JSON-safe error/exception handlers (use our logger) ----
set_error_handler(function($sev, $msg, $file, $line) {
  log_event_friends('php_error', $msg, ['file'=>$file,'line'=>$line,'severity'=>$sev]);
  http_response_code(200);
  echo json_encode([]); // frontend expects array
  exit;
});
set_exception_handler(function($e) {
  log_event_friends('php_exception', $e->getMessage(), ['trace'=>$e->getTraceAsString()]);
  http_response_code(200);
  echo json_encode([]); // frontend expects array
  exit;
});

// First boot log to prove logging works
if ($DEBUG) log_event_friends('boot', 'get-friends.php hit', [
  'method' => $_SERVER['REQUEST_METHOD'] ?? null,
  'uri'    => $_SERVER['REQUEST_URI'] ?? null,
]);

// --------- helpers ----------
function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

function get_param_ids(): array {
  $ids = [];
  if (isset($_GET['ids'])) {
    if (is_array($_GET['ids'])) $ids = $_GET['ids'];
    else $ids = preg_split('/\s*,\s*/', (string)$_GET['ids'], -1, PREG_SPLIT_NO_EMPTY);
  }
  $j = read_json_body();
  if (!$ids && isset($j['ids'])) {
    $ids = is_array($j['ids']) ? $j['ids'] : preg_split('/\s*,\s*/', (string)$j['ids'], -1, PREG_SPLIT_NO_EMPTY);
  }
  $ids = array_values(array_unique(array_map(fn($v)=> (int)$v, $ids)));
  return array_values(array_filter($ids, fn($v)=> $v>0));
}

function get_param_brands(): array {
  $brands = [];
  foreach (['brands','brand'] as $k) {
    if (isset($_GET[$k])) {
      if (is_array($_GET[$k])) $brands = array_merge($brands, $_GET[$k]);
      else $brands = array_merge($brands, preg_split('/\s*,\s*/', (string)$_GET[$k], -1, PREG_SPLIT_NO_EMPTY));
    }
    if (isset($_GET[$k.'[]'])) {
      $brands = array_merge($brands, (array)$_GET[$k.'[]']);
    }
  }
  $j = read_json_body();
  foreach (['brands','brand'] as $k) {
    if (isset($j[$k])) {
      $add = is_array($j[$k]) ? $j[$k] : preg_split('/\s*,\s*/', (string)$j[$k], -1, PREG_SPLIT_NO_EMPTY);
      $brands = array_merge($brands, $add);
    }
  }
  $brands = array_map('trim', $brands);
  $brands = array_values(array_unique(array_filter($brands, fn($v)=> $v!=='')));
  return $brands;
}

// --------- input ----------
$ids = get_param_ids();
$brands = get_param_brands();
$neighborsUsed = []; // anchor_color_id => [ {color_id, cluster_id, name, brand, hex, de}, ... ]


// neighbor expansion controls
$body = read_json_body();
$gs   = $_GET;

$includeNeighbors = (
  (isset($gs['include_neighbors']) && (string)$gs['include_neighbors'] === '1') ||
  (isset($body['include_neighbors']) && (int)$body['include_neighbors'] === 1)
);



$nearCap   = isset($gs['near_cap'])   ? max(1, (int)$gs['near_cap'])   : (isset($body['near_cap'])   ? max(1,(int)$body['near_cap'])   : 10);
$nearMode  = isset($gs['near_mode'])  ? strtolower((string)$gs['near_mode']) : (isset($body['near_mode']) ? strtolower((string)$body['near_mode']) : 'adaptive');

$nearMode  = ($nearMode === 'adaptive') ? 'adaptive' : 'fixed';
$nearMaxDe = isset($gs['near_max_de']) ? (float)$gs['near_max_de'] : (isset($body['near_max_de']) ? (float)$body['near_max_de'] : 3.0);





if (!$ids) {
  echo json_encode([]); // consistent empty
  exit;
}

// --------- 1) lookup anchor hex6 ----------
$sql = "SELECT c.id, c.hex6, COALESCE(c.cluster_id, ch.cluster_id) AS cluster_id
        FROM colors c
        LEFT JOIN cluster_hex ch ON ch.hex6 = c.hex6
        WHERE c.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
$stmt = $pdo->prepare($sql);
$stmt->execute($ids);
$anchors = $stmt->fetchAll(PDO::FETCH_ASSOC);

$anchorHex = [];
$anchorClusters = [];
foreach ($anchors as $a) {
  if (!empty($a['hex6'])) $anchorHex[] = strtoupper($a['hex6']);
  if (!empty($a['cluster_id'])) $anchorClusters[] = (int)$a['cluster_id'];
}
$anchorHex = array_values(array_unique($anchorHex));
$anchorClusters = array_values(array_unique($anchorClusters));

if ($DEBUG) log_event_friends('debug', 'anchors resolved', [
  'ids'             => $ids,
  'anchor_clusters' => $anchorClusters,
  'anchor_hex'      => $anchorHex,
  'N'               => count($anchorClusters),
]);


// Identify exactly which anchors are missing a cluster_id
$missing = [];
foreach ($anchors as $a) {
  $cid = $a['cluster_id'] ?? null;
  if ($cid === null || $cid === '' ) {
    $missing[] = [
      'color_id' => (int)$a['id'],
      'hex6'     => strtoupper((string)($a['hex6'] ?? '')),
      'reason'   => 'cluster_id is NULL (colors.cluster_id and cluster_hex.cluster_id both missing)'
    ];
  }
}

if ($missing) {
  log_event_friends('warn', 'Anchor(s) missing cluster_id', [
    'anchors_missing' => $missing,
    'incoming_ids'    => $ids
  ]);
  echo json_encode([]); // keep frontend contract
  exit;
}

/* --------- 3) compute friend clusters with optional neighbors (AND across anchors, OR within each anchor-group) --------- */

$groupFriends = [];                 // arrays to intersect later
$seenAnchorClusters = [];           // avoid processing same cluster twice

foreach ($anchors as $a) {
  $anchorColorId = (int)($a['id'] ?? 0);
  $cid           = (int)($a['cluster_id'] ?? 0);
  if ($cid <= 0) continue;

  // skip duplicate cluster groups (if multiple palette colors share one cluster)
  if (isset($seenAnchorClusters[$cid])) continue;
  $seenAnchorClusters[$cid] = true;

  // start group with the anchor's own cluster
  $group = [$cid];

  // ensure metadata bucket exists for this anchor color id (for addendum)
  if ($anchorColorId > 0 && !isset($neighborsUsed[$anchorColorId])) {
    $neighborsUsed[$anchorColorId] = [];
  }

  // expand with nearest neighbors (by LAB), record for addendum
if ($includeNeighbors && $anchorColorId > 0) {
  try {
    $near = cf_closest_matches($pdo, [
      'seed_color_id' => $anchorColorId,
      'cap'           => $nearCap,
      'mode'          => $nearMode,   // 'adaptive' or 'fixed'
      'max_de'        => $nearMaxDe,  // for fixed mode
    ]);

    // --- batch fetch HCL lightness for neighbor color_ids
    $nearIds = [];
    foreach ($near as $n) {
      $cid2 = (int)($n['color_id'] ?? 0);
      if ($cid2 > 0) $nearIds[$cid2] = true;
    }
    $Lmap = [];
    if (!empty($nearIds)) {
      $ph  = implode(',', array_fill(0, count($nearIds), '?'));
      $stL = $pdo->prepare("SELECT id, hcl_l FROM swatch_view WHERE id IN ($ph)");
      $stL->execute(array_keys($nearIds));
      while ($r = $stL->fetch(PDO::FETCH_ASSOC)) {
        $Lmap[(int)$r['id']] = is_numeric($r['hcl_l']) ? (float)$r['hcl_l'] : null;
      }
    }

    // --- record neighbors with hcl_l + fg; expand group by neighbor clusters
    foreach ($near as $n) {
      $ncid = (int)($n['cluster_id'] ?? 0);
      if ($ncid <= 0) continue;

      $nColorId = (int)($n['color_id'] ?? 0);
      $L  = $Lmap[$nColorId] ?? null;
      // light gets black text
      $fg = ($L !== null && $L >= 70.0) ? '#000' : '#fff';

      $group[] = $ncid;
      $neighborsUsed[$anchorColorId][] = [
        'color_id'   => $nColorId,
        'cluster_id' => $ncid,
        'name'       => (string)($n['name'] ?? ''),
        'brand'      => (string)($n['brand'] ?? ''),
        'hex'        => (string)($n['rep_hex'] ?? ($n['hex'] ?? '')),
        'hcl_l'      => $L,
        'fg'         => $fg,
        'de'         => isset($n['delta_e2000']) ? (float)$n['delta_e2000'] : null,
      ];
    }
  } catch (Throwable $e) {
    // optional: log_event_friends('warn', 'closest_matches failed', ['anchor_color_id'=>$anchorColorId, 'err'=>$e->getMessage()]);
  }
}

  // friends of this group (union inside)
  $inG  = implode(',', array_fill(0, count($group), '?'));
  $sqlG = "SELECT friends AS friend_cluster_id
           FROM cluster_friends_union
           WHERE cluster_key IN ($inG)
           GROUP BY friend_cluster_id";
  $stG  = $pdo->prepare($sqlG);
  $stG->execute($group);
  $fset = array_map(fn($r)=> (int)$r['friend_cluster_id'], $stG->fetchAll(PDO::FETCH_ASSOC));

  $groupFriends[] = $fset;
}

// intersect across all anchor-groups
if (count($groupFriends) === 1) {
  $friendClusters = $groupFriends[0];
} elseif (count($groupFriends) > 1) {
  $friendClusters = array_values(array_intersect(...$groupFriends));
} else {
  $friendClusters = [];
}

/* --- PRUNE: drop anchors' own clusters (twins) --- */
$friendClusters = array_values(array_diff($friendClusters, $anchorClusters));
if (!$friendClusters) {
  echo json_encode([]);
  exit;
}




// --------- 4) expand clusters -> swatches; 5) brand filter; 6) exclude neutrals; exclude anchors ----------
$inClusters   = implode(',', array_fill(0, count($friendClusters), '?'));
$notInHex     = $anchorHex ? "AND UPPER(sv.hex6) NOT IN (" . implode(',', array_fill(0, count($anchorHex), '?')) . ")" : "";
$notInCluster = $anchorClusters ? "AND ch.cluster_id NOT IN (" . implode(',', array_fill(0, count($anchorClusters), '?')) . ")" : "";
$brandSql     = '';

$params = $friendClusters;
if ($anchorHex)      $params = array_merge($params, $anchorHex);       // for $notInHex
if ($anchorClusters) $params = array_merge($params, $anchorClusters);  // for $notInCluster
if ($brands) {
  $brandSql = " AND sv.brand IN (" . implode(',', array_fill(0, count($brands), '?')) . ") ";
  $params   = array_merge($params, $brands);
}

$sql = "SELECT
          sv.*,
          /* friends version */  sv.hue_cats       AS group_header,
          /* friends version */  sv.hue_cat_order  AS group_order
          /* neutrals version */ /* sv.neutral_cats AS group_header, ... your CASE ... AS group_order */
        FROM cluster_hex ch
        JOIN swatch_view sv ON sv.hex6 = ch.hex6
        WHERE ch.cluster_id IN ($inClusters)
          $notInHex
          $notInCluster
          /* friends version */  AND (sv.neutral_cats IS NULL OR sv.neutral_cats = '')
          /* neutrals version */ /* AND sv.neutral_cats IS NOT NULL AND sv.neutral_cats <> '' */
          $brandSql
          ORDER BY group_order ASC, sv.hcl_c DESC, sv.hcl_l ASC
          
          ";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


if (!$rows) {
  log_event_friends('info', 'final select yielded 0 rows', [
    'friendClusters_count' => count($friendClusters),
    'anchor_clusters'      => $anchorClusters,
    'excluded_hex_count'   => count($anchorHex),
    'brand_filters'        => $brands,
  ]);
}

// --------- output ----------
$payload = [
  'items' => array_values($rows),
];

// only include neighbors_used if the checkbox was on AND we actually found some
if ($includeNeighbors) {
  // prune empties so frontend doesn't render empty lines
  $nz = [];
  foreach ($neighborsUsed as $anchorId => $arr) {
    if (!empty($arr)) $nz[(string)$anchorId] = $arr;
  }
  if (!empty($nz)) $payload['neighbors_used'] = $nz;
}

if ($DEBUG) {
  log_event_friends('debug', 'neighbors_used summary', [
    'include' => $includeNeighbors ? 1 : 0,
    'keys'    => array_keys($neighborsUsed),
    'counts'  => array_map('count', $neighborsUsed),
  ]);
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES);

