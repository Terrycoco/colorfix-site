<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

try {
  require_once __DIR__ . '/../../autoload.php';
  require_once __DIR__ . '/../../db.php';

  $assetId = isset($_GET['asset_id']) ? (string)$_GET['asset_id'] : '';
  if ($assetId === '') {
    http_response_code(400);
    echo json_encode(['error'=>'bad_request','message'=>'asset_id required']); exit;
  }

  $ctl = new \App\Controllers\PhotosController($pdo);
  $res = $ctl->getAsset(['asset_id'=>$assetId]);

  http_response_code(200);
  echo json_encode($res, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'error'   => 'server',
    'message' => $e->getMessage(),
    'file'    => $e->getFile(),
    'line'    => $e->getLine(),
    'type'    => get_class($e),
  ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
