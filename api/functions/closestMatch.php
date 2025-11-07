<?php
declare(strict_types=1);

/**
 * closestMatch.php
 *
 * Returns truly-close matches to a seed color using CIEDE2000 computed from the
 * seed's LAB stored on the **colors** table (not cluster averages).
 *
 * Public entry:
 *   cf_closest_matches(PDO $pdo, array $opts): array
 *
 * Inputs ($opts):
 *   - seed_color_id    int   Preferred: a specific color id (pulls LAB from colors)
 *   - seed_cluster_id  int   Optional: fallback to cluster rep via colorMatch.php
 *   - source_id        int   Optional: fallback resolver you already support
 *   - cap              int   Max neighbors to return (default 10)
 *   - mode             'adaptive' | 'fixed'  (default 'adaptive')
 *   - max_de           float  Used only in 'fixed' mode (default 3.0)
 *   - hcl_limits       array  Optional overrides: ['hue_deg'=>int,'chroma'=>int,'lightness'=>int]
 *
 * Output (array of matches):
 *   [
 *     [
 *       'color_id'   => int,
 *       'cluster_id' => int,
 *       'brand'      => string,
 *       'name'       => string,
 *       'rep_hex'    => string|null,   // '#rrggbb'
 *       'delta_e'    => float,         // CIEDE2000 vs seed
 *       'delta_h'    => int,           // |Δh| in degrees (rounded HCL)
 *       'delta_c'    => int,           // |Δc| in rounded units
 *       'delta_l'    => int,           // |Δl| in rounded units
 *     ],
 *     ...
 *   ]
 */

/* ---------- Robust includes for Bluehost ---------- */
$HERE = dirname(__FILE__);      // e.g., /.../api/functions
$API  = dirname($HERE);         // e.g., /.../api

$loadedDb = false;
foreach ([$API.'/db.php', $HERE.'/../db.php', 'db.php'] as $p) {
  if (@file_exists($p)) { require_once $p; $loadedDb = true; break; }
}
if (!$loadedDb) { @chdir($API); if (@file_exists('db.php')) { require_once 'db.php'; $loadedDb = true; } }
if (!$loadedDb) { throw new RuntimeException('closestMatch: db.php not found'); }

$loadedCM = false;
foreach ([$HERE.'/colorMatch.php', $API.'/functions/colorMatch.php', 'functions/colorMatch.php'] as $p) {
  if (@file_exists($p)) { require_once $p; $loadedCM = true; break; }
}
if (!$loadedCM) { if (@file_exists('functions/colorMatch.php')) { require_once 'functions/colorMatch.php'; $loadedCM = true; } }
if (!$loadedCM) { throw new RuntimeException('closestMatch: functions/colorMatch.php not found'); }

/* ---------- Helpers ---------- */

function cfcm_hue_diff_deg(int $h1, int $h2): int {
  $d = abs($h1 - $h2);
  if ($d > 180) $d = 360 - $d;
  return (int)$d;
}

/**
 * Adaptive envelope based on seed chroma (rounded c_r from cluster).
 * Tighten hue & ΔE for high-chroma; relax hue for near-neutrals but keep L/C tight.
 */
function cfcm_adaptive_limits(int $seed_c): array {
  if ($seed_c <= 6) {
    return ['max_de'=>2.8, 'hue_deg'=>12, 'chroma'=>2, 'lightness'=>3];
  } elseif ($seed_c <= 20) {
    return ['max_de'=>3.0, 'hue_deg'=>8, 'chroma'=>4, 'lightness'=>4];
  } else {
    return ['max_de'=>2.6, 'hue_deg'=>6, 'chroma'=>5, 'lightness'=>4];
  }
}

/**
 * Post-filter a list of neighbors sorted by delta_e2000 ascending,
 * keeping the tight cluster until the first significant gap.
 *
 * $rows: [ ['color_id'=>..., 'cluster_id'=>..., 'delta_e2000'=>..., ...], ... ]
 */
