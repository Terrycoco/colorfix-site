<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0'); ini_set('log_errors','1');

try {
  require_once __DIR__ . '/db.php';

  // --- generator function (no curl) ---
  $fnPath = __DIR__ . '/functions/generateTierAPalettes.php';
  if (file_exists($fnPath)) require_once $fnPath;
  if (!function_exists('generateTierAPalettes')) {
    throw new RuntimeException('generateTierAPalettes() not found.');
  }

  // ====== INPUTS ======
  $membersCsv    = trim((string)($_GET['members_all'] ?? ''));
  $membersAllArr = array_values(array_unique(array_filter(array_map('intval', explode(',', $membersCsv)), fn($n)=>$n>0)));
  $member        = isset($_GET['member']) ? (int)$_GET['member'] : 0;

  if (!$membersAllArr && $member <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'missing anchors (?members_all or ?member)'], JSON_UNESCAPED_SLASHES);
    exit;
  }

  // anchors_map = "12:9031,45:8123"
$anchorsMapRaw = trim((string)($_GET['anchors_map'] ?? ''));
$anchorsMap = [];
if ($anchorsMapRaw !== '') {
  foreach (explode(',', $anchorsMapRaw) as $pair) {
    $pair = trim($pair);
    if ($pair === '') continue;
    if (strpos($pair, ':') !== false) {
      [$cid, $id] = array_map('trim', explode(':', $pair, 2));
      $cid = (int)$cid; $id = (int)$id;
      if ($cid > 0 && $id > 0) $anchorsMap[$cid] = $id;
    }
  }
}


  // sizes (optional; if absent => all sizes)
  $szRaw = isset($_GET['sizes']) ? trim((string)$_GET['sizes']) : '';
  $sizesArr = [];
  if ($szRaw !== '' && strtolower($szRaw) !== 'all') {
    $sizesArr = array_values(array_unique(array_filter(
      array_map('intval', explode(',', $szRaw)),
      fn($n)=>$n>=3 && $n<=12
    )));
  }

  $sort   = $_GET['sort']   ?? 'size_asc';
  $orderBySql = $sort==='size_desc' ? "p.size DESC, p.id DESC" : ($sort==='random' ? "RAND()" : "p.size, p.id");
  $limit  = isset($_GET['limit'])  ? max(1, min(200, (int)$_GET['limit']))   : 100;
  $offset = isset($_GET['offset']) ? max(0,            (int)$_GET['offset']) : 0;
  $tier   = $_GET['tier']   ?? 'A';
  $status = $_GET['status'] ?? 'active';
  $with   = trim((string)($_GET['with'] ?? ''));
  $withAll= trim((string)($_GET['with_all'] ?? ''));

  // generator knobs
  $maxK   = isset($_GET['max_k']) ? max(3, min(12, (int)$_GET['max_k'])) : 6;
  $budget = isset($_GET['time_budget_ms']) ? max(500, min(30000, (int)$_GET['time_budget_ms'])) : 4000;
  $reset  = isset($_GET['reset']) ? (int)$_GET['reset'] !== 0 : false;

  // ====== ROUND-ROBIN PIVOT & GENERATE (one anchor per call) ======
  if (!function_exists('get_cf_etag')) {
    function get_cf_etag(PDO $pdo): string {
      try {
        $v = $pdo->query("SELECT `value` FROM meta WHERE `key`='cluster_friends_etag' LIMIT 1")->fetchColumn();
        if (is_string($v) && $v !== '') return $v;
      } catch (\Throwable $e) {}
      $row = $pdo->query("SELECT CONCAT_WS(':', COUNT(*), COALESCE(UNIX_TIMESTAMP(MAX(updated_at)),0)) FROM cluster_friends")->fetchColumn();
      return 'cf:' . $row;
    }
  }
  if (!function_exists('load_ledger_row')) {
    function load_ledger_row(PDO $pdo, int $pivot, string $tierConfig): ?array {
      $stmt = $pdo->prepare("SELECT is_exhausted, graph_etag FROM palette_gen_state WHERE pivot_cluster_id=:p AND tier_config=:tc LIMIT 1");
      $stmt->execute([':p'=>$pivot, ':tc'=>$tierConfig]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      return $row ?: null;
    }
  }
  if (!function_exists('upsert_ledger_min')) {
    function upsert_ledger_min(PDO $pdo, int $pivot, string $tierConfig, string $cfEtag, bool $exhausted): void {
      $stmt = $pdo->prepare("
        INSERT INTO palette_gen_state (pivot_cluster_id, tier_config, is_exhausted, graph_etag, last_full_run_at)
        VALUES (:p,:tc,:ex,:etag,NOW())
        ON DUPLICATE KEY UPDATE is_exhausted=VALUES(is_exhausted), graph_etag=VALUES(graph_etag), last_full_run_at=VALUES(last_full_run_at)
      ");
      $stmt->execute([':p'=>$pivot, ':tc'=>$tierConfig, ':ex'=>$exhausted?1:0, ':etag'=>$cfEtag]);
    }
  }

  $anchors    = $membersAllArr ?: ($member > 0 ? [$member] : []);
  $tierConfig = "A:max_k={$maxK}";
  $cfEtag     = get_cf_etag($pdo);

  $pivot = 0;
  foreach ($anchors as $cid) {
    $row = load_ledger_row($pdo, (int)$cid, $tierConfig);
    $needGen = $reset || !$row || (int)($row['is_exhausted'] ?? 0) === 0 || (($row['graph_etag'] ?? '') !== $cfEtag);
    if ($needGen) { $pivot = (int)$cid; break; }
  }
  if ($pivot === 0 && $anchors) $pivot = (int)$anchors[0];

  $controllerCapMs = isset($_GET['controller_budget_ms']) ? max(500, min(15000, (int)$_GET['controller_budget_ms'])) : 2500;

  if ($pivot > 0) {
    if ($reset) {
      $pdo->prepare("INSERT INTO palette_gen_state (pivot_cluster_id, tier_config, is_exhausted)
                     VALUES (:p,:tc,0) ON DUPLICATE KEY UPDATE is_exhausted=0")
          ->execute([':p'=>$pivot, ':tc'=>$tierConfig]);
    }
    $started = microtime(true);
    $exhausted = false;
    do {
      $summary   = generateTierAPalettes($pdo, $pivot, $maxK, $budget);
      $added     = (int)($summary['new_palettes'] ?? 0);
      $exhausted = isset($summary['exhausted']) ? (bool)$summary['exhausted']
                 : (isset($summary['is_exhausted']) ? (bool)$summary['is_exhausted']
                 : ($added === 0));
    } while (!$exhausted && ((microtime(true) - $started) * 1000) < $controllerCapMs);

    upsert_ledger_min($pdo, $pivot, $tierConfig, $cfEtag, $exhausted);
  }

  // ====== LIST ======

  // anchors first in GROUP_CONCAT (inline sanitized CSV to avoid HY093)
  $anchorsCsv = '';
  if (!empty($membersAllArr))      $anchorsCsv = implode(',', array_map('intval', $membersAllArr));
  elseif (!empty($member))         $anchorsCsv = (string)((int)$member);

  $orderExpr = "pm.order_hint, pm.member_cluster_id";
  if ($anchorsCsv !== '') {
    $orderExpr = "(FIND_IN_SET(pm.member_cluster_id, '{$anchorsCsv}') = 0),
                  NULLIF(FIND_IN_SET(pm.member_cluster_id, '{$anchorsCsv}'),0),
                  pm.order_hint, pm.member_cluster_id";
  }

  $params = [ ':tier'=>$tier, ':status'=>$status, ':limit'=>$limit, ':offset'=>$offset ];

  // optional size filter SQL + binds
  $sizeSql = '';
  if ($sizesArr) {
    $in = [];
    foreach ($sizesArr as $i=>$s){ $ph=":s{$i}"; $in[]=$ph; $params[$ph]=$s; }
    $inList = implode(',', $in);
    $sizeSql = " AND p.size IN ($inList) ";
  }

  // WITH / WITH_ALL filters
  $withSql = '';
  if ($withAll !== '') {
    $needles = array_values(array_filter(array_map('trim', explode(',', $withAll))));
    $sub = []; $j = 0;
    foreach ($needles as $tok) {
      if ($tok === '') continue;
      $t = strtolower($tok);
      if ($t === 'grey') $t = 'gray';
      $isNeutral = in_array($t, ['white','black','gray','greige','beige','brown'], true);
      if (substr($t,-1) !== 's') $t .= 's';
      $ph = ":with{$j}";
      $params[$ph] = '%'.$t.'%';
      if ($isNeutral) {
        $sub[] = "EXISTS (
          SELECT 1 FROM palette_members pmN{$j}
          JOIN clusters clN{$j} ON clN{$j}.id = pmN{$j}.member_cluster_id
          WHERE pmN{$j}.palette_id = p.id
            AND LOWER(COALESCE(clN{$j}.neutral_cats,'')) LIKE {$ph}
        )";
      } else {
        $sub[] = "EXISTS (
          SELECT 1 FROM palette_members pmN{$j}
          JOIN clusters clN{$j} ON clN{$j}.id = pmN{$j}.member_cluster_id
          WHERE pmN{$j}.palette_id = p.id
            AND LOWER(COALESCE(clN{$j}.hue_cats,'')) LIKE {$ph}
        )";
      }
      $j++;
    }
    if ($sub) $withSql = ' AND ' . implode(' AND ', $sub);
  }

  // ALL-of anchors filter
  $anchorSql = '';
  if ($membersAllArr) {
    $phAll = [];
    foreach ($membersAllArr as $i=>$cid) { $k=":ma{$i}"; $phAll[]=$k; $params[$k]=(int)$cid; }
    $params[':need_count'] = count($membersAllArr);
    $inAll = implode(',', $phAll);
    $anchorSql = "
      AND p.id IN (
        SELECT pm2.palette_id
        FROM palette_members pm2
        WHERE pm2.member_cluster_id IN ($inAll)
        GROUP BY pm2.palette_id
        HAVING COUNT(DISTINCT pm2.member_cluster_id) = :need_count
      )
    ";
  } else {
    $params[':m2'] = $member;
    $anchorSql = "AND EXISTS (
      SELECT 1 FROM palette_members x
      WHERE x.palette_id = p.id AND x.member_cluster_id = :m2
    )";
  }

  $sql = "
    SELECT
      p.id, p.size,
      GROUP_CONCAT(
        cl.rep_hex
        ORDER BY {$orderExpr}
        SEPARATOR ','
      ) AS hexes,
      GROUP_CONCAT(
        pm.member_cluster_id
        ORDER BY {$orderExpr}
        SEPARATOR ','
      ) AS member_cluster_ids,
      SUM(CASE WHEN COALESCE(cl.neutral_cats,'') = '' THEN 1 ELSE 0 END) AS chromatic_count,
      SUM(CASE WHEN COALESCE(cl.neutral_cats,'') <> '' THEN 1 ELSE 0 END) AS neutral_count,
      MAX(CASE WHEN cl.neutral_cats LIKE '%White%' THEN 1 ELSE 0 END)     AS has_white,
      MAX(CASE WHEN cl.neutral_cats LIKE '%Black%' THEN 1 ELSE 0 END)     AS has_black
    FROM palettes p
    JOIN palette_members pm ON pm.palette_id = p.id
    JOIN clusters cl        ON cl.id = pm.member_cluster_id
    WHERE p.tier = :tier AND p.status = :status
      {$sizeSql}
      {$anchorSql}
      {$withSql}
    GROUP BY p.id, p.size
    ORDER BY {$orderBySql}
    LIMIT :limit OFFSET :offset
  ";

  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) {
    $stmt->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
  }
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


