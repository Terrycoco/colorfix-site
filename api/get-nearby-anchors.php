<?php
// /api/get-nearby-anchors.php
declare(strict_types=1);

/**
 * Returns a tiny “neighbors_preview” for the first provided color id,
 * using cf_closest_matches() (true LAB-based proximity, brand-agnostic).
 *
 * Input (query or JSON body):
 *   - ids: array|csv of color_ids (first one is used as the seed)
 *   - cap: int    (default 3)    -> how many neighbors to preview
 *   - mode: 'fixed'|'adaptive'    default 'fixed'
 *   - max_de: float (used in fixed mode, default 3.0)
 *   - hue_deg, chroma, lightness (optional ints to override HCL envelope)
 *
 * Output:
 *   {
 *     "seed": {
 *       "color_id": <int>,
 *       "cluster_id": <int>|null
 *     },
 *     "neighbors_preview": [
 *       { "color_id":..., "cluster_id":..., "brand":"...", "name":"...", "rep_hex":"#rrggbb", "delta_e":2.1, "delta_h":6, "delta_c":1, "delta_l":2 },
 *       ...
 *     ]
 *   }
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(200);
  exit;
}

require_once 'db.php'; // provides $pdo
header('Content-Type: application/json; charset=utf-8');

const GNA_LOG = __DIR__ . '/logs/get-nearby-anchors.log';
function gna_log($lvl, $msg, $ctx = []) {
  $dir = dirname(GNA_LOG);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @error_log(json_encode(['ts'=>date('c'),'lvl'=>$lvl,'msg'=>$msg,'ctx'=>$ctx], JSON_UNESCAPED_SLASHES) . PHP_EOL, 3, GNA_LOG);
}

// Friendly, array-only errors (doesn’t crash caller flows)
set_error_handler(function($sev,$msg,$file,$line){
  gna_log('php_error', $msg, ['file'=>$file,'line'=>$line,'severity'=>$sev]);
  http_response_code(200);
  echo json_encode(['seed'=>null, 'neighbors_preview'=>[]], JSON_UNESCAPED_SLASHES);
  exit;
});
set_exception_handler(function($e){
  gna_log('php_exception', $e->getMessage(), ['trace'=>$e->getTraceAsString()]);
  http_response_code(200);
  echo json_encode(['seed'=>null, 'neighbors_preview'=>[]], JSON_UNESCAPED_SLASHES);
  exit;
});

// ---------- helpers ----------
function gna_read_json(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}
function gna_param_ids(): array {
  $ids = [];
  if (isset($_GET['ids'])) {
    $ids = is_array($_GET['ids']) ? $_GET['ids'] : preg_split('/\s*,\s*/', (string)$_GET['ids'], -1, PREG_SPLIT_NO_EMPTY);
  }
  $j = gna_read_json();
  if (!$ids && isset($j['ids'])) {
    $ids = is_array($j['ids']) ? $j['ids'] : preg_split('/\s*,\s*/', (string)$j['ids'], -1, PREG_SPLIT_NO_EMPTY);
  }
  $ids = array_values(array_unique(array_map(fn($v)=>(int)$v, $ids)));
  return array_values(array_filter($ids, fn($v)=>$v>0));
}
function gna_int($arr, $k, $def) {
  return isset($arr[$k]) ? (int)$arr[$k] : (isset($_GET[$k]) ? (int)$_GET[$k] : $def);
}
function gna_float($arr, $k, $def) {
  return isset($arr[$k]) ? (float)$arr[$k] : (isset($_GET[$k]) ? (float)$_GET[$k] : $def);
}
function gna_str($arr, $k, $def) {
  return isset($arr[$k]) ? (string)$arr[$k] : (isset($_GET[$k]) ? (string)$_GET[$k] : $def);
}

// ---------- input ----------
$body = gna_read_json();
$ids  = gna_param_ids();

$cap   = max(1, gna_int($body, 'cap', 3));
$mode  = strtolower(gna_str($body, 'mode', 'fixed'));
if ($mode !== 'adaptive' && $mode !== 'fixed') $mode = 'fixed';
$maxDe = gna_float($body, 'max_de', 3.0);

// optional HCL overrides
$hue_deg   = gna_int($body, 'hue_deg',   null);
$chromaLim = gna_int($body, 'chroma',    null);
$lightLim  = gna_int($body, 'lightness', null);

if (!$ids) {
  echo json_encode(['seed'=>null, 'neighbors_preview'=>[]], JSON_UNESCAPED_SLASHES);
  exit;
}

$seedColorId = $ids[0]; // conservative: first selected color as seed

// ---------- include closestMatch ----------
require_once __DIR__ . '/functions/closestMatch.php';

// ---------- resolve seed cluster (for the tiny seed payload only) ----------
$seed = null;
try {
  $st = $pdo->prepare("SELECT c.id AS color_id, c.cluster_id FROM colors c WHERE c.id=? LIMIT 1");
  $st->execute([$seedColorId]);
  $seed = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  gna_log('warn', 'seed lookup failed', ['msg'=>$e->getMessage()]);
}

$opts = [
  'seed_color_id' => $seedColorId,
  'cap'           => $cap,
  'mode'          => $mode,   // 'fixed' or 'adaptive'
  'max_de'        => $maxDe,  // used only when mode='fixed'
];

$hcl = [];
if ($hue_deg !== null)   $hcl['hue_deg']   = $hue_deg;
if ($chromaLim !== null) $hcl['chroma']    = $chromaLim;
if ($lightLim  !== null) $hcl['lightness'] = $lightLim;
if ($hcl) $opts['hcl_limits'] = $hcl;

// ---------- compute neighbors preview ----------
$preview = [];
try {
  $preview = cf_closest_matches($pdo, $opts);
} catch (Throwable $e) {
  gna_log('error', 'closest_matches exception', ['msg'=>$e->getMessage(), 'opts'=>$opts]);
  $preview = [];
}

// ---------- shape + emit ----------
echo json_encode([
  'seed' => $seed ? [
    'color_id'   => (int)$seed['color_id'],
    'cluster_id' => isset($seed['cluster_id']) ? (int)$seed['cluster_id'] : null
  ] : null,
  'neighbors_preview' => $preview, // ready for your MiniSwatch row (brand, name, rep_hex, delta_e)
], JSON_UNESCAPED_SLASHES);
