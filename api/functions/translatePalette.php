<?php
declare(strict_types=1);

require_once __DIR__ . '/colorMatch.php';

/**
 * Back-compat entry: translate by palette_id.
 */
function translatePalette(PDO $pdo, int $paletteId, array $brands = []): array {
  if ($paletteId <= 0) return ['ok'=>false,'error'=>'Invalid palette_id'];

  // Get unique member clusters for the given palette
  $st = $pdo->prepare("SELECT member_cluster_id AS cluster_id FROM palette_members WHERE palette_id = :pid");
  $st->execute([':pid'=>$paletteId]);
  $cids = [];
  while ($cid = $st->fetchColumn()) { $cid = (int)$cid; if ($cid>0) $cids[$cid] = true; }
  $clusterIds = array_keys($cids);
  if (!$clusterIds) return ['ok'=>false,'error'=>'No members found for palette_id'];

  return translatePaletteCore($pdo, $clusterIds, $brands);
}

/**
 * NEW entry: translate from a list of cluster_ids (user-built palette).
 */
function translatePaletteFromClusters(PDO $pdo, array $clusterIds, array $brands = []): array {
  // sanitize + dedupe
  $uniq = [];
  foreach ($clusterIds as $cid) { $cid = (int)$cid; if ($cid>0) $uniq[$cid] = true; }
  $clusterIds = array_keys($uniq);
  if (!$clusterIds) return ['ok'=>false,'error'=>'Missing or invalid cluster_ids'];

  return translatePaletteCore($pdo, $clusterIds, $brands);
}

/**
 * Core implementation shared by both entry points.
 * - Resolves brand list (default = all brands except 'true' calibration)
 * - Picks a representative swatch per cluster (prefer non-stain)
 * - Orders reps by chroma desc, then lightness desc
 * - For each rep, finds best per brand (via cm_best_per_brand_all)
 * - Enforces completeness (drop brands that cannot fill every source slot)
 */