function cut_by_gap_adaptive(array $rows, array $opt = []): array {
  $probeN      = $opt['probe']      ?? 10;   // how many to initially consider
  $gapMin      = $opt['gap_min']    ?? 0.8;  // absolute min gap to trigger
  $gapMult     = $opt['gap_mult']   ?? 2.2;  // relative (to local baseline) gap to trigger
  $baselineK   = $opt['baseline_k'] ?? 5;    // diffs to compute baseline (first K)
  $maxN        = $opt['max_n']      ?? 8;    // safety max results
  $ceilDe      = $opt['ceiling_de'] ?? 6.0;  // never keep beyond this ΔE

  if (count($rows) <= 1) return $rows;

  // Only probe the first N closest
  $rows = array_slice($rows, 0, $probeN);

  // Collect ΔEs (prefer 'delta_e', fallback to 'delta_e2000')
  $de = array_values(array_map(function($r){
    $v = $r['delta_e'] ?? $r['delta_e2000'] ?? null;
    return is_numeric($v) ? (float)$v : 999.0;
  }, $rows));

  if (!is_finite($de[0])) return [];

  // Compute successive gaps
  $diffs = [];
  for ($i = 0; $i < count($de) - 1; $i++) {
    $d = $de[$i+1] - $de[$i];
    $diffs[] = $d < 0 ? 0.0 : $d;
  }
  if (!$diffs) {
    return array_slice($rows, 0, min($maxN, count($rows)));
  }

  // Local baseline = median of first K diffs
  $k = max(1, min($baselineK, count($diffs)));
  $firstK = array_slice($diffs, 0, $k);
  sort($firstK);
  $mid = intdiv(count($firstK), 2);
  $base = (count($firstK) % 2 === 1) ? $firstK[$mid] : 0.5 * ($firstK[$mid-1] + $firstK[$mid]);
  if (!is_finite($base) || $base <= 0) $base = 0.1;

  $trigger = max($gapMin, $gapMult * $base);

  // Find first significant gap
  $cutIndex = null;
  for ($i = 0; $i < count($diffs); $i++) {
    $gap = $diffs[$i];
    if ($gap >= $trigger || $de[$i+1] > $ceilDe) {
      $cutIndex = $i; // keep up to $i inclusive
      break;
    }
  }

  if ($cutIndex === null) {
    // No big jump: keep up to ceiling and maxN
    $keep = [];
    foreach ($rows as $r) {
      $e = $r['delta_e'] ?? $r['delta_e2000'] ?? 999.0;
      if ((float)$e <= $ceilDe) $keep[] = $r;
      if (count($keep) >= $maxN) break;
    }
    return $keep;
  }

  $keepN = min($maxN, $cutIndex + 1);
  return array_slice($rows, 0, $keepN);
}



/**
 * Resolve seed using colors table LAB first.
 * Returns: ['color_id','cluster_id','lab_l','lab_a','lab_b','h_r','c_r','l_r']
 */
