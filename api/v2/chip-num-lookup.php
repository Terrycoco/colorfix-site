<?php
declare(strict_types=1);

// Preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=UTF-8');
@ini_set('display_errors','0');
@ini_set('log_errors','1');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

function jexit(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  $q      = isset($_GET['q'])     ? trim((string)$_GET['q'])     : '';
  $brand  = isset($_GET['brand']) ? trim((string)$_GET['brand']) : 'de';
  $limit  = isset($_GET['limit']) ? (int)$_GET['limit']          : 50;

  if ($limit <= 0 || $limit > 200) $limit = 50;

  // Require at least 1 character to avoid table dump
  if ($q === '') jexit(200, ['ok'=>true, 'rows'=>[]]);

  // WHERE (name prefix only — identical to v1)
  $where = "name LIKE :qprefix";
  $args  = [':qprefix' => $q . '%'];

  if ($brand !== '') {
    $where .= " AND LOWER(brand) = :brand";
    $args[':brand'] = strtolower($brand);
  }

  $sql = "SELECT id, name, brand, code, chip_num
            FROM colors
           WHERE $where
           ORDER BY name
           LIMIT :limit";

  $stmt = $pdo->prepare($sql);
  foreach ($args as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_STR);
  }
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->execute();

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  jexit(200, ['ok'=>true, 'rows'=>$rows]);

} catch (Throwable $e) {
  jexit(500, ['ok'=>false, 'error'=>$e->getMessage()]);
}
