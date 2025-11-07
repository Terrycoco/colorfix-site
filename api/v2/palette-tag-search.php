<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(200); exit(); }

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Repos\PdoPaletteRepository;

try {
  $q     = isset($_GET['q']) ? (string)$_GET['q'] : '';
  $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;

  $repo  = new PdoPaletteRepository($pdo);
  $items = $repo->searchPaletteTags($q, $limit);

  echo json_encode(['items' => $items], JSON_UNESCAPED_SLASHES);
  exit;

} catch (Throwable $e) {
  @mkdir(__DIR__ . '/logs', 0775, true);
  @file_put_contents(__DIR__ . '/logs/palette-tag-search.log', date('c').' '.$e->getMessage()."\n", FILE_APPEND);
  http_response_code(500);
  echo json_encode(['error' => 'Internal Server Error']);
  exit;
}
