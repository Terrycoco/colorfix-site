<?php
declare(strict_types=1);

// /api/v2/advanced-search.php
// JSON POST:
// {
//   "hex6": string|null,       // #RRGGBB | RRGGBB | #RGB | RGB (3-digit auto-expands)
//   "hue_min": number|null,    // negative min => wrap with hue_max (e.g., -10 & 20 => 350..20)
//   "hue_max": number|null,
//   "c_min": number|null,
//   "c_max": number|null,
//   "l_min": number|null,
//   "l_max": number|null,
//   "brand":  string[]|null,   // ['de','sw',...], case-insensitive
//   "limit":  number|null,     // default 200, max 500
//   "offset": number|null      // default 0
// }
//
// Filters are applied on `colors` (c.*) and brand/hex6 on `swatch_view` (sv.*).
// Results returned from `swatch_view` rows.

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php'; // defines $pdo = new PDO(...)

use PDO;
use Throwable;

// ---- tiny logger ----
const ADV_LOG = __DIR__ . '/../logs/advanced-search.log';
function adv_log(string $lvl, string $msg, array $ctx = []): void {
  $dir = dirname(ADV_LOG);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @error_log(json_encode([
    'ts'  => date('c'),
    'lvl' => $lvl,
    'msg' => $msg,
    'ctx' => $ctx,
    'uri' => $_SERVER['REQUEST_URI'] ?? null,
    'ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
  ], JSON_UNESCAPED_SLASHES) . PHP_EOL, 3, ADV_LOG);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

try {
  $raw  = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];
  adv_log('incoming', 'payload', ['body' => $data]);

  // ---------- helpers ----------
  $numOrNull = function($v) {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return 0 + $v;
  };
  $normHue = function($h) {
    if (!is_numeric($h)) return null;
    $x = fmod((float)$h, 360.0);
    if ($x < 0) $x += 360.0;
    return $x; // 0.. <360
  };
  $clamp = function($v, $min, $max) {
    return max($min, min($max, $v));
  };

  // ---------- inputs ----------
  $hex6In = (string)($data['hex6'] ?? $data['hex'] ?? '');
  $hueMin = $numOrNull($data['hue_min'] ?? null);
  $hueMax = $numOrNull($data['hue_max'] ?? null);
  $cMin   = $numOrNull($data['c_min']   ?? null);
  $cMax   = $numOrNull($data['c_max']   ?? null);
  $lMin   = $numOrNull($data['l_min']   ?? null);
  $lMax   = $numOrNull($data['l_max']   ?? null);

  $brandsIn = $data['brand'] ?? $data['brands'] ?? [];
  $brands = [];
  if (is_array($brandsIn)) {
    foreach ($brandsIn as $b) {
      $s = strtolower(trim((string)$b));
      if ($s !== '') $brands[] = $s;
    }
    $brands = array_values(array_unique($brands));
  }

  $limit  = (int)($data['limit']  ?? 200);
  $offset = (int)($data['offset'] ?? 0);
  if ($limit < 1)   $limit = 1;
  if ($limit > 500) $limit = 500;
  if ($offset < 0)  $offset = 0;

  // ---------- HEX6 normalize ----------
  $hex6 = '';
  if ($hex6In !== '') {
    $h = strtoupper(ltrim(trim($hex6In), '#'));
    if (preg_match('/^[0-9A-F]{3}$/', $h)) {
      $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
    }
    if (preg_match('/^[0-9A-F]{6}$/', $h)) {
      $hex6 = $h;
    } else {
      adv_log('warn', 'invalid hex6 provided', ['hex6' => $hex6In]);
    }
  }

  // ---------- WHERE builder ----------
  $where  = [];
  $params = [];

  // HUE
  if ($hueMin !== null || $hueMax !== null) {
    if ($hueMin !== null && $hueMax === null) {
      $a = $normHue($hueMin); $b = $a + 1.0;
      if ($b >= 360.0) {
        $where[] = '(c.hcl_h >= :hue_a OR c.hcl_h <= :hue_b)';
        $params[':hue_a'] = $a;
        $params[':hue_b'] = $normHue($b);
      } else {
        $where[] = '(c.hcl_h >= :hue_a AND c.hcl_h <= :hue_b)';
        $params[':hue_a'] = $a; $params[':hue_b'] = $b;
      }
    } elseif ($hueMin === null && $hueMax !== null) {
      $b = $normHue($hueMax); $a = $b - 1.0;
      if ($a < 0.0) {
        $where[] = '(c.hcl_h >= :hue_a OR c.hcl_h <= :hue_b)';
        $params[':hue_a'] = 360.0 + $a;
        $params[':hue_b'] = $b;
      } else {
        $where[] = '(c.hcl_h >= :hue_a AND c.hcl_h <= :hue_b)';
        $params[':hue_a'] = $a; $params[':hue_b'] = $b;
      }
    } else {
      $minN = $normHue($hueMin);
      $maxN = $normHue($hueMax);
      $wrap = ($hueMin < 0) || ($minN > $maxN);
      if ($wrap) {
        $left = ($hueMin < 0) ? 360.0 + $hueMin : $minN;
        $where[] = '(c.hcl_h >= :hue_a OR c.hcl_h <= :hue_b)';
        $params[':hue_a'] = $left; $params[':hue_b'] = $maxN;
      } else {
        $where[] = '(c.hcl_h >= :hue_a AND c.hcl_h <= :hue_b)';
        $params[':hue_a'] = $minN; $params[':hue_b'] = $maxN;
      }
    }
  }

  // CHROMA
  if ($cMin !== null && $cMax !== null) {
    $where[] = '(c.hcl_c >= :c_min AND c.hcl_c <= :c_max)';
    $params[':c_min'] = $cMin; $params[':c_max'] = $cMax;
  } elseif ($cMin !== null) {
    $where[] = '(c.hcl_c >= :c_min AND c.hcl_c <= :c_min_plus)';
    $params[':c_min'] = $cMin; $params[':c_min_plus'] = $cMin + 1.0;
  } elseif ($cMax !== null) {
    $where[] = '(c.hcl_c >= :c_max_minus AND c.hcl_c <= :c_max)';
    $params[':c_max_minus'] = $cMax - 1.0; $params[':c_max'] = $cMax;
  }

  // LIGHTNESS
  if ($lMin !== null && $lMax !== null) {
    $where[] = '(c.hcl_l >= :l_min AND c.hcl_l <= :l_max)';
    $params[':l_min'] = $clamp($lMin, 0.0, 100.0);
    $params[':l_max'] = $clamp($lMax, 0.0, 100.0);
  } elseif ($lMin !== null) {
    $lA = $clamp($lMin, 0.0, 100.0);
    $lB = $clamp($lMin + 1.0, 0.0, 100.0);
    $where[] = '(c.hcl_l >= :l_a AND c.hcl_l <= :l_b)';
    $params[':l_a'] = $lA; $params[':l_b'] = $lB;
  } elseif ($lMax !== null) {
    $lB = $clamp($lMax, 0.0, 100.0);
    $lA = $clamp($lMax - 1.0, 0.0, 100.0);
    $where[] = '(c.hcl_l >= :l_a AND c.hcl_l <= :l_b)';
    $params[':l_a'] = $lA; $params[':l_b'] = $lB;
  }

  // BRAND (sv.brand IN ...), case-insensitive
  if (!empty($brands)) {
    $ph = [];
    foreach ($brands as $i => $b) {
      $key = ":brand_$i";
      $ph[] = $key;
      $params[$key] = $b;
    }
    $where[] = 'LOWER(sv.brand) IN ('.implode(',', $ph).')';
  }

  // HEX6 exact match (sv.hex6)
  if ($hex6 !== '') {
    $where[] = 'UPPER(sv.hex6) = :hex6';
    $params[':hex6'] = $hex6;
  }

  $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

  // ---------- queries ----------
  $countSql = 'SELECT COUNT(*) AS cnt
               FROM colors c
               JOIN swatch_view sv ON sv.id = c.id' . $whereSql;

  $dataSql  = 'SELECT sv.*
               FROM colors c
               JOIN swatch_view sv ON sv.id = c.id' . $whereSql . '
               ORDER BY c.hcl_h ASC, c.hcl_c ASC, c.hcl_l DESC
               LIMIT :limit OFFSET :offset';

  // Count
  $stmt = $pdo->prepare($countSql);
  foreach ($params as $k => $v) $stmt->bindValue($k, $v);
  $stmt->execute();
  $total = (int)$stmt->fetchColumn();

  // Page
  $stmt = $pdo->prepare($dataSql);
  foreach ($params as $k => $v) $stmt->bindValue($k, $v);
  $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'ok'     => true,
    'limit'  => $limit,
    'offset' => $offset,
    'total'  => $total,
    'count'  => count($rows),
    'items'  => $rows
  ]);
} catch (Throwable $e) {
  adv_log('error', 'exception', ['err' => $e->getMessage()]);
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Server error']);
}
