<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0'); ini_set('log_errors','1');

try {
  require_once 'db.php';
  $member = isset($_GET['member']) ? (int)$_GET['member'] : 0;
  if ($member <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing ?member']); exit; }

  $sizes = isset($_GET['sizes']) && trim($_GET['sizes']) !== '' ? $_GET['sizes'] : '3,4';
  $sizesArr = array_values(array_unique(array_filter(array_map('intval', explode(',', $sizes)), fn($n)=>$n>=3 && $n<=12)));
  if (!$sizesArr) $sizesArr = [3,4];

  $sort = $_GET['sort'] ?? 'size_asc';
  $orderBySql = $sort==='size_desc' ? "p.size DESC, p.id DESC" : ($sort==='random' ? "RAND()" : "p.size, p.id");

  $limit  = isset($_GET['limit'])  ? max(1, min(200, (int)$_GET['limit']))   : 100;
  $offset = isset($_GET['offset']) ? max(0,            (int)$_GET['offset']) : 0;
  $tier   = $_GET['tier']   ?? 'A';
  $status = $_GET['status'] ?? 'active';
  $with   = trim((string)($_GET['with'] ?? ''));

  $params = [
    ':tier'=>$tier, ':status'=>$status, ':limit'=>$limit, ':offset'=>$offset,
    ':m1'=>$member, ':m2'=>$member
  ];
  $in = [];
  foreach ($sizesArr as $i=>$s){ $ph=":s$i"; $in[]=$ph; $params[$ph]=$s; }
  $inList = implode(',', $in);

$with    = trim((string)($_GET['with'] ?? ''));
$withAll = trim((string)($_GET['with_all'] ?? '')); // e.g. "White,Black"
$withSql = '';

if ($withAll !== '') {
  $needles = array_values(array_filter(array_map('trim', explode(',', $withAll))));
  $sub = []; $i = 0;
  foreach ($needles as $tok) {
    if ($tok === '') continue;
    $t = strtolower($tok);
    if ($t === 'grey') $t = 'gray';
    $isNeutral = in_array($t, ['white','black','gray','greige','beige','brown'], true);
    if (substr($t,-1) !== 's') $t .= 's';    // plural form
    $ph = ":with{$i}";
    $params[$ph] = '%'.$t.'%';

    if ($isNeutral) {
      $sub[] = "EXISTS (
        SELECT 1 FROM palette_members pmN{$i}
        JOIN clusters clN{$i} ON clN{$i}.id = pmN{$i}.member_cluster_id
        WHERE pmN{$i}.palette_id = p.id
          AND LOWER(COALESCE(clN{$i}.neutral_cats,'')) LIKE {$ph}
      )";
    } else {
      $sub[] = "EXISTS (
        SELECT 1 FROM palette_members pmN{$i}
        JOIN clusters clN{$i} ON clN{$i}.id = pmN{$i}.member_cluster_id
        WHERE pmN{$i}.palette_id = p.id
          AND LOWER(COALESCE(clN{$i}.hue_cats,'')) LIKE {$ph}
      )";
    }
    $i++;
  }
  if ($sub) $withSql = ' AND ' . implode(' AND ', $sub);
}



  $sql = "
  SELECT
  p.id,
  p.size,
  GROUP_CONCAT(
    cl.rep_hex
    ORDER BY pm.order_hint, pm.member_cluster_id
    SEPARATOR ','
  ) AS hexes,
  GROUP_CONCAT(
    pm.member_cluster_id
    ORDER BY pm.order_hint, pm.member_cluster_id
    SEPARATOR ','
  ) AS member_cluster_ids,
  SUM(CASE WHEN COALESCE(cl.neutral_cats,'') = '' THEN 1 ELSE 0 END) AS chromatic_count,
  SUM(CASE WHEN COALESCE(cl.neutral_cats,'') <> '' THEN 1 ELSE 0 END) AS neutral_count,
  MAX(CASE WHEN cl.neutral_cats LIKE '%White%' THEN 1 ELSE 0 END)     AS has_white,
  MAX(CASE WHEN cl.neutral_cats LIKE '%Black%' THEN 1 ELSE 0 END)     AS has_black
FROM palettes p
JOIN palette_members pm ON pm.palette_id = p.id
JOIN clusters cl        ON cl.id = pm.member_cluster_id
    WHERE p.tier = :tier AND p.status = :status
      AND p.size IN ($inList)
      AND EXISTS (SELECT 1 FROM palette_members x WHERE x.palette_id = p.id AND x.member_cluster_id = :m2)
      $withSql
    GROUP BY p.id, p.size
    ORDER BY $orderBySql
    LIMIT :limit OFFSET :offset
  ";

  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $stmt->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['ok'=>true,'member'=>$member,'sizes'=>$sizesArr,'with'=>($with?:null),'count'=>count($rows),'items'=>$rows], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
}
