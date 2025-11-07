<?php
declare(strict_types=1);

/**
 * Palette search engine used by both Browse Palettes and MyPalette “What goes with …?”
 * Exposes one top-level function:
 *   pe_search_palettes(PDO $pdo, array $in): array
 */

/* ---------- Robust path resolution for Bluehost ---------- */
$HERE = dirname(__FILE__);
$API  = dirname($HERE);

// Try the most direct absolute paths first
$paths = [
  'db'         => [$API . '/db.php', 'db.php', $HERE . '/../db.php'],
  'colorMatch' => [$HERE . '/colorMatch.php', $API . '/functions/colorMatch.php', 'functions/colorMatch.php'],
];

// Require db.php
$loadedDb = false;
foreach ($paths['db'] as $p) {
  if (@file_exists($p)) { require_once $p; $loadedDb = true; break; }
}
if (!$loadedDb) {
  @chdir($API);
  if (@file_exists('db.php')) { require_once 'db.php'; $loadedDb = true; }
}
if (!$loadedDb) { throw new RuntimeException('db.php not found from paletteEngine.php'); }

// Require functions/colorMatch.php
$loadedCM = false;
foreach ($paths['colorMatch'] as $p) {
  if (@file_exists($p)) { require_once $p; $loadedCM = true; break; }
}
if (!$loadedCM) {
  if (@file_exists('functions/colorMatch.php')) { require_once 'functions/colorMatch.php'; $loadedCM = true; }
}
if (!$loadedCM) { throw new RuntimeException('functions/colorMatch.php not found from paletteEngine.php'); }

set_exception_handler(function($e){
  @mkdir($API . '/logs', 0775, true);
  @file_put_contents($API . '/logs/paletteEngine.include.diag.log',
    date('c').' EXC: '.$e->getMessage()."\n".$e->getTraceAsString()."\n",
    FILE_APPEND | LOCK_EX
  );
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error'=>$e->getMessage(), 'where'=>'paletteEngine includes'], JSON_UNESCAPED_SLASHES);
  exit;
});

/* ---------------- Logging ---------------- */
function pe_dbg(string $msg): void {
  static $paths = null;
  if ($paths === null) {
    $paths = [
      dirname(__DIR__) . '/logs/palette-engine.log',
      sys_get_temp_dir() . '/palette-engine.log',
    ];
  }
  $line = date('c') . ' ' . $msg . PHP_EOL;
  foreach ($paths as $p) { @file_put_contents($p, $line, FILE_APPEND | LOCK_EX); }
  @error_log($line, 3, dirname(__DIR__) . '/debug.log');
}

/* ---------------- Utilities ---------------- */
function pe_parse_row(array $row): array {
  $pairs = $row['member_pairs'] ? explode(',', $row['member_pairs']) : [];
  $memberIds = [];
  $members   = [];
  foreach ($pairs as $p) {
    [$cid, $hex] = array_pad(explode(':', $p, 2), 2, '');
    $cid = (int)$cid;
    $memberIds[] = $cid;
    $members[] = [
      'cluster_id' => $cid,
      'rep_hex'    => ($hex !== '' ? '#'.$hex : null),
    ];
  }
  return [
    'palette_id'         => (int)$row['palette_id'],
    'size'               => (int)$row['size'],
    'member_cluster_ids' => $memberIds,
    'members'            => $members,
    'meta'               => (object)[],
  ];
}

function pe_member_set_key(array $memberIds): string {
  $ids = array_values(array_map('intval', $memberIds));
  sort($ids, SORT_NUMERIC);
  return implode(',', $ids);
}

/**
 * Build the (Tier A) SQL with optional anchor + filters.
 * Since your UI always supplies a lightness when a family is picked,
 * we enforce SAME-MEMBER AND here for (neutral+lightness) or (hue+lightness).
 * Returns [sql, params]
 */
