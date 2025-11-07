<?php
declare(strict_types=1);
/**
 * /api/palette-get.php?id=123&pivot=10875
 * Returns one palette with members ordered (pivot first), ids + rep_hex.
 */
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0'); ini_set('log_errors','1');
if (isset($_GET['test'])) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "palette-get alive\n";
  exit;
}

function logj(string $msg, array $ctx = []): void {
  try {
    $dir = __DIR__ . '/logs';            // <-- was dirname(__DIR__) . '/logs'
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $file = $dir . '/palette-get-' . date('Y-m-d') . '.log';
    @file_put_contents($file, json_encode(['ts'=>date('c'),'msg'=>$msg,'ctx'=>$ctx], JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
  } catch (Throwable $e) { /* ignore */ }
}

try {
  require_once  'db.php';

  $id    = isset($_GET['id'])    ? (int)$_GET['id']    : 0;
  $pivot = isset($_GET['pivot']) ? (int)$_GET['pivot'] : 0;

  logj('start', ['id'=>$id,'pivot'=>$pivot]);

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'missing ?id'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
  }

$sql = "
  SELECT
    p.id, p.size, p.tier, p.status, p.source_note, p.notes,
    pm.member_cluster_id AS cluster_id,
    cl.rep_hex
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

  $head = $rows[0];
  $out = [
    'ok' => true,
    'id' => (int)$head['id'],
    'size' => (int)$head['size'],
    'tier' => $head['tier'],
    'status' => $head['status'],
    'source_note' => $head['source_note'],
    'notes' => $head['notes'],
    'members' => array_map(fn($r)=>[
        'cluster_id' => (int)$r['cluster_id'],
        'rep_hex'    => $r['rep_hex'],
    ], $rows),
  ];
  logj('done', ['id'=>$id, 'members'=>count($out['members'])]);
  echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;

} catch (Throwable $e) {
  logj('error', ['err'=>$e->getMessage()]);
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