// --- ENRICH: resolve a concrete swatch per member cluster (anchor override else canonical)

// collect all member cluster_ids across returned palettes (to compute canonicals once)
$allClusterIds = [];
foreach ($rows as $r) {
  $m = isset($r['member_cluster_ids']) ? explode(',', (string)$r['member_cluster_ids']) : [];
  foreach ($m as $cidStr) {
    $cid = (int)$cidStr;
    if ($cid > 0) $allClusterIds[$cid] = true;
  }
}
$allClusterIds = array_keys($allClusterIds);

// fetch canonical swatch per cluster (choose MIN(id) deterministically)
$canonicalByCluster = [];
if ($allClusterIds) {
  // build IN list safely
  $inPh = [];
  $bind = [];
  foreach ($allClusterIds as $i => $cid) {
    $ph = ":c{$i}";
    $inPh[] = $ph;
    $bind[$ph] = (int)$cid;
  }
  $sqlCanon = "
    SELECT sv.*
    FROM swatch_view sv
    JOIN (
      SELECT cluster_id, MIN(id) AS min_id
      FROM swatch_view
      WHERE cluster_id IN (" . implode(',', $inPh) . ")
      GROUP BY cluster_id
    ) m
      ON sv.cluster_id = m.cluster_id AND sv.id = m.min_id
  ";
  $stCanon = $pdo->prepare($sqlCanon);
  foreach ($bind as $k=>$v) $stCanon->bindValue($k, $v, PDO::PARAM_INT);
  $stCanon->execute();
  while ($row = $stCanon->fetch(PDO::FETCH_ASSOC)) {
    $canonicalByCluster[(int)$row['cluster_id']] = $row;
  }
}

