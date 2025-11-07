<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0'); ini_set('log_errors','1');

require_once 'db.php';
require_once __DIR__ . '/functions/translatePalette.php';

try {
  // ----- Parse inputs from POST JSON and/or GET query -----
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

  $body = [];
  if ($method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw !== '') {
      $tmp = json_decode($raw, true);
      if (is_array($tmp)) $body = $tmp;
    }
  }

  // palette_id: POST or GET
  $palette_id = (int)($body['palette_id'] ?? ($_GET['palette_id'] ?? 0));

  // cluster_ids: POST (array) or GET (?clusters=1,2,3)
  $cluster_id_set = [];
  if (!empty($body['cluster_ids']) && is_array($body['cluster_ids'])) {
    foreach ($body['cluster_ids'] as $cid) {
      $n = (int)$cid;
      if ($n > 0) $cluster_id_set[$n] = true;
    }
  }
  if (isset($_GET['clusters'])) {
    foreach (explode(',', (string)$_GET['clusters']) as $s) {
      $n = (int)trim($s);
      if ($n > 0) $cluster_id_set[$n] = true;
    }
  }
  $cluster_ids = array_keys($cluster_id_set);

  // Optional brands filter: POST ["de","sw"] or GET ?brands=de,sw
  $brands = [];
  if (!empty($body['brands']) && is_array($body['brands'])) {
    foreach ($body['brands'] as $b) {
      $b = trim((string)$b);
      if ($b !== '') $brands[] = $b;
    }
  } elseif (!empty($_GET['brands'])) {
    foreach (explode(',', (string)$_GET['brands']) as $b) {
      $b = trim((string)$b);
      if ($b !== '') $brands[] = $b;
    }
  }

  // ----- Run translation -----
  if (!empty($cluster_ids)) {
    $res = translatePaletteFromClusters($pdo, $cluster_ids, $brands);
    echo json_encode([
      'ok'         => !empty($res['ok']),
      'src_kind'   => 'clusters',
      'src_count'  => $res['src_count'] ?? 0,
      'brands'     => $res['brands'] ?? [],
      'count'      => isset($res['items']) ? count($res['items']) : 0,
      'items'      => $res['items'] ?? [],
      'failures'   => $res['failures'] ?? [],
    ], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($palette_id > 0) {
    $res = translatePalette($pdo, $palette_id, $brands);
    echo json_encode([
      'ok'         => !empty($res['ok']),
      'palette_id' => $palette_id,
      'src_kind'   => 'palette',
      'src_count'  => $res['src_count'] ?? 0,
      'brands'     => $res['brands'] ?? [],
      'count'      => isset($res['items']) ? count($res['items']) : 0,
      'items'      => $res['items'] ?? [],
      'failures'   => $res['failures'] ?? [],
    ], JSON_UNESCAPED_SLASHES);
    exit;
  }

  echo json_encode(['ok'=>false,'error'=>'Missing palette_id or cluster_ids'], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
}
