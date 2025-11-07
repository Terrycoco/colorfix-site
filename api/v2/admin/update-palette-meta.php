<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(200); exit; }

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\repos\PdoPaletteRepository; // note: lower-case 'repos' to match your file

function norm_tags(mixed $v): array {
  if (is_string($v)) $v = preg_split('/[,\|]+/u', $v, -1, PREG_SPLIT_NO_EMPTY);
  if (!is_array($v)) return [];
  $out = [];
  foreach ($v as $t) {
    $t = preg_replace('/\s+/u', ' ', trim((string)$t));
    if ($t !== '') $out[strtolower($t)] = true; // dedupe case-insensitively
  }
  return array_keys($out);
}

try {
  $raw  = file_get_contents('php://input') ?: '';
  $json = json_decode($raw, true);
  if (!is_array($json)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Bad JSON']); exit; }

  $paletteId = (int)($json['palette_id'] ?? 0);
  if ($paletteId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'palette_id required']); exit; }

  $nickname  = array_key_exists('nickname',  $json) ? (string)($json['nickname']   ?? '') : null;
  $terrySays = array_key_exists('terry_says', $json) ? (string)($json['terry_says'] ?? '') : null;
  $terryFav  = (int)($json['terry_fav'] ?? 0) ? 1 : 0;
  $tags      = norm_tags($json['tags'] ?? []);

  $repo = new PdoPaletteRepository($pdo);

  if (!$repo->updatePaletteMeta($paletteId, $nickname, $terrySays, $terryFav)) {
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Update failed']); exit;
  }

  // Replace tags in one shot
  $repo->replaceTagsForPalette($paletteId, $tags);

  echo json_encode([
    'ok'   => true,
    'meta' => [
      'nickname'   => $nickname,
      'terry_says' => $terrySays,
      'terry_fav'  => $terryFav,
      'tags'       => $tags,
    ],
  ], JSON_UNESCAPED_SLASHES);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Internal Server Error']);
  exit;
}
