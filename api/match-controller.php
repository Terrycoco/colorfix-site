<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0'); ini_set('log_errors','1');

require_once 'db.php';
require_once __DIR__ . '/functions/colorMatch.php';

try {
  $source_id    = isset($_GET['source_id'])   ? (int)$_GET['source_id']   : 0;
  $source_hex   = isset($_GET['source_hex'])  ? strtoupper(trim((string)$_GET['source_hex'])) : '';
  $brand        = isset($_GET['brand'])       ? trim((string)$_GET['brand']) : '';
  $name         = isset($_GET['name'])        ? trim((string)$_GET['name'])  : '';
  $name_mode    = isset($_GET['name_mode'])   ? trim((string)$_GET['name_mode']) : 'exact';
  $target_brand = isset($_GET['target_brand'])? trim((string)$_GET['target_brand']) : '';

  // Resolve + twins (we won't show twins in the list; only need source + lab)
  $base = colorMatch($pdo, [
    'source_id'    => $source_id,
    'source_hex'   => $source_hex,
    'brand'        => $brand,
    'name'         => $name,
    'name_mode'    => $name_mode,
    'target_brand' => $target_brand ?: null,
    'limit'        => 12,
  ]);
  if (empty($base['ok'])) { echo json_encode($base); exit; }

  $src    = $base['source'];
  $srcId  = (int)$src['color_id'];
  $lab    = $src['lab'];

  // One best per brand (twin if exists, else nearest), excludes 'true'
  $best = cm_best_per_brand_all(
    $pdo,
    (int)$src['cluster_id'],
    (float)$lab['L'], (float)$lab['a'], (float)$lab['b'],
    null
  );

  // Collect ids to hydrate; drop the exact source color and any calibration brand
  $bestIds = [];
  foreach ($best as $b) {
    if ((int)$b['color_id'] === $srcId) continue;
    if (strtolower((string)$b['brand']) === 'true') continue;
    $bestIds[] = (int)$b['color_id'];
  }
  $bestIds = array_values(array_unique(array_filter($bestIds)));

  // Hydrate from swatch_enriched using ONLY named placeholders
// ---- hydrate swatches (safe positional placeholders; no extra binds) ----
$svById = [];
$bestIds = array_values(array_unique(array_filter($bestIds, fn($x)=>is_numeric($x) && $x > 0)));

if ($bestIds) {
  $placeholders = implode(',', array_fill(0, count($bestIds), '?'));
  $sql = "SELECT * FROM swatch_enriched WHERE id IN ($placeholders)";

  $stmt = $pdo->prepare($sql);

  // Ensure purely positional params (0..N-1)
  $params = array_values(array_map('intval', $bestIds));

  // Optional sanity log if it ever mismatches
  if (substr_count($sql, '?') !== count($params)) {
    error_log("match-controller hydrate mismatch: ph=" . substr_count($sql,'?') . " params=" . count($params));
  }

  try {
    $stmt->execute($params);
  } catch (Throwable $e) {
    error_log("hydrate execute failed: " . $e->getMessage());
    throw $e;
  }

  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // filter calibration rows in PHP (no extra SQL placeholders = no mismatch risk)
    if (isset($r['brand']) && strtolower((string)$r['brand']) === 'true') continue;
    $svById[(int)$r['id']] = $r;
  }
}


  // Shape for Gallery: single group “Closest by Brand”
  $items = [];
  foreach ($best as $b) {
    $cid = (int)$b['color_id'];
    if ($cid === $srcId) continue;
    if (!isset($svById[$cid])) continue;
    if (strtolower((string)$b['brand']) === 'true') continue;

    $items[] = [
      'group_header' => 'Closest by Brand',
      'group_order'  => 1,
      'delta_e'      => (float)$b['delta_e'],
      'is_twin'      => (bool)$b['is_twin'],
      'color'        => $svById[$cid],
    ];
  }

  echo json_encode([
    'ok'     => true,
    'source' => $src,
    'items'  => $items,
  ], JSON_UNESCAPED_SLASHES) . PHP_EOL;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
