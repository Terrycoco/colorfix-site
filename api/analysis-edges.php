<?php
/**
 * /api/analysis/analysis-edges.php
 *
 * Cluster→cluster edges (one row per directional pair).
 * Includes rep hexes, rounded H/C/L, categories, ΔH/ΔC/ΔL,
 * and a count = COALESCE(true_count_from_friends, cu.weight).
 *
 * Query params:
 *   anchor_type  = 'neutral' | 'hue'        (default 'neutral')
 *   anchor_value = e.g. 'Blacks'            (default 'Blacks')
 *   min_count    = minimum count             (default 1)
 *   sort         = count_desc | delta_h_abs_desc | delta_c_desc | delta_l_desc | a_h_asc | b_h_asc
 *   page         = 1..                       (default 1)
 *   limit        = 25..500                   (default 200)
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

/* ping */
if (isset($_GET['ping'])) {
  ob_end_clean();
  echo json_encode(['ok'=>true,'file'=>'analysis-edges.php'], JSON_UNESCAPED_SLASHES);
  exit;
}

/* tiny logger (optional, safe if folder missing) */
const AE_LOG = __DIR__ . '/logs/analysis-edges.log';
@is_dir(dirname(AE_LOG)) || @mkdir(dirname(AE_LOG), 0775, true);
function ae_log(string $lvl, string $msg, array $ctx = []): void {
  @error_log(json_encode([
    'ts'=>date('c'),'lvl'=>$lvl,'msg'=>$msg,'ctx'=>$ctx,
    'uri'=>$_SERVER['REQUEST_URI'] ?? null,'ip'=>$_SERVER['REMOTE_ADDR'] ?? null
  ], JSON_UNESCAPED_SLASHES) . PHP_EOL, 3, AE_LOG);
}

/* inputs */
$anchorType  = isset($_GET['anchor_type']) ? trim((string)$_GET['anchor_type']) : 'neutral';
$anchorValue = isset($_GET['anchor_value']) ? trim((string)$_GET['anchor_value']) : 'Blacks';
$minCount    = isset($_GET['min_count']) ? max(1, (int)$_GET['min_count']) : 1;
$sortParam   = isset($_GET['sort']) ? trim((string)$_GET['sort']) : 'count_desc';
$page        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limitIn     = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
$limit       = max(25, min(500, $limitIn));
$offset      = ($page - 1) * $limit;

$anchorCol  = ($anchorType === 'hue') ? 'a.hue_cats' : 'a.neutral_cats';
$anchorLike = '%'.$anchorValue.'%';

/* sort options — use selected aliases/expressions available in the SELECT */
$sortMap = [
  'count_desc'       => 'edge_count DESC, a.h_r ASC, b.h_r ASC',
  'delta_h_abs_desc' => 'delta_h_abs DESC, edge_count DESC, a.h_r ASC',
  'delta_c_desc'     => 'delta_c DESC, edge_count DESC, a.h_r ASC',
  'delta_l_desc'     => 'delta_l DESC, edge_count DESC, a.h_r ASC',
  'a_h_asc'          => 'a.h_r ASC, edge_count DESC, b.h_r ASC',
  'b_h_asc'          => 'b.h_r ASC, edge_count DESC, a.h_r ASC',
];
$orderBy = $sortMap[$sortParam] ?? $sortMap['count_desc'];

/* one representative hex per cluster */
$repHexSub = "SELECT cluster_id, MIN(hex6) AS rep_hex6 FROM cluster_hex GROUP BY cluster_id";

/* true counts per directional edge (friends_union → clusters via cluster_hex) */
$ecSub = <<<SQL
  SELECT
    chf.cluster_id AS c_from,
    cht.cluster_id AS c_to,
    COUNT(*)       AS true_count
  FROM friends_union fu
  JOIN cluster_hex chf ON chf.hex6 = fu.key_hex6
  JOIN cluster_hex cht ON cht.hex6 = fu.friend_hex6
  WHERE chf.cluster_id IN (SELECT id FROM clusters a WHERE {$anchorCol} LIKE :av_ec)
  GROUP BY chf.cluster_id, cht.cluster_id
SQL;

