<?php
declare(strict_types=1);
/**
 * /api/cluster-find.php?q=Swiss%20Coffee[&brand=Behr]
 *
 * Default: prefix match (name LIKE 'Swiss Coffee%')
 * Wildcard: if q contains '*' we treat it as LIKE with '%' (e.g. '*Sage' -> '%Sage')
 * Hex: if q looks like hex, match hex6 LIKE 'F1F2EE%'
 *
 * Returns:
 * { ok, items:[{cluster_id, rep_hex, brand, name}], brands:[{brand,hits}] }
 */
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0'); ini_set('log_errors','1');

function out($x){ echo json_encode($x, JSON_UNESCAPED_SLASHES) . PHP_EOL; exit; }
function logj(string $m, array $c=[]){ try{
  $dir = __DIR__.'/logs'; if (!is_dir($dir)) @mkdir($dir,0775,true);
  @file_put_contents($dir.'/cluster-find-'.date('Y-m-d').'.log',
    json_encode(['ts'=>date('c'),'msg'=>$m,'ctx'=>$c],JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
} catch(Throwable $e){} }

try {
  require_once 'db.php';

  $qRaw   = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
  $brandQ = isset($_GET['brand']) ? trim((string)$_GET['brand']) : '';

  if ($qRaw === '') out(['ok'=>true,'items'=>[],'brands'=>[]]);

  $isHex  = (bool)preg_match('/^[0-9A-Fa-f]{3,6}$/', $qRaw);
  $lowerQ = mb_strtolower($qRaw);

  // Determine mode
  $hasWildcard = strpos($qRaw, '*') !== false;
  $likeName = ''; $likeHex = '';

  if ($isHex) {
    // hex prefix by default; allow wildcard like 'F1*' => 'F1%'
    if ($hasWildcard) {
      $likeHex = '%' . str_replace(['%','_','*'], ['\\%','\\_','%'], $lowerQ) . '%';
    } else {
      $likeHex = $lowerQ . '%';
    }
  } else {
    if ($hasWildcard) {
      // contains (or side-trim) with '*' → '%'
      $likeName = '%' . str_replace(['%','_','*'], ['\\%','\\_','%'], $lowerQ) . '%';
    } else {
      // prefix
      $likeName = $lowerQ . '%';
    }
  }

  // WHERE and params (name or hex; require cluster)
  $where = " c.cluster_id IS NOT NULL AND (";
  $params = [];

  if ($isHex) {
    $where .= " LOWER(c.hex6) LIKE :HEX ";
    $params[':HEX'] = $likeHex;
  } else {
    $where .= " LOWER(sv.name) LIKE :NAME ";
    $params[':NAME'] = $likeName;
  }
  $where .= ")";

  if ($brandQ !== '') {
    $where .= " AND sv.brand = :BR ";
    $params[':BR'] = $brandQ;
  }

  // Main results (distinct by cluster+brand+name)
  $sql = "
    SELECT c.cluster_id, cl.rep_hex, sv.brand, sv.name
    FROM swatch_view sv
    JOIN colors   c  ON c.id = sv.id
    JOIN clusters cl ON cl.id = c.cluster_id
    WHERE $where
    GROUP BY c.cluster_id, sv.brand, sv.name
   ORDER BY
  (LOWER(sv.name) = :QEX) DESC,  -- exact name match first
  sv.name,                       -- then name A→Z
  sv.brand,                      -- then brand A→Z
  c.cluster_id
    LIMIT 100
  ";
  $params[':QEX'] = $lowerQ;

  $t0 = microtime(true);
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
  $stmt->execute();
  $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $ms = (int)round((microtime(true)-$t0)*1000);
  logj('cluster-find.done', ['q'=>$qRaw,'brand'=>$brandQ,'mode'=>$isHex?($hasWildcard?'hex-like':'hex-prefix'):($hasWildcard?'name-like':'name-prefix'),'hits'=>count($items),'ms'=>$ms]);

  // Brand facets for this query scope (reuse WHERE/params; drop QEX)
  unset($params[':QEX']);
  $sqlB = "
    SELECT sv.brand, COUNT(DISTINCT c.cluster_id) AS hits
    FROM swatch_view sv
    JOIN colors c ON c.id = sv.id
    WHERE $where
    GROUP BY sv.brand
    ORDER BY hits DESC, sv.brand
    LIMIT 50
  ";
  $stmtB = $pdo->prepare($sqlB);
  foreach ($params as $k=>$v) $stmtB->bindValue($k,$v,PDO::PARAM_STR);
  $stmtB->execute();
  $brands = $stmtB->fetchAll(PDO::FETCH_ASSOC) ?: [];

  out(['ok'=>true,'items'=>$items,'brands'=>$brands]);

} catch (Throwable $e) {
  http_response_code(500);
  out(['ok'=>false,'error'=>$e->getMessage()] );
}
