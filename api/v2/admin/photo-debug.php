<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use PDO;

function respond(int $code, array $payload) {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}

// Prefer /photos; fallback to /colorfix/photos
if (!defined('PHOTOS_ROOT')) {
  $doc = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
  $primary = $doc . '/photos';
  $legacy  = $doc . '/colorfix/photos';
  define('PHOTOS_ROOT', is_dir($primary) ? $primary : $legacy);
}

try {
  $assetId = trim((string)($_GET['asset_id'] ?? ''));
  if ($assetId === '') respond(400, ['error' => 'asset_id required']);

  /** @var PDO $pdo */
  $pdo = $GLOBALS['pdo'] ?? null;
  if (!$pdo instanceof PDO) throw new RuntimeException('DB not initialized');

  // Look up photo
  $stmt = $pdo->prepare('SELECT id, width, height, created_at FROM photos WHERE asset_id = ?');
  $stmt->execute([$assetId]);
  $photo = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$photo) respond(404, ['error' => "asset not found: $assetId", 'PHOTOS_ROOT' => PHOTOS_ROOT]);

  // Resolve asset dir by glob (YYYY/YYYY-MM/ASSET_ID)
  $matches = glob(PHOTOS_ROOT . '/*/*/' . $assetId, GLOB_ONLYDIR);
  $assetDir = $matches[0] ?? null;

  $fs = [
    'PHOTOS_ROOT' => PHOTOS_ROOT,
    'asset_dir'   => $assetDir,
    'asset_dir_exists' => $assetDir ? is_dir($assetDir) : false,
  ];

  $maskList = [];
  $maskDir  = $assetDir ? ($assetDir . '/masks') : null;
  if ($maskDir && is_dir($maskDir)) {
    $files = scandir($maskDir);
    foreach ($files as $f) {
      if ($f === '.' || $f === '..') continue;
      $p = $maskDir . '/' . $f;
      if (!is_file($p)) continue;
      $maskList[] = [
        'file'  => $f,
        'bytes' => filesize($p),
        'mime'  => @mime_content_type($p) ?: null,
        'size'  => @getimagesize($p) ? (@getimagesize($p)[0] . 'x' . @getimagesize($p)[1]) : null,
      ];
    }
  }

  // DB variants (masks only)
  $stmt = $pdo->prepare('SELECT kind, role, path, bytes, width, height, mime FROM photos_variants WHERE photo_id = ? AND kind LIKE "mask:%" ORDER BY role');
  $stmt->execute([(int)$photo['id']]);
  $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Cross-check DB paths to disk
  $dbFiles = [];
  foreach ($variants as $v) {
    $rel = $v['path'];
    $abs = str_starts_with($rel, PHOTOS_ROOT) ? $rel : (PHOTOS_ROOT . $rel);
    $dbFiles[] = [
      'role'   => $v['role'],
      'kind'   => $v['kind'],
      'rel'    => $rel,
      'abs'    => $abs,
      'on_disk'=> is_file($abs),
      'bytes_fs' => is_file($abs) ? filesize($abs) : null,
      'bytes_db' => $v['bytes'],
      'size_db'  => ($v['width'] && $v['height']) ? ($v['width'].'x'.$v['height']) : null,
      'mime_db'  => $v['mime'],
    ];
  }

  respond(200, [
    'ok' => true,
    'asset' => [
      'asset_id' => $assetId,
      'photo_id' => (int)$photo['id'],
      'size'     => ['w' => (int)$photo['width'], 'h' => (int)$photo['height']],
      'created_at' => $photo['created_at'] ?? null,
    ],
    'filesystem' => $fs,
    'masks_folder' => [
      'exists' => (bool)($maskDir && is_dir($maskDir)),
      'path'   => $maskDir,
      'files'  => $maskList,
    ],
    'db_variants_masks' => $dbFiles,
  ]);

} catch (Throwable $e) {
  respond(500, ['error' => $e->getMessage(), 'PHOTOS_ROOT' => PHOTOS_ROOT]);
}
