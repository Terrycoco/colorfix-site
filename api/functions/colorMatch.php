<?php
declare(strict_types=1);

require_once __DIR__ . '/deltaE2000.php';

// put once near the top
function ensure_dir(string $path): void {
  if (!is_dir($path)) { ensure_dir($path, 0775, true); }
}

/**
 * /api/functions/color_match.php
 *
 * Helpers to:
 *  - Resolve a source swatch (by id, brand+name, or hex)
 *  - Fetch twins (same cluster across brands)
 *  - Find nearest colors by ΔE (Lab)
 *  - Choose the best pick per brand (twin if exists, else nearest)
 *
 * Tables/views:
 *   - swatch_enriched (preferred): id, brand, name, hex6, cluster_id, lab_l, lab_a, lab_b, ...
 *   - colors: id, cluster_id, lab_l, lab_a, lab_b, ...
 */

if (!function_exists('logj')) {
  function logj(string $msg, array $ctx = []): void {
    try {
      $dir = dirname(__DIR__) . '/logs';
      if (!is_dir($dir)) ensure_dir($dir, 0775, true);
      $file = $dir . '/color-match-' . date('Y-m-d') . '.log';
      @file_put_contents($file, json_encode(['ts'=>date('c'),'msg'=>$msg,'ctx'=>$ctx], JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    } catch (\Throwable $e) { /* ignore */ }
  }
}

$SWATCH = 'swatch_enriched';

/** Resolve a source swatch using: id OR (brand+name) OR hex */
function cm_resolve_source(PDO $pdo, array $opts) : ?array {
  global $SWATCH;
  $sourceId  = isset($opts['source_id'])  ? (int)$opts['source_id'] : 0;
  $sourceHex = isset($opts['source_hex']) ? strtoupper(trim((string)$opts['source_hex'])) : '';
  $brand     = isset($opts['brand'])      ? trim((string)$opts['brand']) : '';
  $name      = isset($opts['name'])       ? trim((string)$opts['name'])  : '';
  $nameMode  = isset($opts['name_mode'])  ? strtolower((string)$opts['name_mode']) : 'exact';

  if ($sourceId > 0) {
    $sql = "
      SELECT sv.id AS color_id, sv.brand, sv.name, sv.hex6,
             sv.cluster_id, sv.lab_l, sv.lab_a, sv.lab_b
      FROM {$SWATCH} sv
      WHERE sv.id = :id
        AND COALESCE(LOWER(sv.brand),'') <> 'true'
      LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id'=>$sourceId]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) return $row;
  }

  if ($sourceHex !== '') {
    $sql = "
      SELECT sv.id AS color_id, sv.brand, sv.name, sv.hex6,
             sv.cluster_id, sv.lab_l, sv.lab_a, sv.lab_b
      FROM {$SWATCH} sv
      WHERE sv.hex6 = :hex
        AND COALESCE(LOWER(sv.brand),'') <> 'true'
      ORDER BY sv.brand, sv.name
      LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':hex'=>$sourceHex]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) return $row;
  }

  if ($brand !== '' && $name !== '') {
    if ($nameMode === 'prefix') {
      $sql = "
        SELECT sv.id AS color_id, sv.brand, sv.name, sv.hex6,
               sv.cluster_id, sv.lab_l, sv.lab_a, sv.lab_b
        FROM {$SWATCH} sv
        WHERE LOWER(sv.brand) = LOWER(:brand)
          AND LOWER(sv.name)  LIKE CONCAT(LOWER(:name), '%')
          AND COALESCE(LOWER(sv.brand),'') <> 'true'
          AND sv.is_stain = 0
        ORDER BY sv.name, sv.id
        LIMIT 1
      ";
    } else {
      $sql = "
        SELECT sv.id AS color_id, sv.brand, sv.name, sv.hex6,
               sv.cluster_id, sv.lab_l, sv.lab_a, sv.lab_b
        FROM {$SWATCH} sv
        WHERE LOWER(sv.brand) = LOWER(:brand)
          AND LOWER(sv.name)  = LOWER(:name)
          AND COALESCE(LOWER(sv.brand),'') <> 'true'
          AND sv.is_stain = 0
        ORDER BY sv.id
        LIMIT 1
      ";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':brand'=>$brand, ':name'=>$name]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) return $row;
  }

  return null;
}

/** All twins (same cluster across brands) */
function cm_twins(PDO $pdo, int $clusterId): array {
  global $SWATCH;
  $sql = "
  SELECT sv.id AS color_id, sv.brand, sv.name, sv.hex6
  FROM {$SWATCH} sv
  WHERE sv.cluster_id = :cid
    AND COALESCE(LOWER(sv.brand),'') <> 'true' AND sv.is_stain = 0
  ORDER BY (sv.hex6 = (SELECT rep_hex FROM clusters WHERE id = sv.cluster_id)) DESC,
           sv.brand, sv.name
";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':cid'=>$clusterId]);
  $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
  return ['count'=>count($items), 'items'=>$items];
}

/** Nearest colors in a target brand by ΔE (Lab). Include twin first if present. */
function cm_nearest_in_brand(PDO $pdo, int $clusterId, float $L0, float $a0, float $b0, string $targetBrand, int $limit = 12): array {
  global $SWATCH;
  if (!function_exists('deltaE2000')) {
    throw new \RuntimeException('deltaE2000 helper not loaded (require deltae.php)');
  }

  $limit = max(1, min(50, $limit));

  // --- Twin-first (unchanged) ---
  $sqlTw = "
    SELECT sv.id AS color_id, sv.brand, sv.name, sv.hex6
    FROM {$SWATCH} sv
    WHERE sv.cluster_id = :cid
      AND LOWER(sv.brand) = LOWER(:brand)
      AND COALESCE(LOWER(sv.brand),'') <> 'true'
      AND sv.is_stain = 0
    ORDER BY (sv.hex6 = (SELECT rep_hex FROM clusters WHERE id = sv.cluster_id)) DESC, sv.name
    LIMIT 1
  ";
  $stmtTw = $pdo->prepare($sqlTw);
  $stmtTw->execute([':cid'=>$clusterId, ':brand'=>$targetBrand]);
  $twin = $stmtTw->fetch(PDO::FETCH_ASSOC);

  // --- Prefetch pool by cheap ΔE76 ---
  $prefetchLimit = 400;       // widen if needed
  $sqlN = "
    SELECT sv.id AS color_id, sv.brand, sv.name, sv.hex6,
           sv.lab_l, sv.lab_a, sv.lab_b,
           ( POW(sv.lab_l - :L0, 2)
           + POW(sv.lab_a - :a0, 2)
           + POW(sv.lab_b - :b0, 2) ) AS de2_76
    FROM {$SWATCH} sv
    WHERE LOWER(sv.brand) = LOWER(:brand)
      AND COALESCE(LOWER(sv.brand),'') <> 'true'
      AND sv.lab_l IS NOT NULL AND sv.lab_a IS NOT NULL AND sv.lab_b IS NOT NULL
      AND sv.is_stain = 0
    ORDER BY de2_76 ASC, sv.name
    LIMIT :lim
  ";
  $stmtN = $pdo->prepare($sqlN);
  $stmtN->bindValue(':L0', $L0);
  $stmtN->bindValue(':a0', $a0);
  $stmtN->bindValue(':b0', $b0);
  $stmtN->bindValue(':brand', $targetBrand);
  $stmtN->bindValue(':lim', $prefetchLimit, PDO::PARAM_INT);
  $stmtN->execute();
  $cands = $stmtN->fetchAll(PDO::FETCH_ASSOC);

  // --- Compute raw scores ---
  foreach ($cands as &$r) {
    $L = (float)$r['lab_l']; $a = (float)$r['lab_a']; $b = (float)$r['lab_b'];
    $r['de2000_raw'] = deltaE2000($L0, $a0, $b0, $L, $a, $b);
    $r['de76_raw']   = sqrt((float)$r['de2_76']);
  }
  unset($r);

  // --- Sort by ΔE2000 (tie: ΔE76, then name) ---
  usort($cands, function($x, $y) {
    if ($x['de2000_raw'] == $y['de2000_raw']) {
      if ($x['de76_raw'] == $y['de76_raw']) return strcmp($x['name'], $y['name']);
      return ($x['de76_raw'] < $y['de76_raw']) ? -1 : 1;
    }
    return ($x['de2000_raw'] < $y['de2000_raw']) ? -1 : 1;
  });

  // --- Optional debug (no filesystem): add headers + payload when ?cf_debug=1 ---
  $debugOn = isset($_GET['cf_debug']) && $_GET['cf_debug'] === '1';
  if ($debugOn) {
    @header('X-CF-Metric: DE2000');
    @header('X-CF-Anchor: ' . sprintf('L=%.2f a=%.2f b=%.2f', $L0,$a0,$b0));
  }

  // --- Build output ---
  $nearest = [];
  if ($twin) $nearest[] = $twin + ['delta_e'=>0.0, 'delta_e2000'=>0.0, 'is_twin'=>true];

  foreach ($cands as $r) {
    if ($twin && (int)$r['color_id'] === (int)$twin['color_id']) continue;
    $nearest[] = [
      'color_id'     => (int)$r['color_id'],
      'brand'        => $r['brand'],
      'name'         => $r['name'],
      'hex6'         => $r['hex6'],
      'delta_e'      => round($r['de76_raw'], 2),      // legacy ΔE76 (for display)
      'delta_e2000'  => round($r['de2000_raw'], 2),    // actual ranking
      'is_twin'      => false
    ];
    if (count($nearest) >= $limit + ($twin ? 1 : 0)) break;
  }

  $resp = [
    'brand'  => $targetBrand,
    'metric' => 'DE2000',
    'items'  => $nearest
  ];

  if ($debugOn) {
    $resp['debug_top'] = array_map(function($z){
      return [
        'id'     => $z['color_id'] ?? null,
        'name'   => $z['name'] ?? null,
        'de76'   => isset($z['de76_raw']) ? round($z['de76_raw'], 4) : null,
        'de2000' => isset($z['de2000_raw']) ? round($z['de2000_raw'], 4) : null,
      ];
    }, array_slice($cands, 0, 6));
  }

  return $resp;
}



/** Best pick per brand: twin if exists, else nearest (excludes brand 'true') */
function cm_best_per_brand_all(PDO $pdo, int $clusterId, float $L0, float $a0, float $b0, ?array $brands = null): array {
  global $SWATCH;

  if ($brands === null) {
    $rows = $pdo->query("
      SELECT DISTINCT brand
      FROM {$SWATCH}
      WHERE brand IS NOT NULL AND brand <> '' AND LOWER(brand) <> 'true'
        AND is_stain = 0
      ORDER BY brand
    ")->fetchAll(PDO::FETCH_COLUMN, 0);
    $brands = array_map('strval', $rows ?: []);
  } else {
    $brands = array_values(array_unique(array_filter(
      array_map('trim', $brands),
      fn($x)=> $x !== '' && strtolower($x) !== 'true'
    )));
  }

  // Twin-first query (unchanged)
  $sqlTw = "
    SELECT sv.id AS color_id, sv.brand, sv.name, sv.hex6
    FROM {$SWATCH} sv
    WHERE sv.cluster_id = :cid
      AND LOWER(sv.brand) = LOWER(:brand)
      AND COALESCE(LOWER(sv.brand),'') <> 'true'
      AND sv.is_stain = 0
    ORDER BY (sv.hex6 = (SELECT rep_hex FROM clusters WHERE id = sv.cluster_id)) DESC, sv.name
    LIMIT 1
  ";
  $stmtTw = $pdo->prepare($sqlTw);

  // Candidate pool per brand (ordered by cheap ΔE76), we’ll rank by ΔE2000 in PHP
  $prefetchLimit = 400; // widen if needed
  $sqlPool = "
    SELECT sv.id AS color_id, sv.brand, sv.name, sv.hex6,
           sv.lab_l, sv.lab_a, sv.lab_b,
           ( POW(sv.lab_l - :L0, 2)
           + POW(sv.lab_a - :a0, 2)
           + POW(sv.lab_b - :b0, 2) ) AS de2_76
    FROM {$SWATCH} sv
    WHERE LOWER(sv.brand) = LOWER(:brand)
      AND COALESCE(LOWER(sv.brand),'') <> 'true'
      AND sv.lab_l IS NOT NULL AND sv.lab_a IS NOT NULL AND sv.lab_b IS NOT NULL
      AND sv.is_stain = 0
    ORDER BY de2_76 ASC, sv.name
    LIMIT :lim
  ";
  $stmtPool = $pdo->prepare($sqlPool);

  $best = [];

  foreach ($brands as $b) {
    // Twin in this brand?
    $stmtTw->execute([':cid'=>$clusterId, ':brand'=>$b]);
    if ($tw = $stmtTw->fetch(PDO::FETCH_ASSOC)) {
      $best[] = [
        'brand'        => $tw['brand'],
        'color_id'     => (int)$tw['color_id'],
        'name'         => $tw['name'],
        'hex6'         => $tw['hex6'],
        'delta_e'      => 0.0,
        'delta_e2000'  => 0.0,
        'is_twin'      => true
      ];
      continue;
    }

    // No twin: fetch a pool, then pick DE2000-min
    $stmtPool->bindValue(':L0', $L0);
    $stmtPool->bindValue(':a0', $a0);
    $stmtPool->bindValue(':b0', $b0);
    $stmtPool->bindValue(':brand', $b);
    $stmtPool->bindValue(':lim', $prefetchLimit, PDO::PARAM_INT);
    $stmtPool->execute();

    $rows = $stmtPool->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) continue;

    $bestRow = null;
    $bestDE2000 = INF;
    $bestDE76 = INF;

    foreach ($rows as $r) {
      $L = (float)$r['lab_l']; $aa = (float)$r['lab_a']; $bb = (float)$r['lab_b'];
      $de2000 = deltaE2000($L0, $a0, $b0, $L, $aa, $bb);
      $de76   = sqrt((float)$r['de2_76']);

      // Rank by DE2000; tie-break by DE76, then name
      $isBetter = false;
      if ($de2000 < $bestDE2000) {
        $isBetter = true;
      } elseif ($de2000 == $bestDE2000) {
        if ($de76 < $bestDE76) {
          $isBetter = true;
        } elseif ($de76 == $bestDE76) {
          if ($bestRow && strcmp($r['name'], $bestRow['name']) < 0) $isBetter = true;
        }
      }

      if ($isBetter) {
        $bestRow = $r;
        $bestDE2000 = $de2000;
        $bestDE76 = $de76;
      }
    }

    if ($bestRow) {
      $best[] = [
  'brand'        => $bestRow['brand'],
  'color_id'     => (int)$bestRow['color_id'],
  'name'         => $bestRow['name'],
  'hex6'         => $bestRow['hex6'],
  // IMPORTANT: make delta_e be DE2000 so the UI uses the perceptual metric
  'delta_e'      => round($bestDE2000, 2),
  // keep both for visibility if you want
  'delta_e2000'  => round($bestDE2000, 2),
  'delta_e76'    => round($bestDE76, 2),
  'is_twin'      => false
];

    }
  }

  // Sort final list for display: twins first (de2000=0), else ascending de2000 then brand
usort($best, function($x, $y) {
  // sort by DE2000 we just stored in delta_e
  if ($x['delta_e'] == $y['delta_e']) return strcmp($x['brand'], $y['brand']);
  return ($x['delta_e'] < $y['delta_e']) ? -1 : 1;
});

  return $best;
}


/** Facade: resolve → twins → optional brand-nearest (not used by controller for list) */
function colorMatch(PDO $pdo, array $opts): array {
  try {
    $src = cm_resolve_source($pdo, $opts);
    if (!$src) return ['ok'=>false, 'error'=>'Source color not found'];

    $clusterId = (int)$src['cluster_id'];
    $L0 = (float)$src['lab_l']; $a0 = (float)$src['lab_a']; $b0 = (float)$src['lab_b'];

    $twins = cm_twins($pdo, $clusterId);

    $out = [
      'ok'     => true,
      'source' => [
        'color_id'   => (int)$src['color_id'],
        'brand'      => $src['brand'],
        'name'       => $src['name'],
        'hex6'       => $src['hex6'],
        'cluster_id' => $clusterId,
        'lab'        => ['L'=>$L0,'a'=>$a0,'b'=>$b0],
      ],
      'twins'  => $twins['items'] ?? [],
    ];

    if (!empty($opts['target_brand'])) {
      $out['nearest'] = cm_nearest_in_brand($pdo, $clusterId, $L0, $a0, $b0, (string)$opts['target_brand'], (int)($opts['limit'] ?? 12));
    }

    return $out;

  } catch (\Throwable $e) {
    logj('colorMatch.error', ['err'=>$e->getMessage()]);
    return ['ok'=>false, 'error'=>$e->getMessage()];
  }
}


/**
 * Pick a LAB-bearing representative swatch for a cluster.
 * Prefers the swatch whose hex6 == clusters.rep_hex (non-stain, non-calibration).
 * Falls back to the swatch closest (by ΔE76) to the cluster's LAB centroid.
 *
 * Returns: [
 *   color_id, brand, name, hex6, cluster_id, lab_l, lab_a, lab_b
 * ] or null if none.
 */
function cm_resolve_cluster_rep(PDO $pdo, int $clusterId): ?array {
  global $SWATCH;
  if ($clusterId <= 0) return null;

  // 1) Try the cluster's rep_hex (exact swatch in this cluster)
  $sqlRep = "
    SELECT sv.id AS color_id, sv.brand, sv.name, sv.hex6,
           sv.cluster_id, sv.lab_l, sv.lab_a, sv.lab_b
    FROM {$SWATCH} sv
    JOIN clusters c ON c.id = sv.cluster_id
    WHERE sv.cluster_id = :cid
      AND COALESCE(LOWER(sv.brand),'') <> 'true'
      AND sv.is_stain = 0
      AND sv.lab_l IS NOT NULL AND sv.lab_a IS NOT NULL AND sv.lab_b IS NOT NULL
      AND UPPER(sv.hex6) = UPPER(c.rep_hex)
    ORDER BY sv.id
    LIMIT 1
  ";
  $stRep = $pdo->prepare($sqlRep);
  $stRep->execute([':cid' => $clusterId]);
  if ($row = $stRep->fetch(PDO::FETCH_ASSOC)) {
    return [
      'color_id'   => (int)$row['color_id'],
      'brand'      => (string)$row['brand'],
      'name'       => (string)$row['name'],
      'hex6'       => (string)$row['hex6'],
      'cluster_id' => (int)$row['cluster_id'],
      'lab_l'      => (float)$row['lab_l'],
      'lab_a'      => (float)$row['lab_a'],
      'lab_b'      => (float)$row['lab_b'],
    ];
  }

  // 2) Fall back: choose the swatch nearest to the cluster's LAB centroid
  $sqlCentroid = "
    SELECT
      AVG(sv.lab_l) AS Lc,
      AVG(sv.lab_a) AS ac,
      AVG(sv.lab_b) AS bc
    FROM {$SWATCH} sv
    WHERE sv.cluster_id = :cid
      AND COALESCE(LOWER(sv.brand),'') <> 'true'
      AND sv.is_stain = 0
      AND sv.lab_l IS NOT NULL AND sv.lab_a IS NOT NULL AND sv.lab_b IS NOT NULL
  ";
  $stC = $pdo->prepare($sqlCentroid);
  $stC->execute([':cid' => $clusterId]);
  $cent = $stC->fetch(PDO::FETCH_ASSOC);
  if (!$cent || $cent['Lc'] === null) {
    return null; // no viable LAB rows in this cluster
  }

  $Lc = (float)$cent['Lc']; $ac = (float)$cent['ac']; $bc = (float)$cent['bc'];

  $sqlPick = "
    SELECT sv.id AS color_id, sv.brand, sv.name, sv.hex6,
           sv.cluster_id, sv.lab_l, sv.lab_a, sv.lab_b,
           ( POW(sv.lab_l - :Lc, 2)
           + POW(sv.lab_a - :ac, 2)
           + POW(sv.lab_b - :bc, 2) ) AS de2_76
    FROM {$SWATCH} sv
    WHERE sv.cluster_id = :cid
      AND COALESCE(LOWER(sv.brand),'') <> 'true'
      AND sv.is_stain = 0
      AND sv.lab_l IS NOT NULL AND sv.lab_a IS NOT NULL AND sv.lab_b IS NOT NULL
    ORDER BY de2_76 ASC, sv.name, sv.id
    LIMIT 1
  ";
  $stPick = $pdo->prepare($sqlPick);
  $stPick->bindValue(':Lc',  $Lc);
  $stPick->bindValue(':ac',  $ac);
  $stPick->bindValue(':bc',  $bc);
  $stPick->bindValue(':cid', $clusterId, PDO::PARAM_INT);
  $stPick->execute();

  if ($r = $stPick->fetch(PDO::FETCH_ASSOC)) {
    return [
      'color_id'   => (int)$r['color_id'],
      'brand'      => (string)$r['brand'],
      'name'       => (string)$r['name'],
      'hex6'       => (string)$r['hex6'],
      'cluster_id' => (int)$r['cluster_id'],
      'lab_l'      => (float)$r['lab_l'],
      'lab_a'      => (float)$r['lab_a'],
      'lab_b'      => (float)$r['lab_b'],
    ];
  }

  return null;
}


/**
 * Nearest neighbors across ALL brands (brand codes), excluding the seed cluster (twins).
 * Prefilters a large pool by cheap ΔE76, computes ΔE2000 in PHP, ranks, then dedupes by cluster.
 *
 * Params:
 *  - $L0,$a0,$b0: seed LAB
 *  - $limit: number of neighbor clusters to return (default 12)
 *  - $excludeClusterId: the seed cluster id (exclude twins)
 *  - $maxDE2000: cutoff for inclusion (default 5.0)
 *
 * Returns: array of [
 *   color_id, cluster_id, brand, name, hex6, delta_e2000, is_twin=false
 * ]
 */
function cm_neighbors_any_brand(
  PDO $pdo,
  float $L0, float $a0, float $b0,
  int $limit = 12,
  int $excludeClusterId = 0,
  float $maxDE2000 = 5.0
): array {
  global $SWATCH;

  if (!function_exists('deltaE2000')) {
    throw new \RuntimeException('deltaE2000 helper not loaded (require deltaE2000.php)');
  }

  $limit = max(1, min(100, $limit));
  $prefetchLimit = 2000; // tune as needed

  // modest LAB window to shrink the pool before ΔE76 order
  $dL = 35; $da = 60; $db = 60;

  // Use POSITIONAL placeholders only (avoids HY093 on some stacks)
  $sql = "
    SELECT
      sv.id   AS color_id,
      sv.brand,
      sv.name,
      sv.hex6,
      sv.cluster_id,
      sv.lab_l, sv.lab_a, sv.lab_b,
      ( ((sv.lab_l - ?) * (sv.lab_l - ?))
      + ((sv.lab_a - ?) * (sv.lab_a - ?))
      + ((sv.lab_b - ?) * (sv.lab_b - ?)) ) AS de2_76
    FROM {$SWATCH} sv
    WHERE COALESCE(LOWER(sv.brand),'') <> 'true'
      AND sv.is_stain = 0
      AND sv.lab_l IS NOT NULL AND sv.lab_a IS NOT NULL AND sv.lab_b IS NOT NULL
      AND sv.cluster_id <> ?
      AND ABS(sv.lab_l - ?) <= ?
      AND ABS(sv.lab_a - ?) <= ?
      AND ABS(sv.lab_b - ?) <= ?
    ORDER BY de2_76 ASC, sv.name
    LIMIT ?
  ";

  $stmt = $pdo->prepare($sql);
  $i = 1;
  $stmt->bindValue($i++, $L0);
  $stmt->bindValue($i++, $L0);
  $stmt->bindValue($i++, $a0);
  $stmt->bindValue($i++, $a0);
  $stmt->bindValue($i++, $b0);
  $stmt->bindValue($i++, $b0);
  $stmt->bindValue($i++, $excludeClusterId, PDO::PARAM_INT);
  $stmt->bindValue($i++, $L0);
  $stmt->bindValue($i++, $dL, PDO::PARAM_INT);
  $stmt->bindValue($i++, $a0);
  $stmt->bindValue($i++, $da, PDO::PARAM_INT);
  $stmt->bindValue($i++, $b0);
  $stmt->bindValue($i++, $db, PDO::PARAM_INT);
  $stmt->bindValue($i++, $prefetchLimit, PDO::PARAM_INT);

  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) return [];

  foreach ($rows as &$r) {
    $L = (float)$r['lab_l']; $a = (float)$r['lab_a']; $b = (float)$r['lab_b'];
    $r['de2000'] = deltaE2000($L0, $a0, $b0, $L, $a, $b);
    $r['de76']   = sqrt((float)$r['de2_76']);
  }
  unset($r);

  usort($rows, function($x, $y) {
    if ($x['de2000'] == $y['de2000']) {
      if ($x['de76'] == $y['de76']) {
        $bc = strcmp((string)$x['brand'], (string)$y['brand']);
        if ($bc !== 0) return $bc;
        return strcmp((string)$x['name'], (string)$y['name']);
      }
      return ($x['de76'] < $y['de76']) ? -1 : 1;
    }
    return ($x['de2000'] < $y['de2000']) ? -1 : 1;
  });

  $out = [];
  $seenClusters = [];
  foreach ($rows as $r) {
    $cid = (int)$r['cluster_id'];
    if (isset($seenClusters[$cid])) continue;
    if ($r['de2000'] > $maxDE2000) continue;

    $seenClusters[$cid] = 1;
    $out[] = [
      'color_id'     => (int)$r['color_id'],
      'cluster_id'   => $cid,
      'brand'        => (string)$r['brand'],
      'name'         => (string)$r['name'],
      'hex6'         => (string)$r['hex6'],
      'delta_e2000'  => round((float)$r['de2000'], 2),
      'is_twin'      => false,
    ];
    if (count($out) >= $limit) break;
  }

  return $out;
}
