<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Repos\PdoPhotoRepository;
use App\Services\MaskOverlayService;

function respond(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  if (!isset($pdo) || !$pdo instanceof PDO) {
    respond(500, ['ok' => false, 'error' => 'DB not initialized']);
  }

  $assetId = trim((string)($_GET['asset_id'] ?? $_POST['asset_id'] ?? ''));
  $force = (string)($_GET['force'] ?? $_POST['force'] ?? '') === '1';
  $allowAll = (string)($_GET['all'] ?? $_POST['all'] ?? '') === '1';
  $limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 50;
  $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

  $repo = new PdoPhotoRepository($pdo);
  $svc = new MaskOverlayService($repo, $pdo);

  if ($assetId !== '') {
    $res = $svc->applyDefaultsForAsset($assetId, $force, true);
    respond(200, ['ok' => true, 'asset_id' => $assetId, 'result' => $res]);
  }

  if (!$allowAll) {
    respond(400, ['ok' => false, 'error' => 'asset_id required (or pass all=1)']);
  }

  $rows = $repo->listPhotos(['limit' => $limit, 'offset' => $offset]);
  $summary = [
    'updated' => 0,
    'skipped' => 0,
    'processed' => 0,
    'next_offset' => null,
  ];
  $items = [];
  foreach ($rows as $row) {
    $aid = (string)($row['asset_id'] ?? '');
    if ($aid === '') continue;
    $res = $svc->applyDefaultsForAsset($aid, $force, true);
    $summary['updated'] += (int)($res['updated'] ?? 0);
    $summary['skipped'] += (int)($res['skipped'] ?? 0);
    $summary['processed'] += 1;
    $items[] = $res;
  }
  if (count($rows) === $limit) {
    $summary['next_offset'] = $offset + $limit;
  }

  respond(200, [
    'ok' => true,
    'limit' => $limit,
    'offset' => $offset,
    'summary' => $summary,
    'items' => $items,
  ]);
} catch (Throwable $e) {
  respond(500, ['ok' => false, 'error' => $e->getMessage()]);
}
