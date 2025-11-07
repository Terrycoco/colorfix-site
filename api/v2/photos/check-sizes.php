<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Repos\PdoPhotoRepository;

function respond($c,$p){ http_response_code($c); echo json_encode($p, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }

try {
  $asset = (string)($_GET['asset_id'] ?? '');
  if ($asset === '') respond(400, ['error'=>'bad_request','message'=>'asset_id required']);

  $repo = new PdoPhotoRepository($pdo);
  $photo = $repo->getPhotoByAssetId($asset);
  if (!$photo) respond(404, ['error'=>'not_found','message'=>"asset not found: $asset"]);

  $variants = $repo->listVariants((int)$photo['id']);
  $doc = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
  $toAbs = fn(string $rel) => $doc . '/' . ltrim($rel, '/');

  $prepared = null;
  $masks = [];
  $repaired = null;

  foreach ($variants as $v) {
    $kind = (string)$v['kind'];
    if ($kind === 'prepared_base') $prepared = $v;
    elseif ($kind === 'repaired_base') $repaired = $v;
    elseif (str_starts_with($kind, 'mask:')) $masks[] = $v;
  }

  $probe = function(?array $row) use ($toAbs) {
    if (!$row) return null;
    $abs = $toAbs((string)$row['path']);
    $i = @getimagesize($abs);
    return [
      'path' => (string)$row['path'],
      'exists' => (int)is_file($abs),
      'w' => $i ? (int)$i[0] : null,
      'h' => $i ? (int)$i[1] : null,
    ];
  };

  $prepInfo = $probe($prepared);
  $repInfo  = $probe($repaired);
  $maskInfos = [];
  foreach ($masks as $m) {
    $role = (string)($m['role'] ?? substr((string)$m['kind'], 5));
    $maskInfos[] = ['role'=>$role] + $probe($m);
  }

  $issues = [];
  if (!$prepInfo || !$prepInfo['exists']) $issues[] = 'prepared_missing';
  // Check masks vs prepared
  if ($prepInfo && $prepInfo['w'] && $prepInfo['h']) {
    foreach ($maskInfos as $mi) {
      if ($mi['exists'] && ($mi['w'] !== $prepInfo['w'] || $mi['h'] !== $prepInfo['h'])) {
        $issues[] = "mask_size_mismatch:{$mi['role']} ({$mi['w']}x{$mi['h']} vs prepared {$prepInfo['w']}x{$prepInfo['h']})";
      }
    }
  }

  respond(200, [
    'asset_id' => $asset,
    'prepared' => $prepInfo,
    'repaired' => $repInfo,
    'masks'    => $maskInfos,
    'issues'   => array_values(array_unique($issues)),
    'hint'     => 'Prepared and ALL masks must match. Repaired can differ (UI-only).'
  ]);

} catch (Throwable $e) {
  respond(500, ['error'=>'server','message'=>$e->getMessage()]);
}