function cfcm_resolve_seed(PDO $pdo, array $opts): ?array {
  $seedColorId   = isset($opts['seed_color_id'])   ? (int)$opts['seed_color_id']   : 0;
  $seedClusterId = isset($opts['seed_cluster_id']) ? (int)$opts['seed_cluster_id'] : 0;
  $sourceId      = isset($opts['source_id'])       ? (int)$opts['source_id']       : 0;

  // 1) Preferred: explicit color id -> get LAB directly from colors table
  if ($seedColorId > 0) {
    $sql = "SELECT c.id AS color_id, c.cluster_id, c.lab_l, c.lab_a, c.lab_b,
                   cl.h_r, cl.c_r, cl.l_r
            FROM colors c
            LEFT JOIN clusters cl ON cl.id = c.cluster_id
            WHERE c.id = ? AND c.lab_l IS NOT NULL AND c.lab_a IS NOT NULL AND c.lab_b IS NOT NULL
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$seedColorId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      return [
        'color_id'   => (int)$row['color_id'],
        'cluster_id' => (int)$row['cluster_id'],
        'lab_l'      => (float)$row['lab_l'],
        'lab_a'      => (float)$row['lab_a'],
        'lab_b'      => (float)$row['lab_b'],
        'h_r'        => (int)($row['h_r'] ?? 0),
        'c_r'        => (int)($row['c_r'] ?? 0),
        'l_r'        => (int)($row['l_r'] ?? 0),
      ];
    }
  }

  // 2) Fallbacks: your existing helpers (should return a colors-based LAB)
  if ($sourceId > 0) {
    $seed = cm_resolve_source($pdo, ['source_id' => $sourceId]);
    if ($seed && isset($seed['lab_l'],$seed['lab_a'],$seed['lab_b'])) {
      return [
        'color_id'   => (int)($seed['color_id'] ?? 0),
        'cluster_id' => (int)($seed['cluster_id'] ?? 0),
        'lab_l'      => (float)$seed['lab_l'],
        'lab_a'      => (float)$seed['lab_a'],
        'lab_b'      => (float)$seed['lab_b'],
        'h_r'        => (int)($seed['h_r'] ?? 0),
        'c_r'        => (int)($seed['c_r'] ?? 0),
        'l_r'        => (int)($seed['l_r'] ?? 0),
      ];
    }
  }
  if ($seedClusterId > 0) {
    $seed = cm_resolve_cluster_rep($pdo, $seedClusterId); // should pick a representative color row
    if ($seed && isset($seed['lab_l'],$seed['lab_a'],$seed['lab_b'])) {
      return [
        'color_id'   => (int)($seed['color_id'] ?? 0),
        'cluster_id' => (int)($seed['cluster_id'] ?? $seedClusterId),
        'lab_l'      => (float)$seed['lab_l'],
        'lab_a'      => (float)$seed['lab_a'],
        'lab_b'      => (float)$seed['lab_b'],
        'h_r'        => (int)($seed['h_r'] ?? 0),
        'c_r'        => (int)($seed['c_r'] ?? 0),
        'l_r'        => (int)($seed['l_r'] ?? 0),
      ];
    }
  }

  return null;
}

/**
 * Main function: return truly-close matches across any brand, powered by LAB from colors.
 */
