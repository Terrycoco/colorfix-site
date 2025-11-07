<?php
/**
 * /api/analysis/analysis-clusters.php
 * Cluster-level summary (no per-edge rows).
 */

declare(strict_types=1);
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
ini_set('display_errors','0');
error_reporting(E_ALL);
set_time_limit(25);

require_once 'db.php';

if (isset($_GET['ping'])) {
  ob_end_clean();
  echo json_encode(['ok'=>true,'file'=>'analysis-clusters.php'], JSON_UNESCAPED_SLASHES);
  exit;
}

const LOGF = __DIR__ . '/logs/analysis-clusters.log';
@is_dir(dirname(LOGF)) || @mkdir(dirname(LOGF), 0775, true);
function alog(string $lvl, string $msg, array $ctx=[]): void {
  @error_log(json_encode(['ts'=>date('c'),'lvl'=>$lvl,'msg'=>$msg,'ctx'=>$ctx], JSON_UNESCAPED_SLASHES) . PHP_EOL, 3, LOGF);
}

/* inputs */
$anchorType  = isset($_GET['anchor_type']) ? trim((string)$_GET['anchor_type']) : 'neutral';
$anchorValue = isset($_GET['anchor_value']) ? trim((string)$_GET['anchor_value']) : 'Blacks';
$minCount    = isset($_GET['min_count']) ? max(1, (int)$_GET['min_count']) : 1;
$sortParam   = isset($_GET['sort']) ? trim((string)$_GET['sort']) : 'support_desc';
$page        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limitIn     = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
$limit       = max(25, min(500, $limitIn));
$offset      = ($page - 1) * $limit;

$anchorCol  = ($anchorType === 'hue') ? 'a.hue_cats' : 'a.neutral_cats';
$anchorLike = '%'.$anchorValue.'%';

/* sort options */
$sortMap = [
  'support_desc'  => 't.total_w DESC, a.h_r ASC, a.c_r ASC, a.l_r ASC',
  'support_asc'   => 't.total_w ASC,  a.h_r ASC, a.c_r ASC, a.l_r ASC',

  'partners_desc' => 'COALESCE(p.partners,0) DESC, t.total_w DESC',
  'partners_asc'  => 'COALESCE(p.partners,0) ASC,  t.total_w DESC',

  'a_h_asc'       => 'a.h_r ASC,  a.c_r ASC, a.l_r ASC',
  'a_h_desc'      => 'a.h_r DESC, a.c_r ASC, a.l_r ASC',

  'a_c_asc'       => 'a.c_r ASC,  a.h_r ASC, a.l_r ASC',
  'a_c_desc'      => 'a.c_r DESC, a.h_r ASC, a.l_r ASC',

  'a_l_asc'       => 'a.l_r ASC,  a.h_r ASC',
  'a_l_desc'      => 'a.l_r DESC, a.h_r ASC',
];

$orderBy = $sortMap[$sortParam] ?? $sortMap['support_desc'];

/* representative hex per cluster */
$repHexSub = "SELECT cluster_id, MIN(hex6) AS rep_hex6 FROM cluster_hex GROUP BY cluster_id";

/* ---- subqueries with UNIQUE placeholders each time ---- */
/* path #1 (totals) */
$edges1 = "
  SELECT cu.c_from AS a_id, cu.c_to AS b_id, COALESCE(cu.weight,1) AS w
  FROM cluster_union cu
  JOIN clusters a ON a.id = cu.c_from
  WHERE {$anchorCol} LIKE :av1
    AND COALESCE(cu.weight,1) >= :mc1
";
$fam1 = "
  SELECT e.a_id, b.neutral_cats AS family, SUM(e.w) AS w
  FROM ( {$edges1} ) e
  JOIN clusters b ON b.id = e.b_id
  GROUP BY e.a_id, b.neutral_cats
";
$totals1 = "
  SELECT a_id, SUM(w) AS total_w
  FROM ( {$fam1} ) f
  GROUP BY a_id
";

/* path #2 (partners) */
$edges2 = "
  SELECT cu.c_from AS a_id, cu.c_to AS b_id, COALESCE(cu.weight,1) AS w
  FROM cluster_union cu
  JOIN clusters a ON a.id = cu.c_from
  WHERE {$anchorCol} LIKE :av2
    AND COALESCE(cu.weight,1) >= :mc2
";
$partners2 = "
  SELECT e.a_id, COUNT(DISTINCT e.b_id) AS partners
  FROM ( {$edges2} ) e
  GROUP BY e.a_id
";