/**
 * Build Tier-A SQL with strict SAME-MEMBER enforcement.
 * If a family (hue or neutral) AND a lightness are provided, a single EXISTS
 * clause requires one member cluster to match BOTH on the same row in clusters.
 */
function pe_build_sql_for_anchor(
  ?int $anchor,
  int $sizeMin,
  int $sizeMax,
  ?string $hueCats,
  ?string $neutralCats,
  ?string $lightnessCats
): array {

  $pidSql    = "FROM palettes p WHERE p.tier = 'A' ";
  $pidParams = [];

  // Optional anchor
  if ($anchor) {
    $pidSql .= " AND EXISTS (
      SELECT 1 FROM palette_members x
      WHERE x.palette_id = p.id AND x.member_cluster_id = ?
    )";
    $pidParams[] = $anchor;
  }

  // STRICT same-member logic:
  // If a family and a lightness are given, require them on the SAME cluster.
  if ($neutralCats && $lightnessCats) {
    $pidSql .= " AND EXISTS (
      SELECT 1
      FROM palette_members m
      JOIN clusters c ON c.id = m.member_cluster_id
      WHERE m.palette_id = p.id
        AND c.neutral_cats IS NOT NULL AND c.neutral_cats <> ''
        AND c.neutral_cats LIKE ?
        AND c.lightness_cats LIKE ?
    )";
    $pidParams[] = '%'.$neutralCats.'%';
    $pidParams[] = '%'.$lightnessCats.'%';
  } elseif ($hueCats && $lightnessCats) {
    $pidSql .= " AND EXISTS (
      SELECT 1
      FROM palette_members m
      JOIN clusters c ON c.id = m.member_cluster_id
      WHERE m.palette_id = p.id
        AND (c.neutral_cats IS NULL OR c.neutral_cats = '')
        AND c.hue_cats LIKE ?
        AND c.lightness_cats LIKE ?
    )";
    $pidParams[] = '%'.$hueCats.'%';
    $pidParams[] = '%'.$lightnessCats.'%';
  } elseif ($neutralCats) {
    // family only (just in case)
    $pidSql .= " AND EXISTS (
      SELECT 1
      FROM palette_members z
      JOIN clusters c2 ON c2.id = z.member_cluster_id
      WHERE z.palette_id = p.id
        AND c2.neutral_cats IS NOT NULL AND c2.neutral_cats <> ''
        AND c2.neutral_cats LIKE ?
    )";
    $pidParams[] = '%'.$neutralCats.'%';
  } elseif ($hueCats) {
    // family only (just in case)
    $pidSql .= " AND EXISTS (
      SELECT 1
      FROM palette_members y
      JOIN clusters c ON c.id = y.member_cluster_id
      WHERE y.palette_id = p.id
        AND (c.neutral_cats IS NULL OR c.neutral_cats = '')
        AND c.hue_cats LIKE ?
    )";
    $pidParams[] = '%'.$hueCats.'%';
  } elseif ($lightnessCats) {
    // lightness only (edge)
    $pidSql .= " AND EXISTS (
      SELECT 1
      FROM palette_members m
      JOIN clusters c ON c.id = m.member_cluster_id
      WHERE m.palette_id = p.id
        AND c.lightness_cats LIKE ?
    )";
    $pidParams[] = '%'.$lightnessCats.'%';
  }

  $sql = "
    SELECT
      pid.id AS palette_id,
      COUNT(pm.member_cluster_id) AS size,
      GROUP_CONCAT(
        CONCAT(pm.member_cluster_id, ':', COALESCE(cc.rep_hex,''))
        ORDER BY
          CASE WHEN cc.neutral_cats IS NULL OR cc.neutral_cats = '' THEN 1 ELSE 0 END DESC,
          cc.c_r DESC,
          cc.l_r ASC
        SEPARATOR ','
      ) AS member_pairs
    FROM (
      SELECT DISTINCT p.id
      $pidSql
    ) AS pid
    JOIN palette_members pm ON pm.palette_id = pid.id
    JOIN clusters cc        ON cc.id = pm.member_cluster_id
    GROUP BY pid.id
    HAVING size BETWEEN ? AND ?
    ORDER BY size DESC, pid.id DESC
  ";

  $params = array_merge($pidParams, [$sizeMin, $sizeMax]);

  // DEBUG: confirm the strict WHERE is live
  pe_dbg('STRICT WHERE=' . $pidSql . ' :: ' . json_encode($pidParams, JSON_UNESCAPED_SLASHES));

  return [$sql, $params];
}



