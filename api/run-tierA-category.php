<?php
/**
 * /api/run-tierA-category.php
 *
 * Run Tier A for every cluster in a category (from clusters.hue_cats / neutral_cats),
 * optionally looping until everything is exhausted — NO pivot IDs required.
 *
 * CLI:
 *   php run-tierA-category.php --category="Greens" --only_pending=1 --loop_until_done=1 --budget_ms=180000
 *   php run-tierA-category.php --category="Whites" --only_pending=1 --batch_limit=100 --max_loops=5
 *
 * HTTP:
 *   /api/run-tierA-category.php?category=Greens&only_pending=1&loop_until_done=1&budget_ms=180000
 */
declare(strict_types=1);

@ignore_user_abort(true);
@set_time_limit(0);
@ini_set('display_errors','0');
@ini_set('memory_limit','1024M');

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) header('Content-Type: application/json; charset=utf-8');

/* ---------- helpers ---------- */
function argenv(string $key, $default=null) {
  static $cli=null;
  if ($cli===null && PHP_SAPI==='cli') {
    $cli=[]; global $argv;
    foreach (array_slice($argv,1) as $a) {
      if (preg_match('/^--?([^=]+)=(.*)$/',$a,$m)) $cli[$m[1]]=$m[2];
      elseif (preg_match('/^--?(.+)$/',$a,$m))     $cli[$m[1]]='1';
    }
  }
  return PHP_SAPI==='cli' ? ($cli[$key]??$default) : ($_POST[$key]??$_GET[$key]??$default);
}
function out_json($arr,int $code=200){
  if(PHP_SAPI!=='cli'){ http_response_code($code); echo json_encode($arr,JSON_UNESCAPED_SLASHES); }
  else { fwrite(STDOUT,json_encode($arr,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL); }
}
function cc_log($msg,$ctx=[]){
  try{
    $f = __DIR__.'/logs/run-tierA-category.log';
    if(!is_dir(dirname($f))) @mkdir(dirname($f),0775,true);
    @error_log(json_encode(['ts'=>date('c'),'msg'=>$msg,'ctx'=>$ctx],JSON_UNESCAPED_SLASHES).PHP_EOL,3,$f);
  }catch(Throwable $e){}
}

/* ---------- DB + function ---------- */
require_once 'db.php'; // must define $pdo

// If your bootstrap already includes generateTierAPalettes(), this will be true.
if (!function_exists('generateTierAPalettes')) {
  // Point this to YOUR real file that defines generateTierAPalettes()
  require_once __DIR__ . '/functions/generateTierAPalettes.php';
}
if (!function_exists('generateTierAPalettes')) {
  out_json(['ok'=>false,'error'=>'generateTierAPalettes() not found. Include your file if bootstrap doesn’t already.'],500);
  exit;
}

/* ---------- params ---------- */
$category       = trim((string)argenv('category',''));
if ($category===''){ out_json(['ok'=>false,'error'=>'Missing category'],400); exit; }

// Plain LIKE pattern (adds % automatically)
$like = "%{$category}%";

$maxK           = max(3,(int)argenv('max_k',6));
$budgetMs       = max(500,(int)argenv('budget_ms',120000));   // bigger default so heavy pivots can finish
$onlyPending    = (int)!!argenv('only_pending',1);            // default: skip already exhausted
$batchLimit     = max(1,(int)argenv('batch_limit',100));      // how many pivots per loop
$loopUntilDone  = (int)!!argenv('loop_until_done',0);         // keep looping until 0 pending
$maxLoops       = max(1,(int)argenv('max_loops',10));         // safety cap on loops
$tierConfig     = "A:max_k={$maxK}";

/* ---------- category WHERE (uses :like1, :like2) ---------- */
$catWhere = "
(
  COALESCE(c.hue_cats,'')     LIKE :like1
  OR
  COALESCE(c.neutral_cats,'') LIKE :like2
)
";

/* ---------- small helpers to query counts and pending lists ---------- */
function count_total_in_category(PDO $pdo, string $catWhere, string $like): int {
  $sql = "SELECT COUNT(*) FROM clusters c WHERE {$catWhere}";
  $st = $pdo->prepare($sql);
  $st->bindValue(':like1', $like, PDO::PARAM_STR);
  $st->bindValue(':like2', $like, PDO::PARAM_STR);
  $st->execute();
  return (int)$st->fetchColumn();
}

function fetch_pending_pivots(PDO $pdo, string $catWhere, string $like, string $tierConfig, int $limit, int $offset=0): array {
  $sql = "
    SELECT DISTINCT c.id
    FROM clusters c
    LEFT JOIN palette_gen_state s
      ON s.pivot_cluster_id = c.id
     AND s.tier_config      = :tc
    WHERE {$catWhere}
      AND COALESCE(s.is_exhausted, 0) = 0
    ORDER BY c.id
    LIMIT :off, :lim
  ";
  $st = $pdo->prepare($sql);
  $st->bindValue(':tc',$tierConfig,PDO::PARAM_STR);
  $st->bindValue(':like1', $like, PDO::PARAM_STR);
  $st->bindValue(':like2', $like, PDO::PARAM_STR);
  $st->bindValue(':off',$offset,PDO::PARAM_INT);
  $st->bindValue(':lim',$limit,PDO::PARAM_INT);
  $st->execute();
  return array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN,0));
}