// fetch anchor override swatches by their ids (values of anchorsMap)
$anchorSwatchById = [];
if ($anchorsMap) {
  $anchorIds = array_values($anchorsMap);
  $inPh = [];
  $bind = [];
  foreach ($anchorIds as $i => $idv) {
    $ph = ":a{$i}";
    $inPh[] = $ph;
    $bind[$ph] = (int)$idv;
  }
  $sqlAnch = "SELECT * FROM swatch_view WHERE id IN (" . implode(',', $inPh) . ")";
  $stAnch = $pdo->prepare($sqlAnch);
  foreach ($bind as $k=>$v) $stAnch->bindValue($k, $v, PDO::PARAM_INT);
  $stAnch->execute();
  while ($row = $stAnch->fetch(PDO::FETCH_ASSOC)) {
    $anchorSwatchById[(int)$row['id']] = $row;
  }
}

// build members array on each palette row in the original order
// build members array on each palette row in the original order
foreach ($rows as &$r) {
  $members = [];
  $clusters = isset($r['member_cluster_ids']) ? explode(',', (string)$r['member_cluster_ids']) : [];

  foreach ($clusters as $cidStr) {
    $cid = (int)$cidStr; if ($cid <= 0) continue;

    // prefer anchor override if present and valid for this cluster; else canonical
    $chosen = null;
    if (isset($anchorsMap[$cid])) {
      $aid = (int)$anchorsMap[$cid];
      $cand = $anchorSwatchById[$aid] ?? null;
      if ($cand && (int)$cand['cluster_id'] === $cid) $chosen = $cand;
    }
    if (!$chosen) $chosen = $canonicalByCluster[$cid] ?? null;

  if ($chosen) {
  $h6 = '';
  if (!empty($chosen['hex6'])) $h6 = strtoupper((string)$chosen['hex6']);
  elseif (!empty($chosen['hex'])) $h6 = strtoupper(ltrim((string)$chosen['hex'], '#'));

  $members[] = [
    'id'         => (int)$chosen['id'],
    'brand'      => (string)($chosen['brand'] ?? $chosen['brand_code'] ?? ''),
    'name'       => (string)($chosen['name'] ?? $chosen['color_name'] ?? ''),
    'hex6'       => $h6,
    'hex'        => $h6 !== '' ? ('#' . $h6) : '',
    'r'          => isset($chosen['r']) ? (int)$chosen['r'] : null,
    'g'          => isset($chosen['g']) ? (int)$chosen['g'] : null,
    'b'          => isset($chosen['b']) ? (int)$chosen['b'] : null,
    'cluster_id' => (int)$cid,
  ];
} else {
  $members[] = [
    'id'         => null,
    'brand'      => '',
    'name'       => '',
    'hex6'       => '',
    'hex'        => '',
    'r'          => null,
    'g'          => null,
    'b'          => null,
    'cluster_id' => (int)$cid,
  ];
}

  } // ← THIS was missing

  $r['members'] = $members;
}
unset($r);



  echo json_encode([
    'ok'=>true,
    'member'       => $membersAllArr ? null : ($member ?: null),
    'members_all'  => $membersAllArr ?: null,
    'sizes'        => $sizesArr ?: null,
    'with'         => ($with?:null),
    'count'        => count($rows),
    'items'        => $rows
  ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
}