function cf_closest_matches(PDO $pdo, array $opts = []): array {
  $cap   = max(1, (int)($opts['cap'] ?? 10));
  $mode  = (($opts['mode'] ?? 'adaptive') === 'fixed') ? 'fixed' : 'adaptive';
  $maxDe = (float)($opts['max_de'] ?? 3.0);

  $hclOverrides = $opts['hcl_limits'] ?? [];
  $ovr_h = isset($hclOverrides['hue_deg'])   ? (int)$hclOverrides['hue_deg']   : null;
  $ovr_c = isset($hclOverrides['chroma'])    ? (int)$hclOverrides['chroma']    : null;
  $ovr_l = isset($hclOverrides['lightness']) ? (int)$hclOverrides['lightness'] : null;

  // Resolve seed using colors LAB first
  $seed = cfcm_resolve_seed($pdo, $opts);
  if (!$seed) return [];

  $L0 = (float)$seed['lab_l'];
  $a0 = (float)$seed['lab_a'];
  $b0 = (float)$seed['lab_b'];
  $seedCluster = (int)($seed['cluster_id'] ?? 0);
  $seedH = (int)($seed['h_r'] ?? 0);
  $seedC = (int)($seed['c_r'] ?? 0);
  $seedL = (int)($seed['l_r'] ?? 0);

  // Envelope
  if ($mode === 'adaptive') {
    $lim     = cfcm_adaptive_limits($seedC);
    $useDe   = (float)$lim['max_de'];
    $H_LIM   = (int)$lim['hue_deg'];
    $C_LIM   = (int)$lim['chroma'];
    $L_LIM   = (int)$lim['lightness'];
  } else {
    $useDe = $maxDe;
    $H_LIM = 8; $C_LIM = 4; $L_LIM = 4;
  }
  if ($ovr_h !== null) $H_LIM = $ovr_h;
  if ($ovr_c !== null) $C_LIM = $ovr_c;
  if ($ovr_l !== null) $L_LIM = $ovr_l;

  // Neighbor pool (brand-agnostic), DE computed from colors LAB in your helper
  $POOL = min(max($cap * 6, 24), 100);
  $neighbors = cm_neighbors_any_brand($pdo, $L0, $a0, $b0, $POOL, $seedCluster, $useDe);
  if (!$neighbors) return [];

  // Collect cluster ids for HCL checks
  $nCids = [];
  foreach ($neighbors as $nb) {
    $cid = (int)($nb['cluster_id'] ?? 0);
    if ($cid > 0 && $cid !== $seedCluster) $nCids[$cid] = true;
  }
  $nCids = array_values(array_keys($nCids));

  // Fetch HCL + rep_hex for neighbors
  $hcl = [];
  if ($nCids) {
    $ph = implode(',', array_fill(0, count($nCids), '?'));
    $sql = "SELECT id, rep_hex, COALESCE(h_r,0) h_r, COALESCE(c_r,0) c_r, COALESCE(l_r,0) l_r
            FROM clusters WHERE id IN ($ph)";
    $st = $pdo->prepare($sql);
    $st->execute($nCids);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $hcl[(int)$r['id']] = [
        'h' => (int)$r['h_r'],
        'c' => (int)$r['c_r'],
        'l' => (int)$r['l_r'],
        'rep_hex' => ($r['rep_hex'] ?? '') !== '' ? '#'.$r['rep_hex'] : null,
      ];
    }
  }

  // Filter by both DE and tight HCL envelope
  $out = [];
  foreach ($neighbors as $nb) {
    $cid = (int)($nb['cluster_id'] ?? 0);
    if ($cid <= 0 || $cid === $seedCluster) continue;

    $de = (float)($nb['delta_e2000'] ?? 999);
    if ($de > $useDe) continue;

    $hc = $hcl[$cid] ?? null;
    if (!$hc) continue;

    $dh = cfcm_hue_diff_deg($seedH, $hc['h']);
    $dc = abs($seedC - $hc['c']);
    $dl = abs($seedL - $hc['l']);

    $okHue = ($seedC <= 6) ? ($dh <= max(12, $H_LIM)) : ($dh <= $H_LIM);
    if (!$okHue) continue;
    if ($dc > $C_LIM) continue;
    if ($dl > $L_LIM) continue;

    $out[] = [
      'color_id'   => (int)$nb['color_id'],
      'cluster_id' => $cid,
      'brand'      => (string)($nb['brand'] ?? ''),
      'name'       => (string)($nb['name'] ?? ''),
      'rep_hex'    => $hc['rep_hex'] ?? null,
      'delta_e'    => $de,
      'delta_h'    => $dh,
      'delta_c'    => $dc,
      'delta_l'    => $dl,
    ];
  }

usort($out, function($a,$b){
  if ($a['delta_e'] !== $b['delta_e']) return $a['delta_e'] <=> $b['delta_e'];
  if ($a['delta_h'] !== $b['delta_h']) return $a['delta_h'] <=> $b['delta_h'];
  if ($a['delta_c'] !== $b['delta_c']) return $a['delta_c'] <=> $b['delta_c'];
  return $a['cluster_id'] <=> $b['cluster_id'];
});

if ($mode === 'adaptive') {
  // Prefer the natural cluster; parameters tuned to cut around the first jump (~1.6 here)
  $out = cut_by_gap_adaptive($out, [
    'probe'      => 15,     // look a bit deeper before deciding
    'baseline_k' => 3,      // baseline from the first few tiny gaps
    'gap_min'    => 0.35,   // absolute minimum gap that can trigger a cut
    'gap_mult'   => 3.0,    // gap must be ~3× the local baseline to trigger
    'ceiling_de' => 2.2,    // never keep items beyond this ΔE (safety)
    'max_n'      => min(10, $cap),
  ]);
} else {
  // fixed mode just respects ΔE ceiling and cap
  $out = array_values(array_filter($out, fn($r) => (float)$r['delta_e'] <= $maxDe));
  if (count($out) > $cap) $out = array_slice($out, 0, $cap);
}



  // Final hard cap (still keeps it to <= 10 by default)
  if (count($out) > $cap) $out = array_slice($out, 0, $cap);

  return $out;
}

