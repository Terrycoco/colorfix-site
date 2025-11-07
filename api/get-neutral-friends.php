<?php
// /api/get-neutral-friends.php
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

const GNF_LOG = __DIR__ . '/logs/get-neutral-friends.log';
function log_event_neutrals($lvl, $msg, $ctx = []) {
  $dir = dirname(GNF_LOG);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @error_log(
    json_encode(['ts'=>date('c'),'lvl'=>$lvl,'msg'=>$msg,'ctx'=>$ctx], JSON_UNESCAPED_SLASHES) . PHP_EOL,
    3,
    GNF_LOG
  );
}

set_error_handler(function($sev, $msg, $file, $line) {
  log_event_neutrals('php_error', $msg, ['file'=>$file,'line'=>$line,'severity'=>$sev]);
  http_response_code(200);
  echo json_encode([]);
  exit;
});
set_exception_handler(function($e) {
  log_event_neutrals('php_exception', $e->getMessage(), ['trace'=>$e->getTraceAsString()]);
  http_response_code(200);
  echo json_encode([]);
  exit;
});

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
$neighborsUsed = [];

// neighbor expansion controls (LOOSER defaults for NEUTRALS)
$body = read_json_body();
$gs   = $_GET;

// Force neutrals to be generous: fixed mode, ΔE up to 3.0, cap 15
$includeNeighbors = (
  (isset($gs['include_neighbors']) && (string)$gs['include_neighbors'] === '1') ||
  (isset($body['include_neighbors']) && (int)$body['include_neighbors'] === 1)
);

// Allow caller to override, but our defaults are loose
$nearCap   = isset($gs['near_cap'])   ? max(1, (int)$gs['near_cap'])   : (isset($body['near_cap'])   ? max(1,(int)$body['near_cap'])   : 15);
$nearMode  = 'fixed'; // hard-set for neutrals so there is no gap-cut
$nearMaxDe = isset($gs['near_max_de']) ? (float)$gs['near_max_de'] : (isset($body['near_max_de']) ? (float)$body['near_max_de'] : 3.0);

if (!$ids) {
  echo json_encode([]);
  exit;
}

// --------- 1) lookup anchors ----------
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

if ($DEBUG) log_event_neutrals('debug', 'anchors resolved', [
  'ids' => $ids,
  'anchor_clusters' => $anchorClusters,
  'anchor_hex' => $anchorHex,
  'N' => count($anchorClusters),
]);

// --------- 2) neighbor expansion + friends ----------
$groupFriends = [];
$seenAnchorClusters = [];

foreach ($anchors as $a) {
  $anchorColorId = (int)($a['id'] ?? 0);
  $cid           = (int)($a['cluster_id'] ?? 0);
  if ($cid <= 0) continue;
  if (isset($seenAnchorClusters[$cid])) continue;
  $seenAnchorClusters[$cid] = true;

  $group = [$cid];
  if ($anchorColorId > 0 && !isset($neighborsUsed[$anchorColorId])) {
    $neighborsUsed[$anchorColorId] = [];
  }

  if ($includeNeighbors && $anchorColorId > 0) {
    try {
      // Neutrals: fixed + looser ΔE + higher cap
      $near = cf_closest_matches($pdo, [
        'seed_color_id' => $anchorColorId,
        'cap'           => $nearCap,
        'mode'          => 'fixed',      // important: no gap-cut
        'max_de'        => $nearMaxDe,   // generous ΔE ~ 3.0
      ]);

      // fetch HCL lightness for text color decision
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

      foreach ($near as $n) {
        $ncid = (int)($n['cluster_id'] ?? 0);
        if ($ncid <= 0) continue;

        $nColorId = (int)($n['color_id'] ?? 0);
        $L  = $Lmap[$nColorId] ?? null;
        $fg = ($L !== null && $L >= 70.0) ? '#000' : '#fff';

        $group[] = $ncid; // union inside this anchor-group
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
      // swallow; stick with just the anchor cluster
    }
  }

  // friends of this group (union within)
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

// intersect across anchors (keeps relevance tight once palette grows)
if (count($groupFriends) === 1)       $friendClusters = $groupFriends[0];
elseif (count($groupFriends) > 1)     $friendClusters = array_values(array_intersect(...$groupFriends));
else                                  $friendClusters = [];

// drop anchors' own clusters
$friendClusters = array_values(array_diff($friendClusters, $anchorClusters));
if (!$friendClusters) { echo json_encode([]); exit; }

// --------- 3) expand to neutral swatches ----------
$inClusters   = implode(',', array_fill(0, count($friendClusters), '?'));
$notInHex     = $anchorHex ? "AND UPPER(sv.hex6) NOT IN (" . implode(',', array_fill(0, count($anchorHex), '?')) . ")" : "";
$notInCluster = $anchorClusters ? "AND ch.cluster_id NOT IN (" . implode(',', array_fill(0, count($anchorClusters), '?')) . ")" : "";
$brandSql     = '';

$params = $friendClusters;
if ($anchorHex)      $params = array_merge($params, $anchorHex);
if ($anchorClusters) $params = array_merge($params, $anchorClusters);
if ($brands) {
  $brandSql = " AND sv.brand IN (" . implode(',', array_fill(0, count($brands), '?')) . ") ";
  $params   = array_merge($params, $brands);
}

$sql = "SELECT
          sv.*,
          sv.neutral_cats  AS group_header,
          CASE
            WHEN sv.neutral_cats LIKE '%Black%' THEN 1
            WHEN sv.neutral_cats LIKE '%White%' THEN 2
            WHEN sv.neutral_cats LIKE '%Gray%'  THEN 3
            WHEN sv.neutral_cats LIKE '%Beige%' THEN 4
            ELSE 99
          END AS group_order
        FROM cluster_hex ch
        JOIN swatch_view sv ON sv.hex6 = ch.hex6
        WHERE ch.cluster_id IN ($inClusters)
          $notInHex
          $notInCluster
          AND sv.neutral_cats IS NOT NULL AND sv.neutral_cats <> ''
          $brandSql
        ORDER BY group_order ASC, sv.hcl_c DESC, sv.hcl_l ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --------- output ----------
$payload = [ 'items' => array_values($rows) ];
if ($includeNeighbors) {
  $nz = [];
  foreach ($neighborsUsed as $anchorId => $arr) {
    if (!empty($arr)) $nz[(string)$anchorId] = $arr;
  }
  if (!empty($nz)) $payload['neighbors_used'] = $nz;
}
echo json_encode($payload, JSON_UNESCAPED_SLASHES);