function translatePaletteCore(PDO $pdo, array $clusterIds, array $brands = []): array {
  // ----- Resolve brands (default: all, excluding calibration 'true') -----
  if (!$brands) {
    $brands = [];
    $q = $pdo->query("SELECT DISTINCT brand FROM swatch_view WHERE LOWER(brand) <> 'true' ORDER BY brand");
    while ($b = $q->fetchColumn()) {
      $b = trim((string)$b);
      if ($b !== '') $brands[] = $b;
    }
    if (!$brands) return ['ok'=>false,'error'=>'No brands found in swatch_view'];
  }
  // stable + case-insens dedupe
  $seen   = [];
  $ordered= [];
  foreach ($brands as $b) { $k = mb_strtolower(trim((string)$b)); if ($k !== '' && !isset($seen[$k])) { $seen[$k]=1; $ordered[]=$b; } }
  $orderMap = [];
  foreach ($ordered as $i=>$b) { $orderMap[mb_strtolower($b)] = $i+1; }

  $srcCount = count($clusterIds);

  // ----- Representative color per cluster (prefer non-stain) -----
  $ph = implode(',', array_fill(0, count($clusterIds), '?'));
  $repSql = "
    SELECT cluster_id, COALESCE(MIN(CASE WHEN is_stain=0 THEN id END), MIN(id)) AS rep_id
    FROM swatch_view
    WHERE cluster_id IN ($ph)
    GROUP BY cluster_id
  ";
  $repStmt = $pdo->prepare($repSql);
  $repStmt->execute($clusterIds);
  $repMap = $repStmt->fetchAll(PDO::FETCH_KEY_PAIR); // cluster_id => rep_id
  if (!$repMap) return ['ok'=>false,'error'=>'No representative swatches found for clusters'];

  // Order reps by chroma desc, then lightness desc (Inspector order)
  $repIds = array_values(array_map('intval', array_values($repMap)));
  $repOrder = [];
  if ($repIds) {
    $phReps = implode(',', array_fill(0, count($repIds), '?'));
    $st2 = $pdo->prepare("SELECT id, hcl_c, hcl_l FROM swatch_view WHERE id IN ($phReps)");
    $st2->execute($repIds);
    $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
    usort($rows, function($a,$b){
      $ca=(float)($a['hcl_c']??0); $cb=(float)($b['hcl_c']??0);
      if ($ca !== $cb) return $cb <=> $ca;
      $la=(float)($a['hcl_l']??0); $lb=(float)($b['hcl_l']??0);
      if ($la !== $lb) return $lb <=> $la;
      return (int)$a['id'] <=> (int)$b['id'];
    });
    $i=1; foreach ($rows as $r) { $repOrder[(int)$r['id']] = $i++; }
  }

  // ----- For each rep, run colorMatch and pick best per brand -----
  $pairsByBrand = [];     // brand => [ [src_id, best_id, de, is_twin, src_order], ... ]
  $hydrateIds   = [];
  $failures     = [];

  foreach ($repMap as $clusterId => $srcId) {
    $srcId = (int)$srcId;

    $base = colorMatch($pdo, [
      'source_id'    => $srcId,
      'source_hex'   => '',
      'brand'        => '',
      'name'         => '',
      'name_mode'    => 'exact',
      'target_brand' => null,
      'limit'        => 12,
    ]);
    if (empty($base['ok'])) {
      $failures[] = ['src_id'=>$srcId,'reason'=>$base['error'] ?? 'colorMatch failed'];
      continue;
    }
    $src = $base['source'];
    if (empty($src['lab']) || !isset($src['lab']['L'],$src['lab']['a'],$src['lab']['b'])) {
      $failures[] = ['src_id'=>$srcId,'reason'=>'Missing LAB'];
      continue;
    }
    $lab = $src['lab'];
    $clusterId = (int)$src['cluster_id'];

    $allBest = cm_best_per_brand_all($pdo, $clusterId, (float)$lab['L'], (float)$lab['a'], (float)$lab['b'], null);

    // brand → best pick
    $byBrand = [];
    foreach ($allBest as $b) { $byBrand[mb_strtolower((string)$b['brand'])] = $b; }

    $srcOrder = $repOrder[$srcId] ?? 9999;

    foreach ($ordered as $brand) {
      $k = mb_strtolower($brand);
      if (!isset($byBrand[$k])) { $failures[] = ['src_id'=>$srcId,'brand'=>$brand,'reason'=>'No best match']; continue; }
      $pick   = $byBrand[$k];
      $bestId = (int)$pick['color_id'];
      if ($bestId <= 0) continue;

      $pairsByBrand[$brand][] = [$srcId, $bestId, (float)$pick['delta_e'], (bool)$pick['is_twin'], (int)$srcOrder];
      $hydrateIds[] = $bestId;
    }
  }

  // ----- Hydrate chosen colors from swatch_view -----
// ----- Hydrate chosen colors from swatch_view -----
$hydrateIds = array_values(array_unique(array_filter($hydrateIds, fn($x)=>is_numeric($x)&&$x>0)));
$sw = [];
if ($hydrateIds) {
  $ph2 = implode(',', array_fill(0, count($hydrateIds), '?'));

  // EXPLICIT columns, force r/g/b from swatch_view
  $sql = "
    SELECT
     *
    FROM swatch_view
    WHERE id IN ($ph2)
  ";

  $st3 = $pdo->prepare($sql);
  $st3->execute(array_values(array_map('intval', $hydrateIds)));

  while ($r = $st3->fetch(PDO::FETCH_ASSOC)) {
    // skip calibration brand 'true'
    if (isset($r['brand']) && strtolower((string)$r['brand']) === 'true') continue;
    // ensure ints for rgb (PaletteSwatch uses rgb(...))
    $r['r'] = (int)($r['r'] ?? 0);
    $r['g'] = (int)($r['g'] ?? 0);
    $r['b'] = (int)($r['b'] ?? 0);
    $sw[(int)$r['id']] = $r;
  }
}


  // ----- Build items with de-dup + completeness enforcement -----
  $items = [];
  $keptBrands = [];

  foreach ($ordered as $brand) {
    $ord   = $orderMap[mb_strtolower($brand)] ?? 9999;
    $pairs = $pairsByBrand[$brand] ?? [];

    // de-dup within brand by best color id and ensure 1 result per source color
    $seenBest = [];
    $seenSrc  = [];
    $rows     = [];

    foreach ($pairs as [$srcId, $bestId, $de, $twin, $srcOrder]) {
      if (!isset($sw[$bestId])) continue;
      if (isset($seenBest[$bestId])) continue; // drop duplicate color in this brand
      if (isset($seenSrc[$srcId]))  continue;  // safety: one pick per source color

      $seenBest[$bestId] = 1;
      $seenSrc[$srcId]   = 1;

      $row = $sw[$bestId];
      $rows[] = [
        'brand_name' => (string)($row['brand_name'] ?? $row['brand'] ?? $brand),
        'group_order'=> (int)$ord,
        'src_order'  => (int)$srcOrder,
        'delta_e'    => (float)$de,
        'is_twin'    => $twin ? 1 : 0,
        'color'      => $row,
      ];
    }

    // Only keep the brand if it has a COMPLETE set (same size as source palette)
    if (count($rows) === $srcCount) {
      usort($rows, fn($a,$b) => ($a['src_order'] <=> $b['src_order']) ?: ((int)$a['color']['id'] <=> (int)$b['color']['id']));
      foreach ($rows as $r) $items[] = $r;
      $keptBrands[] = $brand;
    }
  }

  // Final sort: by group, then source order
  usort($items, function($a,$b){
    $goA = (int)($a['group_order'] ?? 9999);
    $goB = (int)($b['group_order'] ?? 9999);
    if ($goA !== $goB) return $goA <=> $goB;
    $soA = (int)($a['src_order'] ?? 9999);
    $soB = (int)($b['src_order'] ?? 9999);
    if ($soA !== $soB) return $soA <=> $soB;
    return (int)($a['color']['id'] ?? 0) <=> (int)($b['color']['id'] ?? 0);
  });

  return [
    'ok'         => true,
    'brands'     => $keptBrands,   // only brands that passed completeness
    'src_count'  => $srcCount,     // size of the source palette
    'items'      => $items,
    'failures'   => $failures,
  ];
}
