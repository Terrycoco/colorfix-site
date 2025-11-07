<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Repos\PdoPhotoRepository;

function respond(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $repo = new PdoPhotoRepository($pdo);

  // ---------- Base path resolution ----------
  // Prefer abs_base (full filesystem path). Fallback to rel_base joined to DOCUMENT_ROOT.
  $absBase = isset($_GET['abs_base']) ? trim((string)$_GET['abs_base']) : '';
  $relBase = isset($_GET['rel_base']) ? trim((string)$_GET['rel_base']) : '/photos';

  $doc = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
  if ($absBase !== '') {
    $startAbs = rtrim($absBase, '/');
  } else {
    $rel = (str_starts_with($relBase, '/')) ? $relBase : ('/' . $relBase);
    $startAbs = rtrim($doc . $rel, '/');
  }

  $diag = [
    'doc_root'  => $doc,
    'abs_base'  => $absBase,
    'rel_base'  => $relBase,
    'start_abs' => $startAbs,
    'exists'    => file_exists($startAbs),
    'is_dir'    => is_dir($startAbs),
  ];

  if (!is_dir($startAbs)) {
    // helpful sibling listing of parent
    $parent = rtrim(dirname($startAbs), '/');
    $listing = [];
    if (is_dir($parent)) {
      foreach (@scandir($parent) ?: [] as $n) {
        if ($n === '.' || $n === '..') continue;
        $p = $parent . '/' . $n;
        $listing[] = ['name'=>$n, 'is_dir'=>is_dir($p), 'path'=>$p];
      }
    }
    respond(400, ['error'=>'start_not_dir'] + $diag + ['parent'=> $parent, 'parent_listing'=>$listing]);
  }

  // ---------- Find /YYYY/YYYY-MM/PHO_* ----------
  $yearDirs = glob($startAbs . '/*', GLOB_ONLYDIR) ?: [];
  $assetDirs = [];
  foreach ($yearDirs as $y) {
    $ymDirs = glob($y . '/*', GLOB_ONLYDIR) ?: [];
    foreach ($ymDirs as $ym) {
      foreach (glob($ym . '/PHO_*', GLOB_ONLYDIR) ?: [] as $assetDir) {
        if (preg_match('/\/PHO_[A-Z0-9]+$/', $assetDir)) $assetDirs[] = $assetDir;
      }
    }
  }

  $restored = [];
  $skipped  = [];
  $missing  = [];

  foreach ($assetDirs as $dir) {
    $assetId = basename($dir);
    $photo = $repo->getPhotoByAssetId($assetId);
    if (!$photo) {
      $ins = $repo->createPhotoShell($assetId, null, null, null, null, null);
      $photo = $repo->getPhotoById((int)$ins['id']);
      if (!$photo) { $missing[] = $assetId; continue; }
    }
    $photoId = (int)$photo['id'];

    // repaired: /PHO_*/PHO_*_repaired_1600.jpg
    $rep = $dir . '/' . $assetId . '_repaired_1600.jpg';
    if (is_file($rep)) {
      $pi = @getimagesize($rep);
      $rel = '/' . ltrim(substr($rep, strlen($doc)), '/');
      $repo->upsertVariant($photoId, 'repaired_base', null, $rel, $pi['mime'] ?? 'image/jpeg', @filesize($rep) ?: null, $pi ? (int)$pi[0] : null, $pi ? (int)$pi[1] : null);
      if ($pi && ((int)$photo['width'] === 0 || (int)$photo['height'] === 0)) {
        $repo->updatePhotoSize($photoId, (int)$pi[0], (int)$pi[1]);
      }
      $restored[] = ['asset_id'=>$assetId, 'kind'=>'repaired_base', 'path'=>$rel];
    }

    // prepared: /PHO_*/prepared/PHO_*_prepared_1600.jpg
    $prep = $dir . '/prepared/' . $assetId . '_prepared_1600.jpg';
    if (is_file($prep)) {
      $pi = @getimagesize($prep);
      $rel = '/' . ltrim(substr($prep, strlen($doc)), '/');
      $repo->upsertVariant($photoId, 'prepared_base', null, $rel, $pi['mime'] ?? 'image/jpeg', @filesize($prep) ?: null, $pi ? (int)$pi[0] : null, $pi ? (int)$pi[1] : null);
      if ($pi && ((int)$photo['width'] === 0 || (int)$photo['height'] === 0)) {
        $repo->updatePhotoSize($photoId, (int)$pi[0], (int)$pi[1]);
      }
      $restored[] = ['asset_id'=>$assetId, 'kind'=>'prepared_base', 'path'=>$rel];
    } else {
      $skipped[] = ['asset_id'=>$assetId, 'reason'=>'no prepared_base found'];
    }

    // masks: /PHO_*/masks/*.png
    foreach (glob($dir . '/masks/*.png') ?: [] as $mf) {
      if (!is_file($mf)) continue;
      $role = preg_replace('/[^a-z0-9\-]+/', '', strtolower(pathinfo($mf, PATHINFO_FILENAME)));
      if ($role === '') continue;

      $mi = @getimagesize($mf);
      $rel = '/' . ltrim(substr($mf, strlen($doc)), '/');
      $repo->upsertVariant($photoId, "mask:{$role}", $role, $rel, $mi['mime'] ?? 'image/png', @filesize($mf) ?: null, $mi ? (int)$mi[0] : null, $mi ? (int)$mi[1] : null);
      $restored[] = ['asset_id'=>$assetId, 'kind'=>"mask:{$role}", 'role'=>$role, 'path'=>$rel];
    }
  }

  respond(200, [
    'ok'=>true,
    'diag'=>$diag,
    'scanned'=>count($assetDirs),
    'restored'=>$restored,
    'skipped'=>$skipped,
    'missing_photo_rows'=>$missing
  ]);

} catch (Throwable $e) {
  respond(500, ['error'=>'reindex_failed','message'=>$e->getMessage()]);
}