/**
 * Count Tier A by size (for min_results + UI counts)
 * Mirrors the same-member logic above.
 */
/**
 * Count Tier-A by size using the exact same strict filters as above,
 * so the accordion/header counts reflect what the user will actually see.
 */
function pe_count_tierA(
  PDO $pdo,
  ?int $anchorId,
  ?string $hueCats,
  ?string $neutralCats,
  ?string $lightnessCats,
  int $sizeMin,
  int $sizeMax
): array {

  $where = " WHERE p.tier = 'A' ";
  $fp = [];

  if ($anchorId) {
    $where .= " AND EXISTS (
      SELECT 1 FROM palette_members x
      WHERE x.palette_id = p.id AND x.member_cluster_id = ?
    )";
    $fp[] = $anchorId;
  }

  if ($neutralCats && $lightnessCats) {
    $where .= " AND EXISTS (
      SELECT 1
      FROM palette_members m
      JOIN clusters c ON c.id = m.member_cluster_id
      WHERE m.palette_id = p.id
        AND c.neutral_cats IS NOT NULL AND c.neutral_cats <> ''
        AND c.neutral_cats LIKE ?
        AND c.lightness_cats LIKE ?
    )";
    $fp[] = '%'.$neutralCats.'%';
    $fp[] = '%'.$lightnessCats.'%';
  } elseif ($hueCats && $lightnessCats) {
    $where .= " AND EXISTS (
      SELECT 1
      FROM palette_members m
      JOIN clusters c ON c.id = m.member_cluster_id
      WHERE m.palette_id = p.id
        AND (c.neutral_cats IS NULL OR c.neutral_cats = '')
        AND c.hue_cats LIKE ?
        AND c.lightness_cats LIKE ?
    )";
    $fp[] = '%'.$hueCats.'%';
    $fp[] = '%'.$lightnessCats.'%';
  } else {
    if ($neutralCats) {
      $where .= " AND EXISTS (
        SELECT 1
        FROM palette_members m
        JOIN clusters c ON c.id = m.member_cluster_id
        WHERE m.palette_id = p.id
          AND c.neutral_cats IS NOT NULL AND c.neutral_cats <> ''
          AND c.neutral_cats LIKE ?
      )";
      $fp[] = '%'.$neutralCats.'%';
    }
    if ($hueCats) {
      $where .= " AND EXISTS (
        SELECT 1
        FROM palette_members m
        JOIN clusters c ON c.id = m.member_cluster_id
        WHERE m.palette_id = p.id
          AND (c.neutral_cats IS NULL OR c.neutral_cats = '')
          AND c.hue_cats LIKE ?
      )";
      $fp[] = '%'.$hueCats.'%';
    }
    if ($lightnessCats) {
      $where .= " AND EXISTS (
        SELECT 1
        FROM palette_members m
        JOIN clusters c ON c.id = m.member_cluster_id
        WHERE m.palette_id = p.id
          AND c.lightness_cats LIKE ?
      )";
      $fp[] = '%'.$lightnessCats.'%';
    }
  }

  $sub = "
    SELECT p.id AS pid, COUNT(pm.member_cluster_id) AS size
    FROM palettes p
    JOIN palette_members pm ON pm.palette_id = p.id
    $where
    GROUP BY p.id
    HAVING size BETWEEN ? AND ?
  ";

  $params = array_merge($fp, [$sizeMin, $sizeMax]);
  $sql    = "SELECT size, COUNT(*) AS cnt FROM ($sub) s GROUP BY size ORDER BY size";

  $st = $pdo->prepare($sql);
  $st->execute($params);

  $counts = [];
  $total  = 0;
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $counts[(string)$r['size']] = (int)$r['cnt'];
    $total += (int)$r['cnt'];
  }
  return [$counts, $total];
}


