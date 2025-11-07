<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0'); ini_set('log_errors','1');

try {
  require_once 'db.php';
  $member = isset($_GET['member']) ? (int)$_GET['member'] : 0;
  if ($member <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing ?member']); exit; }

  $tier   = $_GET['tier']   ?? 'A';
  $status = $_GET['status'] ?? 'active';
  $with   = trim((string)($_GET['with'] ?? ''));

  $params = [ ':tier'=>$tier, ':status'=>$status, ':m'=>$member ];
 $with    = trim((string)($_GET['with'] ?? ''));
$withSql = '';

if ($with !== '') {
  // normalize token
  $t = strtolower($with);
  if ($t === 'grey') $t = 'gray';        // normalize spelling

  // neutral vs chromatic routing
  $isNeutral = in_array($t, ['white','black','gray','greige','beige','brown'], true);

  // simple pluralization (your cats are plural)
  if (substr($t, -1) !== 's') $t .= 's';
  $params[':with'] = '%'.$t.'%';

  if ($isNeutral) {
    // ONLY neutrals: ignore undertone (hue_cats) for this filter
    $withSql = " AND EXISTS (
      SELECT 1
      FROM palette_members pm2
      JOIN clusters cl2 ON cl2.id = pm2.member_cluster_id
      WHERE pm2.palette_id = p.id
        AND LOWER(COALESCE(cl2.neutral_cats,'')) LIKE :with
    )";
  } else {
    // ONLY chromatic family in hue_cats
    $withSql = " AND EXISTS (
      SELECT 1
      FROM palette_members pm2
      JOIN clusters cl2 ON cl2.id = pm2.member_cluster_id
      WHERE pm2.palette_id = p.id
        AND LOWER(COALESCE(cl2.hue_cats,'')) LIKE :with
    )";
  }
}


  $sql = "
    SELECT p.size AS size, COUNT(*) AS cnt
    FROM palettes p
    WHERE p.tier = :tier AND p.status = :status
      AND EXISTS (SELECT 1 FROM palette_members x WHERE x.palette_id = p.id AND x.member_cluster_id = :m)
      $withSql
    GROUP BY p.size
    ORDER BY p.size
  ";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $stmt->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  echo json_encode(['ok'=>true,'member'=>$member,'with'=>($with?:null),'items'=>$rows], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
}