try {
  ae_log('info','edges start', [
    'anchor_type'=>$anchorType,'anchor_value'=>$anchorValue,
    'min_count'=>$minCount,'sort'=>$sortParam,'page'=>$page,'limit'=>$limit
  ]);

  /* PAGE query */
  $sqlPage = <<<SQL
    SELECT
      ahex.rep_hex6 AS anchor_hex6,
      a.h_r AS a_h, a.c_r AS a_c, a.l_r AS a_l,
      a.neutral_cats AS a_neutral_cats,
      a.hue_cats     AS a_hue_cats,

      bhex.rep_hex6 AS friend_hex6,
      b.h_r AS b_h, b.c_r AS b_c, b.l_r AS b_l,
      b.neutral_cats AS b_neutral_cats,
      b.hue_cats     AS b_hue_cats,

      ((MOD(b.h_r - a.h_r + 540, 360)) - 180)    AS delta_h_signed,
      ABS((MOD(b.h_r - a.h_r + 540, 360)) - 180) AS delta_h_abs,
      (b.c_r - a.c_r)                            AS delta_c,
      (b.l_r - a.l_r)                            AS delta_l,

      COALESCE(ec.true_count, cu.weight) AS edge_count
    FROM cluster_union cu
    JOIN clusters a ON a.id = cu.c_from
    JOIN clusters b ON b.id = cu.c_to
    LEFT JOIN ({$repHexSub}) ahex ON ahex.cluster_id = a.id
    LEFT JOIN ({$repHexSub}) bhex ON bhex.cluster_id = b.id
    LEFT JOIN ({$ecSub}) ec ON ec.c_from = cu.c_from AND ec.c_to = cu.c_to
    WHERE {$anchorCol} LIKE :av_main
      AND COALESCE(ec.true_count, cu.weight) >= :min_count
    ORDER BY {$orderBy}
    LIMIT :limit OFFSET :offset
  SQL;

  /* COUNT query */
  $sqlCount = <<<SQL
    SELECT COUNT(*) AS cnt
    FROM (
      SELECT cu.c_from, cu.c_to
      FROM cluster_union cu
      JOIN clusters a ON a.id = cu.c_from
      LEFT JOIN ({$ecSub}) ec ON ec.c_from = cu.c_from AND ec.c_to = cu.c_to
      WHERE {$anchorCol} LIKE :av_main
        AND COALESCE(ec.true_count, cu.weight) >= :min_count
      GROUP BY cu.c_from, cu.c_to
    ) x
  SQL;

  /* run page */
  $stmt = $pdo->prepare($sqlPage);
  $stmt->bindValue(':av_ec',     $anchorLike, \PDO::PARAM_STR); // for ecSub
  $stmt->bindValue(':av_main',   $anchorLike, \PDO::PARAM_STR);
  $stmt->bindValue(':min_count', $minCount,   \PDO::PARAM_INT);
  $stmt->bindValue(':limit',     $limit,      \PDO::PARAM_INT);
  $stmt->bindValue(':offset',    $offset,     \PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

  /* run count */
  $stmt2 = $pdo->prepare($sqlCount);
  $stmt2->bindValue(':av_ec',     $anchorLike, \PDO::PARAM_STR); // for ecSub
  $stmt2->bindValue(':av_main',   $anchorLike, \PDO::PARAM_STR);
  $stmt2->bindValue(':min_count', $minCount,   \PDO::PARAM_INT);
  $stmt2->execute();
  $total = (int)($stmt2->fetchColumn() ?: 0);

  /* normalize payload */
  $items = array_map(function(array $r): array {
    foreach (['a_h','a_c','a_l','b_h','b_c','b_l','delta_h_signed','delta_h_abs','delta_c','delta_l','edge_count'] as $k) {
      if (array_key_exists($k,$r) && $r[$k] !== null && $r[$k] !== '') {
        $r[$k] = is_numeric($r[$k]) ? $r[$k] + 0 : $r[$k];
      }
    }
    $r['count'] = $r['edge_count'] ?? 0; // keep UI key 'count'
    unset($r['edge_count']);
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
  ae_log('error','edges fail', ['err'=>$e->getMessage()]);
  http_response_code(500);
  $err = json_encode(['error'=>'Internal error','detail'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
  ob_end_clean();
  echo $err;
  exit;
}