/**
 * Dedupe (two-pass HCL grid)
 */
function pe_dedupe_hcl_grid(PDO $pdo, array $itemsMerged, ?int $anchorId): array {
  if (empty($itemsMerged)) return $itemsMerged;

  $H_STEP = 2;  $C_STEP = 2;  $L_STEP = 2;
  $C_STEP_NEUTRAL = 1; $L_STEP_NEUTRAL = 2;

  $prefer = function(array $A, array $B) {
    $pa = $A['meta']->provenance ?? 'original';
    $pb = $B['meta']->provenance ?? 'original';
    if ($pa !== $pb) return ($pa === 'original') ? $A : $B;
    if ($pa !== 'original') {
      $da = (float)($A['meta']->neighbor['de'] ?? INF);
      $db = (float)($B['meta']->neighbor['de'] ?? INF);
      if ($da !== $db) return ($da < $db) ? $A : $B;
    }
    if ($A['size'] !== $B['size']) return ($A['size'] > $B['size']) ? $A : $B;
    return ($A['palette_id'] > $B['palette_id']) ? $A : $B;
  };

  $allIds = [];
  foreach ($itemsMerged as $it) foreach ($it['member_cluster_ids'] as $cid) $allIds[(int)$cid] = true;
  $allIds = array_values(array_keys($allIds));

  $hcl = [];
  if ($allIds) {
    $ph  = implode(',', array_fill(0, count($allIds), '?'));
    $sql = "SELECT id, COALESCE(h_r,0) AS h_r, COALESCE(c_r,0) AS c_r, COALESCE(l_r,0) AS l_r,
                   CASE WHEN neutral_cats IS NULL OR neutral_cats='' THEN 0 ELSE 1 END AS is_neutral
            FROM clusters WHERE id IN ($ph)";
    $st = $pdo->prepare($sql);
    $st->execute($allIds);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $hcl[(int)$r['id']] = [
        'h' => (int)$r['h_r'],
        'c' => (int)$r['c_r'],
        'l' => (int)$r['l_r'],
        'neutral' => ((int)$r['is_neutral'] === 1),
      ];
    }
  }

  $snap = function (int $v, int $step, int $offset = 0): int {
    if ($step <= 1) return $v;
    $v2 = $v + $offset;
    $q  = (int)floor(($v2 + $step/2) / $step);
    $r  = $q * $step - $offset;
    return $r;
  };

  $canonCode = function (int $cid, int $gridOffset) use ($hcl,$H_STEP,$C_STEP,$L_STEP,$C_STEP_NEUTRAL,$L_STEP_NEUTRAL,$snap): string {
    $p = $hcl[$cid] ?? ['h'=>0,'c'=>0,'l'=>0,'neutral'=>false];
    if ($p['neutral']) {
      $c = $snap($p['c'], $C_STEP_NEUTRAL);
      $l = $snap($p['l'], $L_STEP_NEUTRAL);
      return "N|C{$c}|L{$l}";
    } else {
      $h = $snap($p['h'], $H_STEP, $gridOffset);
      if ($h >= 360) $h -= 360; if ($h < 0) $h += 360;
      $c = $snap($p['c'], $C_STEP, $gridOffset ? 1 : 0);
      $l = $snap($p['l'], $L_STEP, $gridOffset ? 1 : 0);
      return "C|H{$h}|C{$c}|L{$l}";
    }
  };

  $restKeys = function (array $it) use ($anchorId, $canonCode): array {
    $prov = $it['meta']->provenance ?? 'original';
    $anchorForItem = ($prov === 'original')
      ? ($anchorId ?: null)
      : (isset($it['meta']->neighbor['cluster_id']) ? (int)$it['meta']->neighbor['cluster_id'] : null);

    $codesA = []; $codesB = [];
    foreach ($it['member_cluster_ids'] as $cid) {
      $cid = (int)$cid;
      if ($anchorForItem !== null && $cid === $anchorForItem) continue;
      $codesA[] = $canonCode($cid, 0);
      $codesB[] = $canonCode($cid, 1);
    }
    sort($codesA, SORT_STRING);
    sort($codesB, SORT_STRING);
    return [ implode(',', $codesA), implode(',', $codesB) ];
  };

  $byKeyA = []; $byKeyB = []; $order  = [];
  foreach ($itemsMerged as $it) {
    [$kA, $kB] = $restKeys($it);
    $existing = $byKeyA[$kA] ?? $byKeyB[$kB] ?? null;
    if ($existing === null) {
      $byKeyA[$kA] = $it;
      $byKeyB[$kB] = $it;
      $order[] = [$kA,$kB];
    } else {
      $winner = $prefer($existing, $it);
      if ($winner !== $existing) {
        $byKeyA[$kA] = $winner;
        $byKeyB[$kB] = $winner;
      }
    }
  }

  $deduped = [];
  foreach ($order as [$kA,$kB]) {
    $chosen = $byKeyA[$kA] ?? $byKeyB[$kB] ?? null;
    if ($chosen) $deduped[] = $chosen;
  }
  $itemsMerged = $deduped;

  /* Second, coarser pass */
  $H2=6; $C2=4; $L2=4; $CN2=2; $LN2=3;
  $snap2 = function (int $v, int $step, int $offset = 0): int {
    if ($step <= 1) return $v;
    $v2 = $v + $offset;
    $q  = (int)floor(($v2 + $step/2) / $step);
    $r  = $q * $step - $offset;
    if ($step === 6) { if ($r >= 360) $r -= 360; if ($r < 0) $r += 360; }
    return $r;
  };
  $canon2 = function (int $cid, int $gridOffset) use ($hcl,$H2,$C2,$L2,$CN2,$LN2,$snap2): string {
    $p = $hcl[$cid] ?? ['h'=>0,'c'=>0,'l'=>0,'neutral'=>false];
    if ($p['neutral']) {
      $c = $snap2($p['c'], $CN2);
      $l = $snap2($p['l'], $LN2);
      return "N|C{$c}|L{$l}";
    } else {
      $h = $snap2($p['h'], $H2, $gridOffset ? 1 : 0);
      $c = $snap2($p['c'], $C2, $gridOffset ? 1 : 0);
      $l = $snap2($p['l'], $L2, $gridOffset ? 1 : 0);
      return "C|H{$h}|C{$c}|L{$l}";
    }
  };
  $restKeys2 = function (array $it) use ($anchorId,$canon2): array {
    $prov = $it['meta']->provenance ?? 'original';
    $anchorForItem = ($prov === 'original')
      ? ($anchorId ?: null)
      : (isset($it['meta']->neighbor['cluster_id']) ? (int)$it['meta']->neighbor['cluster_id'] : null);

    $A = []; $B = [];
    foreach ($it['member_cluster_ids'] as $cid) {
      $cid = (int)$cid;
      if ($anchorForItem !== null && $cid === $anchorForItem) continue;
      $A[] = $canon2($cid, 0);
      $B[] = $canon2($cid, 1);
    }
    sort($A, SORT_STRING);
    sort($B, SORT_STRING);
    return [ implode(',', $A), implode(',', $B) ];
  };

  $mapA = []; $mapB = []; $order2 = []; $out2 = [];
  foreach ($itemsMerged as $it) {
    [$kA,$kB] = $restKeys2($it);
    $existing = $mapA[$kA] ?? $mapB[$kB] ?? null;
    if ($existing === null) {
      $mapA[$kA] = $it; $mapB[$kB] = $it; $order2[]  = [$kA,$kB];
    } else {
      $winner = $prefer($existing, $it);
      if ($winner !== $existing) { $mapA[$kA] = $winner; $mapB[$kB] = $winner; }
    }
  }
  foreach ($order2 as [$kA,$kB]) {
    $chosen = $mapA[$kA] ?? $mapB[$kB] ?? null;
    if ($chosen) $out2[] = $chosen;
  }
  return $out2;
}

