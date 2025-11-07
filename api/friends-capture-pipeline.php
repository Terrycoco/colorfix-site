<?php
declare(strict_types=1);

// CORS preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=UTF-8');
@ini_set('display_errors','0');
@ini_set('memory_limit','1024M');
@set_time_limit(0);

// simple logger that writes NEXT TO THIS FILE and mirrors to PHP error_log
if (!function_exists('plog')) {
  function plog($msg, $ctx = []) {
    $line = json_encode(['ts'=>date('c'),'msg'=>$msg,'ctx'=>$ctx], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $f = @fopen('friends-capture-pipeline.log', 'a');   // same folder as this script
    if ($f) { @fwrite($f, $line); @fclose($f); }
    @error_log('[pipeline] ' . trim($line));
  }
}
plog('boot');

// Simple errors -> JSON, and mirror to error_log
set_error_handler(function($sev,$msg,$file,$line){
  @error_log("[pipeline] PHP error: $msg at $file:$line");
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>"PHP error: $msg",'at'=>"$file:$line"]);
  exit;
});
set_exception_handler(function($e){
  @error_log("[pipeline] Exception: ".$e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  exit;
});

// ---------- PLAIN RELATIVE REQUIRES (file must sit in /api/) ----------
require_once 'db.php';
require_once 'functions/captureFriends.php';
require_once 'functions/refreshClusterEdgesTargeted.php';
require_once 'functions/generateTierAPalettes.php';

// (^^^ DO NOT PUT ANY OTHER CHARACTERS HERE. The dashed line you had caused a parse error.)

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Input
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Use POST']);
  plog('reject.non_post');
  exit;
}

$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Invalid JSON']);
  plog('reject.bad_json', ['raw_first_200'=>substr($raw,0,200)]);
  exit;
}

$ids = $in['ids'] ?? [];
if (is_string($ids)) $ids = array_map('intval', array_filter(array_map('trim', explode(',', $ids))));
else                 $ids = array_values(array_unique(array_map('intval', (array)$ids)));
if (count($ids) < 2) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Need 2+ ids']);
  plog('reject.too_few_ids', ['ids'=>$ids]);
  exit;
}

// Stage switch (optional): ?stage=capture|cluster|tiera|all
$stage = isset($_GET['stage']) ? strtolower((string)$_GET['stage']) : 'all';
if (!in_array($stage, ['capture','cluster','tiera','all'], true)) $stage = 'all';

// 1) Capture color friendships
plog('capture.start', ['ids'=>$ids]);
$capture = captureFriends($pdo, $ids);
plog('capture.done', $capture);
if (empty($capture['ok'])) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'stage'=>'capture','detail'=>$capture]);
  plog('capture.fail', $capture);
  exit;
}
if ($stage === 'capture') {
  echo json_encode(['ok'=>true,'stage'=>'capture','capture'=>$capture]);
  exit;
}

// 2) Resolve touched clusters
$ph = implode(',', array_fill(0, count($ids), '?'));
$st = $pdo->prepare("SELECT DISTINCT cluster_id FROM colors WHERE id IN ($ph) AND cluster_id IS NOT NULL");
foreach ($ids as $i=>$id) $st->bindValue($i+1, $id, PDO::PARAM_INT);
$st->execute();
$clustersTouched = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN, 0));
plog('clusters.touched', ['clusters'=>$clustersTouched]);

if (!$clustersTouched) {
  echo json_encode([
    'ok'=>true,'stage'=>'cluster',
    'capture'=>$capture,
    'clusters_touched'=>[],
    'cluster_refresh'=>['ok'=>true,'note'=>'no clusters resolved from ids'],
    'tierA'=>[]
  ]);
  plog('clusters.none');
  exit;
}

// 3) Targeted refresh of cluster_friends for only touched clusters
plog('cluster_refresh.start', ['clusters'=>$clustersTouched]);
$clusterRefresh = refreshClusterEdgesTargeted($pdo, $clustersTouched);
plog('cluster_refresh.done', $clusterRefresh);
if (empty($clusterRefresh['ok'])) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'stage'=>'cluster','detail'=>$clusterRefresh]);
  plog('cluster_refresh.fail', $clusterRefresh);
  exit;
}
if ($stage === 'cluster') {
  echo json_encode([
    'ok'=>true,'stage'=>'cluster',
    'capture'=>$capture,
    'clusters_touched'=>$clustersTouched,
    'cluster_refresh'=>$clusterRefresh,
    'tierA'=>[]
  ]);
  exit;
}

// 4) Clear exhaustion + regenerate Tier A palettes for touched clusters
$clear = $pdo->prepare("UPDATE palette_gen_state SET is_exhausted = 0 WHERE pivot_cluster_id = :cid AND tier_config LIKE 'A:%'");
$MAX_PER_RUN = 12;   // cap clusters per request
$BUDGET_MS   = 2500; // per-cluster time budget
$MAX_K       = 6;

$runList = array_slice($clustersTouched, 0, $MAX_PER_RUN);
plog('tierA.start', ['run_count'=>count($runList), 'budget_ms'=>$BUDGET_MS]);

$paletteResults = [];
foreach ($runList as $cid) {
  try {
    $clear->execute([':cid'=>$cid]);
    $paletteResults[$cid] = generateTierAPalettes($pdo, $cid, $MAX_K, $BUDGET_MS);
  } catch (Throwable $e) {
    $paletteResults[$cid] = ['ok'=>false, 'error'=>$e->getMessage()];
  }
}

plog('tierA.done', ['ran'=>array_keys($paletteResults)]);

// Done
echo json_encode([
  'ok' => true,
  'stage' => 'all',
  'capture' => $capture,
  'clusters_touched' => $clustersTouched,
  'cluster_refresh' => $clusterRefresh,
  'tierA' => $paletteResults
]);
