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

  $sanitizeCategory = function(string $value): ?string {
    $trim = trim($value);
    if ($trim === '') return null;
    $trim = str_replace('\\', '/', $trim);
    $segments = array_filter(array_map(function ($seg) {
      $seg = strtolower(trim($seg));
      $seg = preg_replace('/[^a-z0-9\-]+/', '-', $seg);
      $seg = trim($seg, '-');
      return $seg;
    }, explode('/', $trim)));
    if (!$segments) return null;
    return implode('/', $segments);
  };

  $findCategoryPath = function(string $assetDir) use ($startAbs, $sanitizeCategory): ?string {
    $rel = ltrim(substr($assetDir, strlen($startAbs)), '/');
    if ($rel === '' || $rel === false) return null;
    $parent = dirname($rel);
    if ($parent === '.' || $parent === '') return null;
    return $sanitizeCategory($parent);
  };

  // ---------- Find PHO_* at any depth ----------
  $assetDirs = [];
  $iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($startAbs, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  foreach ($iter as $item) {
    if (!$item->isDir()) continue;
    $name = $item->getFilename();
    if (preg_match('/^PHO_[A-Z0-9]+$/i', $name)) {
      $assetDirs[] = $item->getPathname();
    }
  }

  $restored = [];
  $skipped  = [];
  $missing  = [];

  foreach ($assetDirs as $dir) {
    $assetId = basename($dir);
    $photo = $repo->getPhotoByAssetId($assetId);
    $categoryPath = $findCategoryPath($dir);
    if (!$photo) {
      $ins = $repo->createPhotoShell($assetId, null, null, null, null, null, $categoryPath);
      $photo = $repo->getPhotoById((int)$ins['id']);
      if (!$photo) { $missing[] = $assetId; continue; }
    } elseif ($categoryPath && empty($photo['category_path'])) {
      $repo->updatePhotoCategoryPath((int)$photo['id'], $categoryPath);
    }
    $photoId = (int)$photo['id'];

    $relFromDoc = function(string $abs) use ($doc): string {
      $rel = '/' . ltrim(substr($abs, strlen($doc)), '/');
      return $rel === '/' ? $abs : $rel;
    };

    // repaired: /PHO_*/repaired/base.* or *_repaired_*.jpg
    $repDir = $dir . '/repaired';
    $repFile = '';
    if (is_dir($repDir)) {
      $cands = glob($repDir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];
      foreach ($cands as $f) {
        $base = strtolower(basename($f));
        if (str_starts_with($base, 'base.')) { $repFile = $f; break; }
        if (str_contains($base, 'repaired')) { $repFile = $f; break; }
      }
      if (!$repFile && count($cands) === 1) $repFile = $cands[0];
    } else {
      $legacy = $dir . '/' . $assetId . '_repaired_1600.jpg';
      if (is_file($legacy)) $repFile = $legacy;
    }
    if ($repFile && is_file($repFile)) {
      $pi = @getimagesize($repFile);
      $rel = $relFromDoc($repFile);
      $repo->upsertVariant($photoId, 'repaired_base', null, $rel, $pi['mime'] ?? 'image/jpeg', @filesize($repFile) ?: null, $pi ? (int)$pi[0] : null, $pi ? (int)$pi[1] : null, []);
      if ($pi && ((int)$photo['width'] === 0 || (int)$photo['height'] === 0)) {
        $repo->updatePhotoSize($photoId, (int)$pi[0], (int)$pi[1]);
      }
      $restored[] = ['asset_id'=>$assetId, 'kind'=>'repaired_base', 'path'=>$rel];
    }

    // prepared: /PHO_*/prepared/base.* or *_prepared_*.jpg (plus tiers)
    $prepDir = $dir . '/prepared';
    $prepBase = '';
    $prepTiers = ['dark' => '', 'medium' => '', 'light' => ''];
    if (is_dir($prepDir)) {
      $cands = glob($prepDir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];
      foreach ($cands as $f) {
        $base = strtolower(basename($f));
        if (preg_match('/_prepared_(dark|medium|light)/', $base, $m)) {
          $prepTiers[$m[1]] = $f;
          continue;
        }
        if (preg_match('/^(dark|medium|light)\\./', $base, $m)) {
          $prepTiers[$m[1]] = $f;
          continue;
        }
        if (str_starts_with($base, 'base.')) {
          $prepBase = $f;
          continue;
        }
        if (str_contains($base, 'prepared') && $prepBase === '') {
          $prepBase = $f;
          continue;
        }
      }
      if (!$prepBase && count($cands) === 1) $prepBase = $cands[0];
    } else {
      $legacy = $dir . '/prepared/' . $assetId . '_prepared_1600.jpg';
      if (is_file($legacy)) $prepBase = $legacy;
    }
    if ($prepBase && is_file($prepBase)) {
      $pi = @getimagesize($prepBase);
      $rel = $relFromDoc($prepBase);
      $repo->upsertVariant($photoId, 'prepared_base', null, $rel, $pi['mime'] ?? 'image/jpeg', @filesize($prepBase) ?: null, $pi ? (int)$pi[0] : null, $pi ? (int)$pi[1] : null, []);
      if ($pi && ((int)$photo['width'] === 0 || (int)$photo['height'] === 0)) {
        $repo->updatePhotoSize($photoId, (int)$pi[0], (int)$pi[1]);
      }
      $restored[] = ['asset_id'=>$assetId, 'kind'=>'prepared_base', 'path'=>$rel];
    } else {
      $skipped[] = ['asset_id'=>$assetId, 'reason'=>'no prepared_base found'];
    }
    foreach ($prepTiers as $role => $f) {
      if (!$f || !is_file($f)) continue;
      $pi = @getimagesize($f);
      $rel = $relFromDoc($f);
      $repo->upsertVariant($photoId, 'prepared', $role, $rel, $pi['mime'] ?? 'image/jpeg', @filesize($f) ?: null, $pi ? (int)$pi[0] : null, $pi ? (int)$pi[1] : null, []);
      $restored[] = ['asset_id'=>$assetId, 'kind'=>'prepared', 'role'=>$role, 'path'=>$rel];
    }

    // textures: /PHO_*/textures/overlay.png
    $tex = $dir . '/textures/overlay.png';
    if (is_file($tex)) {
      $pi = @getimagesize($tex);
      $rel = $relFromDoc($tex);
      $repo->upsertVariant($photoId, 'texture', null, $rel, $pi['mime'] ?? 'image/png', @filesize($tex) ?: null, $pi ? (int)$pi[0] : null, $pi ? (int)$pi[1] : null, []);
      $restored[] = ['asset_id'=>$assetId, 'kind'=>'texture', 'path'=>$rel];
    }

    // masks: /PHO_*/masks/*.png
    foreach (glob($dir . '/masks/*.png') ?: [] as $mf) {
      if (!is_file($mf)) continue;
      $role = preg_replace('/[^a-z0-9\-]+/', '', strtolower(pathinfo($mf, PATHINFO_FILENAME)));
      if ($role === '') continue;

      $mi = @getimagesize($mf);
      $rel = $relFromDoc($mf);
      $repo->upsertVariant($photoId, "mask:{$role}", $role, $rel, $mi['mime'] ?? 'image/png', @filesize($mf) ?: null, $mi ? (int)$mi[0] : null, $mi ? (int)$mi[1] : null, []);
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
