<?php
declare(strict_types=1);
/**
 * /api/palette-get.php?id=123&pivot=10875
 * Returns one palette with members ordered (pivot first), plus a representative color_id
 * and the full swatch_view row for each member.
 */
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0'); ini_set('log_errors','1');

function logj(string $msg, array $ctx = []): void {
  try {
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $file = $dir . '/palette-get-' . date('Y-m-d') . '.log';
    @file_put_contents($file, json_encode(['ts'=>date('c'),'msg'=>$msg,'ctx'=>$ctx], JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
  } catch (Throwable $e) { /* ignore */ }
}

try {
  require_once 'db.php';

  $id    = isset($_GET['id'])    ? (int)$_GET['id']    : 0;
  $pivot = isset($_GET['pivot']) ? (int)$_GET['pivot'] : 0;

  logj('start', ['id'=>$id,'pivot'=>$pivot]);

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'missing ?id'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
  }

  // Pull palette members, pivot-first. Include representative color_id + example_name.
  $sql = "
    SELECT
      p.id, p.size, p.tier, p.status, p.source_note, p.notes,
      pm.member_cluster_id AS cluster_id,
      cl.rep_hex,
      (SELECT MIN(name) FROM colors WHERE cluster_id = pm.member_cluster_id) AS example_name,
      (
        SELECT c.id
        FROM colors c
        WHERE c.cluster_id = pm.member_cluster_id
        ORDER BY (c.hex6 = cl.rep_hex) DESC, c.id
        LIMIT 1
      ) AS color_id
    FROM palettes p
    JOIN palette_members pm ON pm.palette_id = p.id
    JOIN clusters cl        ON cl.id = pm.member_cluster_id
    WHERE p.id = ?
    ORDER BY (pm.member_cluster_id = ?) DESC,
             pm.order_hint, pm.member_cluster_id
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$id, $pivot]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) {
    logj('not_found', ['id'=>$id]);
    echo json_encode(['ok'=>false,'error'=>'not found'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
  }

  // Representative color_ids for swatch_view lookup
  $colorIds = array_values(array_unique(array_filter(array_map(
    fn($r) => isset($r['color_id']) ? (int)$r['color_id'] : 0,
    $rows
  ))));

  // Fetch full swatch_view rows keyed by color_id
  $svById = [];
  if ($colorIds) {
    $place = implode(',', array_fill(0, count($colorIds), '?'));
    // If your view uses `id` instead of `color_id`, change the column below AND the array key.
    $sqlSv = "SELECT * FROM swatch_view WHERE id IN ($place)";
    $stmtSv = $pdo->prepare($sqlSv);
    $stmtSv->execute($colorIds);
    foreach ($stmtSv->fetchAll(PDO::FETCH_ASSOC) as $sv) {
      $svById[(int)$sv['id']] = $sv;
    }
  }

  // Build response
  $first = $rows[0];
  $out = [
    'ok'          => true,
    'id'          => (int)$first['id'],
    'size'        => (int)$first['size'],
    'tier'        => $first['tier'],
    'status'      => $first['status'],
    'source_note' => $first['source_note'],
    'notes'       => $first['notes'],
    'members'     => array_map(function($r) use ($svById) {
      $cid = isset($r['color_id']) ? (int)$r['color_id'] : null;
      return [
        'cluster_id'   => (int)$r['cluster_id'],
        'rep_hex'      => $r['rep_hex'],
        'example_name' => $r['example_name'] ?? null,
        'color_id'     => $cid,
        'swatch'       => ($cid && isset($svById[$cid])) ? $svById[$cid] : null, // full swatch_view row
      ];
    }, $rows),
  ];

  logj('done', ['id'=>$id, 'members'=>count($out['members'])]);
  echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;

} catch (Throwable $e) {
  logj('error', ['err'=>$e->getMessage()]);
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
