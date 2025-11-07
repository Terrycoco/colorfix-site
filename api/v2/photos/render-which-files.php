<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Repos\PdoPhotoRepository;

function j($x){ echo json_encode($x, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT); exit; }

$assetId = trim((string)($_GET['asset_id'] ?? ''));
$role    = trim((string)($_GET['role'] ?? ''));

if ($assetId === '') j(['error'=>'missing asset_id']);
if ($role === '')    j(['error'=>'missing role']);

$repo  = new PdoPhotoRepository($pdo);
$photo = $repo->getPhotoByAssetId($assetId);
if (!$photo) j(['error'=>"asset not found: {$assetId}"]);

$vars = $repo->listVariants((int)$photo['id']);

$prepared = null;
$mask     = null;
foreach ($vars as $v) {
  if (($v['kind'] ?? '') === 'prepared_base') $prepared = $v;
  if (($v['kind'] ?? '') === 'mask:'.$role)   $mask     = $v;
}

if (!$prepared) j(['error'=>'no prepared_base variant found', 'asset_id'=>$assetId, 'variants'=>$vars]);
if (!$mask)     j(['error'=>"no mask for role '{$role}'", 'asset_id'=>$assetId, 'variants'=>$vars]);

$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($docRoot === '') $docRoot = dirname(__DIR__, 2); // fallback

$prepRel = (string)$prepared['path'];
$maskRel = (string)$mask['path'];

$prepAbs = $docRoot . (str_starts_with($prepRel, '/') ? $prepRel : '/'.$prepRel);
$maskAbs = $docRoot . (str_starts_with($maskRel, '/') ? $maskRel : '/'.$maskRel);

$prepSz = @getimagesize($prepAbs);
$maskSz = @getimagesize($maskAbs);

$out = [
  'asset_id'  => $assetId,
  'role'      => $role,
  'document_root' => $docRoot,
  'prepared'  => [
    'kind'  => $prepared['kind'],
    'path_rel' => $prepRel,
    'path_abs' => $prepAbs,
    'exists'   => is_file($prepAbs) ? 1 : 0,
    'w'        => $prepSz[0] ?? null,
    'h'        => $prepSz[1] ?? null,
  ],
  'mask'      => [
    'kind'  => $mask['kind'],
    'path_rel' => $maskRel,
    'path_abs' => $maskAbs,
    'exists'   => is_file($maskAbs) ? 1 : 0,
    'w'        => $maskSz[0] ?? null,
    'h'        => $maskSz[1] ?? null,
  ],
  'db_variants_sample' => [
    'prepared_db_w' => $prepared['width'] ?? null,
    'prepared_db_h' => $prepared['height'] ?? null,
    'mask_db_w'     => $mask['width'] ?? null,
    'mask_db_h'     => $mask['height'] ?? null,
  ],
];

j($out);
