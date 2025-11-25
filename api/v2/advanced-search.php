<?php
// NOTE: ensure there are absolutely no bytes before this "<?php" — no BOM/whitespace.
// CORS preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=UTF-8');
@ini_set('display_errors','0');
@ini_set('log_errors','1');

// Keep includes exactly like your sibling file
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

// v2 JSON handlers (always 200, never HTML)
set_error_handler(function($sev,$msg,$file,$line){
  http_response_code(200);
  echo json_encode(['ok'=>false,'items'=>[],'total'=>0,'count'=>0,'_err'=>"PHP error: $msg"], JSON_UNESCAPED_SLASHES);
  exit;
});
set_exception_handler(function($e){
  http_response_code(200);
  echo json_encode(['ok'=>false,'items'=>[],'total'=>0,'count'=>0,'_err'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
  exit;
});

function jok($payload){ http_response_code(200); echo json_encode($payload, JSON_UNESCAPED_SLASHES); exit; }

// ---------- helpers (no type hints / arrows) ----------
$numOrNull = function($v){ return ($v===null||$v===''||!is_numeric($v)) ? null : 0+$v; };
$normHue = function($h){ if(!is_numeric($h))return null; $x=fmod((float)$h,360.0); if($x<0)$x+=360.0; return $x; };
$clamp = function($v,$min,$max){ return max($min, min($max,$v)); };

// ---------- input ----------
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = array();

$hex6In = (string)(isset($data['hex6']) ? $data['hex6'] : (isset($data['hex']) ? $data['hex'] : ''));
$hueMin = $numOrNull(isset($data['hue_min']) ? $data['hue_min'] : null);
$hueMax = $numOrNull(isset($data['hue_max']) ? $data['hue_max'] : null);
$cMin   = $numOrNull(isset($data['c_min'])   ? $data['c_min']   : null);
$cMax   = $numOrNull(isset($data['c_max'])   ? $data['c_max']   : null);
$lMin   = $numOrNull(isset($data['l_min'])   ? $data['l_min']   : null);
$lMax   = $numOrNull(isset($data['l_max'])   ? $data['l_max']   : null);
$supercatId   = (int)($data['supercat_id'] ?? 0);
if ($supercatId < 0) $supercatId = 0;
$supercatSlug = trim((string)($data['supercat_slug'] ?? ''));

$brandsIn = array();
if (isset($data['brand'])  && is_array($data['brand']))  $brandsIn = $data['brand'];
if (isset($data['brands']) && is_array($data['brands'])) $brandsIn = $data['brands'];
$brands = array();
foreach ($brandsIn as $b){ $s=strtolower(trim((string)$b)); if($s!=='') $brands[]=$s; }
$brands = array_values(array_unique($brands));

$limit  = (int)(isset($data['limit'])  ? $data['limit']  : 200);
$offset = (int)(isset($data['offset']) ? $data['offset'] : 0);
if ($limit < 1)   $limit = 1;
if ($limit > 500) $limit = 500;
if ($offset < 0)  $offset = 0;

// ---------- HEX6 normalize (accept #RRGGBB | RRGGBB | #RGB | RGB) ----------
$hex6 = '';
if ($hex6In !== '') {
  $h = strtoupper(ltrim(trim($hex6In), '#'));
  if (preg_match('/^[0-9A-F]{3}$/', $h)) $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
  if (preg_match('/^[0-9A-F]{6}$/', $h)) $hex6 = $h; else {
    // invalid hex provided → empty results (gracefully)
    jok(array('ok'=>true,'limit'=>$limit,'offset'=>$offset,'total'=>0,'count'=>0,'items'=>array()));
  }
}

// ---------- WHERE builder ----------
$where  = array();
$params = array();

// HUE band(s)
if ($hueMin !== null || $hueMax !== null) {
  if ($hueMin !== null && $hueMax === null) {
    $a=$normHue($hueMin); $b=$a+1.0;
    if ($b>=360.0) { $where[]='(c.hcl_h >= :hue_a OR c.hcl_h <= :hue_b)'; $params[':hue_a']=$a; $params[':hue_b']=$normHue($b); }
    else           { $where[]='(c.hcl_h >= :hue_a AND c.hcl_h <= :hue_b)'; $params[':hue_a']=$a; $params[':hue_b']=$b; }
  } elseif ($hueMin === null && $hueMax !== null) {
    $b=$normHue($hueMax); $a=$b-1.0;
    if ($a<0.0) { $where[]='(c.hcl_h >= :hue_a OR c.hcl_h <= :hue_b)'; $params[':hue_a']=360.0+$a; $params[':hue_b']=$b; }
    else        { $where[]='(c.hcl_h >= :hue_a AND c.hcl_h <= :hue_b)'; $params[':hue_a']=$a; $params[':hue_b']=$b; }
  } else {
    $minN=$normHue($hueMin); $maxN=$normHue($hueMax);
    $wrap = ($hueMin<0) || ($minN>$maxN);
    if ($wrap) { $left=($hueMin<0)?360.0+$hueMin:$minN; $where[]='(c.hcl_h >= :hue_a OR c.hcl_h <= :hue_b)'; $params[':hue_a']=$left; $params[':hue_b']=$maxN; }
    else       { $where[]='(c.hcl_h >= :hue_a AND c.hcl_h <= :hue_b)'; $params[':hue_a']=$minN; $params[':hue_b']=$maxN; }
  }
}

// CHROMA
if ($cMin !== null && $cMax !== null) {
  $where[]='(c.hcl_c >= :c_min AND c.hcl_c <= :c_max)'; $params[':c_min']=$cMin; $params[':c_max']=$cMax;
} elseif ($cMin !== null) {
  $where[]='(c.hcl_c >= :c_min AND c.hcl_c <= :c_min_plus)'; $params[':c_min']=$cMin; $params[':c_min_plus']=$cMin+1.0;
} elseif ($cMax !== null) {
  $where[]='(c.hcl_c >= :c_max_minus AND c.hcl_c <= :c_max)'; $params[':c_max_minus']=$cMax-1.0; $params[':c_max']=$cMax;
}

// LIGHTNESS
if ($lMin !== null && $lMax !== null) {
  $where[]='(c.hcl_l >= :l_min AND c.hcl_l <= :l_max)'; $params[':l_min']=$clamp($lMin,0.0,100.0); $params[':l_max']=$clamp($lMax,0.0,100.0);
} elseif ($lMin !== null) {
  $lA=$clamp($lMin,0.0,100.0); $lB=$clamp($lMin+1.0,0.0,100.0); $where[]='(c.hcl_l >= :l_a AND c.hcl_l <= :l_b)'; $params[':l_a']=$lA; $params[':l_b']=$lB;
} elseif ($lMax !== null) {
  $lB=$clamp($lMax,0.0,100.0); $lA=$clamp($lMax-1.0,0.0,100.0); $where[]='(c.hcl_l >= :l_a AND c.hcl_l <= :l_b)'; $params[':l_a']=$lA; $params[':l_b']=$lB;
}

// BRAND (sv.brand IN ...), case-insensitive
if (!empty($brands)) {
  $ph = array();
  foreach ($brands as $i=>$b){ $key=':brand_'.$i; $ph[]=$key; $params[$key]=$b; }
  $where[] = 'LOWER(sv.brand) IN ('.implode(',', $ph).')';
}

// HEX6 exact (sv.hex6)
if ($hex6 !== '') { $where[] = 'UPPER(sv.hex6) = :hex6'; $params[':hex6'] = $hex6; }

$joins = array('JOIN swatch_view sv ON sv.id = c.id');
$slugFilter = '';
$filterSupercat = ($supercatId > 0) || ($supercatSlug !== '');
if ($filterSupercat) {
  $joins[] = 'JOIN color_supercats cs ON cs.color_id = c.id';
  if ($supercatSlug !== '') {
    $slugFilter = strtolower($supercatSlug);
    $joins[] = 'JOIN supercats sc ON sc.id = cs.supercat_id';
    $where[] = 'sc.slug = :super_slug';
    $where[] = 'sc.is_active = 1';
    $params[':super_slug'] = $slugFilter;
  }
  if ($supercatId > 0) {
    $where[] = 'cs.supercat_id = :super_id';
    $params[':super_id'] = $supercatId;
  }
}
$joinSql = ' ' . implode(' ', $joins) . ' ';
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

// ---------- queries ----------
$countSql = 'SELECT COUNT(*) AS cnt
             FROM colors c
             ' . $joinSql . $whereSql;

$dataSql  = 'SELECT sv.*
             FROM colors c
             ' . $joinSql . $whereSql . '
             ORDER BY c.hcl_h ASC, c.hcl_c ASC, c.hcl_l DESC
             LIMIT :limit OFFSET :offset';

try {
  $stmt = $pdo->prepare($countSql);
  foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
  $stmt->execute();
  $total = (int)$stmt->fetchColumn();

  $stmt = $pdo->prepare($dataSql);
  foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
  $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  jok(array(
    'ok'     => true,
    'limit'  => $limit,
    'offset' => $offset,
    'total'  => $total,
    'count'  => count($rows),
    'items'  => $rows
  ));
} catch (Throwable $e) {
  jok(array(
    'ok'     => false,
    'limit'  => $limit,
    'offset' => $offset,
    'total'  => 0,
    'count'  => 0,
    'items'  => array(),
    '_err'   => 'Query error'
  ));
}