function count_pending(PDO $pdo, string $catWhere, string $like, string $tierConfig): int {
  $sql = "
    SELECT COUNT(*)
    FROM clusters c
    LEFT JOIN palette_gen_state s
      ON s.pivot_cluster_id = c.id
     AND s.tier_config      = :tc
    WHERE {$catWhere}
      AND COALESCE(s.is_exhausted, 0) = 0
  ";
  $st = $pdo->prepare($sql);
  $st->bindValue(':tc',$tierConfig,PDO::PARAM_STR);
  $st->bindValue(':like1', $like, PDO::PARAM_STR);
  $st->bindValue(':like2', $like, PDO::PARAM_STR);
  $st->execute();
  return (int)$st->fetchColumn();
}

/* ---------- run (with optional loop-until-done) ---------- */
$totalInCat      = count_total_in_category($pdo, $catWhere, $like);
$pendingBefore   = count_pending($pdo, $catWhere, $like, $tierConfig);
$loops           = 0;
$overall = [
  'ok_count'=>0,
  'skipped_count'=>0,
  'cliques_found_total'=>0,
  'palettes_inserted_total'=>0,
  'pivots_needing_rerun'=>[],
  'loop_summaries'=>[]
];

$startAll = microtime(true);

do {
  $loops++;
  $pivots = fetch_pending_pivots($pdo, $catWhere, $like, $tierConfig, $batchLimit, 0);
  if (!$pivots) break;

  $ok=0; $sk=0; $cf=0; $pi=0; $needRerun=[];
  $loopStart = microtime(true);

  foreach ($pivots as $cid) {
    try {
      $res = generateTierAPalettes($pdo, $cid, $maxK, $budgetMs);
      if (!empty($res['ok']))      $ok++;
      if (!empty($res['skipped'])) $sk++;
      $cf += (int)($res['cliques_found']     ?? 0);
      $pi += (int)($res['palettes_inserted'] ?? 0);
      if (isset($res['exhausted']) && !$res['exhausted']) $needRerun[] = $cid;
    } catch (Throwable $e) {
      $needRerun[] = $cid; // try again next loop
    }
  }

  $overall['ok_count']                 += $ok;
  $overall['skipped_count']            += $sk;
  $overall['cliques_found_total']      += $cf;
  $overall['palettes_inserted_total']  += $pi;
  $overall['pivots_needing_rerun']      = array_values(array_unique(array_merge($overall['pivots_needing_rerun'],$needRerun)));

  $pendingNow = count_pending($pdo, $catWhere, $like, $tierConfig);

  $overall['loop_summaries'][] = [
    'loop'         => $loops,
    'ran_pivots'   => $pivots,
    'ok_count'     => $ok,
    'skipped_count'=> $sk,
    'cliques_found'=> $cf,
    'palettes_inserted'=> $pi,
    'needed_rerun' => $needRerun,
    'pending_after'=> $pendingNow,
    'elapsed_ms'   => (int)round((microtime(true)-$loopStart)*1000)
  ];

  if (!$loopUntilDone) break;
  if ($loops >= $maxLoops) break;
  if ($pendingNow === 0) break;

} while (true);

$pendingAfter   = count_pending($pdo, $catWhere, $like, $tierConfig);
$exhaustedAfter = max(0, $totalInCat - $pendingAfter);

$out = [
  'ok'                      => true,
  'category'                => $category,
  'tier_config'             => $tierConfig,
  'loops_run'               => $loops,
  'batch_limit'             => $batchLimit,
  'budget_ms_per_pivot'     => $budgetMs,
  'pending_before'          => $pendingBefore,
  'pending_after'           => $pendingAfter,
  'exhausted_after'         => $exhaustedAfter,
  'total_in_category'       => $totalInCat,
  'rerun_recommended'       => ($pendingAfter > 0),
  'ok_count'                => $overall['ok_count'],
  'skipped_count'           => $overall['skipped_count'],
  'cliques_found_total'     => $overall['cliques_found_total'],
  'palettes_inserted_total' => $overall['palettes_inserted_total'],
  'pivots_needing_rerun_sample' => array_slice($overall['pivots_needing_rerun'], 0, 20),
  'loop_summaries'          => $overall['loop_summaries'],
  'elapsed_ms'              => (int)round((microtime(true)-$startAll)*1000)
];

out_json($out, 200);