/**
 * Main search — returns the same shape your endpoint emits today.
 */
function pe_search_palettes(PDO $pdo, array $in): array {
  $sizeMin       = max(1, (int)($in['size_min'] ?? 3));
  $sizeMax       = max($sizeMin, (int)($in['size_max'] ?? 7));
  $anchorId      = isset($in['exact_anchor_cluster_id']) ? (int)$in['exact_anchor_cluster_id'] : null;
  $hueCats       = $in['include_idea']['hue_cats']       ?? null;
  $neutralCats   = $in['include_idea']['neutral_cats']   ?? null;
  $lightnessCats = $in['include_idea']['lightness_cats'] ?? null;
  $limit         = min(100, (int)($in['limit']  ?? 60));
  $offset        = max(0,   (int)($in['offset'] ?? 0));

  $prefetchMul   = 3;
  $prefetch      = max($limit, $limit * $prefetchMul);

  $includeSimilar = (int)($in['include_similar'] ?? 0) === 1;
  $minResults     = max(1, (int)($in['min_results'] ?? 12));
  $neighborsCap   = max(1, min(50, (int)($in['neighbors'] ?? 8)));
  $maxDE2000      = (float)($in['max_de'] ?? 4.0);
  $sourceId       = isset($in['source_id']) ? (int)$in['source_id'] : 0;

  pe_dbg('PE IDEA: '.json_encode([
    'hue'=>$hueCats, 'neutral'=>$neutralCats, 'lightness'=>$lightnessCats,
    'sizeMin'=>$sizeMin, 'sizeMax'=>$sizeMax
  ], JSON_UNESCAPED_SLASHES));

  if ($pdo instanceof PDO) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }

  // Tier A
  [$sqlA, $paramsA] = pe_build_sql_for_anchor($anchorId, $sizeMin, $sizeMax, $hueCats, $neutralCats, $lightnessCats);
  $sqlA_paged    = $sqlA . " LIMIT ? OFFSET ? ";
  $paramsA_paged = array_merge($paramsA, [$prefetch, $offset]);
  pe_dbg('PE SQL A: '.$sqlA_paged);
  pe_dbg('PE PAR A: '.json_encode($paramsA_paged, JSON_UNESCAPED_SLASHES));

  $stA = $pdo->prepare($sqlA_paged);
  $stA->execute($paramsA_paged);
  $itemsA  = [];
  $seenKeys = [];
  while ($row = $stA->fetch(PDO::FETCH_ASSOC)) {
    $it = pe_parse_row($row);
    $k  = pe_member_set_key($it['member_cluster_ids']);
    $seenKeys[$k] = true;
    $it['meta'] = (object)['provenance' => 'original'];
    $itemsA[] = $it;
  }

  // Counts (match same-member logic)
  [$countsA, $totalA] = pe_count_tierA($pdo, $anchorId, $hueCats, $neutralCats, $lightnessCats, $sizeMin, $sizeMax);

  // Disable neighbors when lightness is selected (tight + fast)
  $needSimilar  = $includeSimilar || ($totalA < $minResults && !$lightnessCats);

  $itemsMerged  = $itemsA;
  $neighborTaps = 0;

  if ($needSimilar) {
    $seed = null;
    if ($sourceId > 0) {
      $seed = cm_resolve_source($pdo, ['source_id'=>$sourceId]);
    } elseif ($anchorId) {
      $seed = cm_resolve_cluster_rep($pdo, (int)$anchorId);
    }
    if ($seed && isset($seed['lab_l'],$seed['lab_a'],$seed['lab_b'])) {
      $L0 = (float)$seed['lab_l']; $a0 = (float)$seed['lab_a']; $b0 = (float)$seed['lab_b'];
      $seedCluster = (int)$seed['cluster_id'];

      $neighbors = cm_neighbors_any_brand($pdo, $L0,$a0,$b0, $neighborsCap, $seedCluster, $maxDE2000);
      pe_dbg('PE neighbors='.count($neighbors));

      $limitPerNeighbor = max(6, min($prefetch, $minResults * 2));

      foreach ($neighbors as $nb) {
        $neighborTaps++;
        $anchorNeighbor = (int)$nb['cluster_id'];
        [$sqlAnchor, $paramsAnchor] = pe_build_sql_for_anchor($anchorNeighbor, $sizeMin, $sizeMax, $hueCats, $neutralCats, $lightnessCats);
        $sqlNeighbor    = $sqlAnchor . " LIMIT ? ";
        $paramsNeighbor = array_merge($paramsAnchor, [$limitPerNeighbor]);

        pe_dbg('PE SQL B['.$neighborTaps.']: '.$sqlNeighbor);
        pe_dbg('PE PAR B['.$neighborTaps.']: '.json_encode($paramsNeighbor, JSON_UNESCAPED_SLASHES));

        $stN = $pdo->prepare($sqlNeighbor);
        $stN->execute($paramsNeighbor);

        while ($row = $stN->fetch(PDO::FETCH_ASSOC)) {
          $it = pe_parse_row($row);
          $k  = pe_member_set_key($it['member_cluster_ids']);
          if (isset($seenKeys[$k])) continue;

          $seenKeys[$k] = true;
          $it['meta'] = (object)[
            'provenance' => 'similar',
            'neighbor'   => [
              'color_id'   => (int)$nb['color_id'],
              'cluster_id' => (int)$nb['cluster_id'],
              'brand'      => (string)$nb['brand'],
              'name'       => (string)$nb['name'],
              'de'         => (float)$nb['delta_e2000'],
            ],
          ];
          $itemsMerged[] = $it;

          if (count($itemsMerged) >= ($offset + $limit + (int)ceil($limit * 0.25))) {
            break 2;
          }
        }
      }
    } else {
      pe_dbg('PE TierB skipped: seed not resolved or missing LAB');
    }
  }

  // Dedupe
  $itemsMerged = pe_dedupe_hcl_grid($pdo, $itemsMerged, $anchorId);

  // Order + page
  usort($itemsMerged, function($a,$b){
    $pa = $a['meta']->provenance ?? 'original';
    $pb = $b['meta']->provenance ?? 'original';
    if ($pa !== $pb) return ($pa === 'original') ? -1 : 1;
    if ($pa !== 'original') {
      $da = (float)($a['meta']->neighbor['de'] ?? INF);
      $db = (float)($b['meta']->neighbor['de'] ?? INF);
      if ($da !== $db) return $da <=> $db;
    }
    if ($a['size'] !== $b['size']) return $b['size'] <=> $a['size'];
    return $b['palette_id'] <=> $a['palette_id'];
  });

  // Recompute counts from final items so headers always match
  $countsOut = [];
  foreach ($itemsMerged as $it) {
    $k = (string)$it['size'];
    $countsOut[$k] = ($countsOut[$k] ?? 0) + 1;
  }
  $totalOut = array_sum($countsOut);

  $paged      = array_slice($itemsMerged, 0, $limit);
  $nextOffset = (count($paged) >= $limit) ? ($offset + $limit) : null;

  return [
    'items'           => array_values($paged),
    'counts_by_size'  => $countsOut,
    'total_count'     => $totalOut,
    'limit'           => $limit,
    'offset'          => $offset,
    'next_offset'     => $nextOffset,
    'search_expanded' => $needSimilar ? 1 : 0,
    'neighbors_used'  => $needSimilar ? $neighborTaps : 0,
  ];
}