/* path #3 (tops) */
$edges3 = "
  SELECT cu.c_from AS a_id, cu.c_to AS b_id, COALESCE(cu.weight,1) AS w
  FROM cluster_union cu
  JOIN clusters a ON a.id = cu.c_from
  WHERE {$anchorCol} LIKE :av3
    AND COALESCE(cu.weight,1) >= :mc3
";
$fam3 = "
  SELECT e.a_id, b.neutral_cats AS family, SUM(e.w) AS w
  FROM ( {$edges3} ) e
  JOIN clusters b ON b.id = e.b_id
  GROUP BY e.a_id, b.neutral_cats
";
$tops3 = "
  SELECT f1.a_id,
         GROUP_CONCAT(f1.family ORDER BY f1.w DESC SEPARATOR '|') AS fams,
         GROUP_CONCAT(f1.w      ORDER BY f1.w DESC SEPARATOR '|') AS ws
  FROM ( {$fam3} ) f1
  GROUP BY f1.a_id
";

/* page query */
$sqlPage = "
  SELECT
    a.id AS anchor_cluster_id,
    ahex.rep_hex6 AS anchor_hex6,
    a.h_r AS a_h, a.c_r AS a_c, a.l_r AS a_l,
    a.neutral_cats AS a_neutral_cats,
    a.hue_cats     AS a_hue_cats,
    t.total_w      AS total_support,
    COALESCE(p.partners,0) AS partner_clusters,
    tops.fams      AS top_families,
    tops.ws        AS top_weights
  FROM ( {$totals1} ) t
  JOIN clusters a           ON a.id = t.a_id
  LEFT JOIN ( {$partners2} ) p ON p.a_id = t.a_id
  LEFT JOIN ( {$tops3} ) tops  ON tops.a_id = t.a_id
  LEFT JOIN ( {$repHexSub} ) ahex ON ahex.cluster_id = a.id
  ORDER BY {$orderBy}
  LIMIT :limit OFFSET :offset
";

/* count query — separate placeholders again */
$edges4 = "
  SELECT cu.c_from AS a_id, cu.c_to AS b_id, COALESCE(cu.weight,1) AS w
  FROM cluster_union cu
  JOIN clusters a ON a.id = cu.c_from
  WHERE {$anchorCol} LIKE :av4
    AND COALESCE(cu.weight,1) >= :mc4
";
$fam4 = "
  SELECT e.a_id, b.neutral_cats AS family, SUM(e.w) AS w
  FROM ( {$edges4} ) e
  JOIN clusters b ON b.id = e.b_id
  GROUP BY e.a_id, b.neutral_cats
";
$totals4 = "
  SELECT a_id, SUM(w) AS total_w
  FROM ( {$fam4} ) f
  GROUP BY a_id
";
$sqlCount = "SELECT COUNT(*) FROM ( {$totals4} ) x";

try {
  alog('info','clusters start', [
    'anchor_type'=>$anchorType,'anchor_value'=>$anchorValue,
    'min_count'=>$minCount,'sort'=>$sortParam,'page'=>$page,'limit'=>$limit
  ]);

  /* PAGE */
  $stmt = $pdo->prepare($sqlPage);
  $stmt->bindValue(':av1', $anchorLike, \PDO::PARAM_STR);
  $stmt->bindValue(':mc1', $minCount,   \PDO::PARAM_INT);
  $stmt->bindValue(':av2', $anchorLike, \PDO::PARAM_STR);
  $stmt->bindValue(':mc2', $minCount,   \PDO::PARAM_INT);
  $stmt->bindValue(':av3', $anchorLike, \PDO::PARAM_STR);
  $stmt->bindValue(':mc3', $minCount,   \PDO::PARAM_INT);
  $stmt->bindValue(':limit',  $limit,   \PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

  /* COUNT */
  $stmt2 = $pdo->prepare($sqlCount);
  $stmt2->bindValue(':av4', $anchorLike, \PDO::PARAM_STR);
  $stmt2->bindValue(':mc4', $minCount,   \PDO::PARAM_INT);
  $stmt2->execute();
  $total = (int)($stmt2->fetchColumn() ?: 0);

  $items = array_map(function(array $r): array {
    foreach (['a_h','a_c','a_l','total_support','partner_clusters'] as $k) {
      if (isset($r[$k]) && $r[$k] !== '') $r[$k] = $r[$k]+0;
    }
    return $r;
  }, $rows);

  $payload = [
    'items' => $items,
    'meta'  => ['page'=>$page,'limit'=>$limit,'total_items'=>$total]
  ];

  $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
  ob_end_clean();
  echo $json;
  exit;

} catch (\Throwable $e) {
  alog('error','clusters fail', ['err'=>$e->getMessage()]);
  http_response_code(500);
  $err = json_encode(['error'=>'Internal error','detail'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
  ob_end_clean();
  echo $err;
  exit;
}
