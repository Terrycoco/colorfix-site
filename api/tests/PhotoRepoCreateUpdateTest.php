<?php
declare(strict_types=1);

/**
 * PhotoRepoCreateAndUpdateTest
 *
 * Scope:
 * - createPhotoShell(string, ?string, ?string, ?string, ?string, ?string): ['id'=>int]
 * - getPhotoByAssetId(): row
 * - getPhotoById(): row
 * - updatePhotoSize(): updates width/height
 *
 * Tables truncated:
 *   photos, photos_variants, photos_tags, photos_mask_stats
 */

use App\Repos\PdoPhotoRepository;

//
// ---------- Bootstrap ----------
$__here = __DIR__;
require_once $__here . '/../autoload.php';
require_once $__here . '/../db.php'; // must provide $pdo (PDO)

if (!isset($pdo) || !($pdo instanceof PDO)) {
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    $pdo = $GLOBALS['pdo'];
  } else {
    fwrite(STDERR, "[PhotoRepoCreateAndUpdateTest] ERROR: \$pdo not available from /api/db.php\n");
    exit(1);
  }
}

// Minimal assert helpers
if (!function_exists('assert_true')) {
  function assert_true($cond, string $msg = 'assert_true failed'): void {
    if (!$cond) throw new RuntimeException($msg);
  }
}
if (!function_exists('assert_eq')) {
  function assert_eq($a, $b, string $msg = 'assert_eq failed'): void {
    if ($a !== $b) {
      $sa = var_export($a, true);
      $sb = var_export($b, true);
      throw new RuntimeException("$msg\nExpected: $sb\nActual:   $sa");
    }
  }
}
if (!function_exists('assert_not_null')) {
  function assert_not_null($v, string $msg = 'assert_not_null failed'): void {
    if ($v === null) throw new RuntimeException($msg);
  }
}

// ---------- Helpers ----------
function truncate_photo_tables(PDO $pdo): void {
  $pdo->exec('DELETE FROM photos_mask_stats');
  $pdo->exec('DELETE FROM photos_tags');
  $pdo->exec('DELETE FROM photos_variants');
  $pdo->exec('DELETE FROM photos');
}
function fetch_one(PDO $pdo, string $sql, array $params = []): ?array {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row === false ? null : $row;
}

// ---------- Test Body ----------
$repo = new PdoPhotoRepository($pdo);
truncate_photo_tables($pdo);

try {
  // 1) createPhotoShell (matches your exact signature and return)
  $assetId = 'PHO_TEST_REPO_001';
  $ret = $repo->createPhotoShell(
    $assetId,
    null,   // style_primary
    null,   // verdict
    null,   // status
    null,   // lighting
    null,   // rights_status
    null    // category_path
  );
  assert_true(is_array($ret) && isset($ret['id']), 'createPhotoShell should return ["id"=>int]');
  $photoId = (int)$ret['id'];
  assert_true($photoId > 0, 'createPhotoShell id should be positive');

  // Verify row exists via asset_id
  $row = $repo->getPhotoByAssetId($assetId);
  assert_not_null($row, 'getPhotoByAssetId should return a row');
  assert_eq((int)$row['id'], $photoId, 'asset lookup must return same id');
  assert_eq((string)$row['asset_id'], $assetId, 'asset_id should match');

  // Newly created shell defaults width/height to 0
  assert_eq((int)$row['width'], 0, 'new shell width should be 0');
  assert_eq((int)$row['height'], 0, 'new shell height should be 0');

  // 2) updatePhotoSize
  $w = 1920; $h = 1280;
  $repo->updatePhotoSize($photoId, $w, $h);

  $row2 = $repo->getPhotoById($photoId);
  assert_not_null($row2, 'Row should exist after updatePhotoSize');
  assert_eq((int)$row2['width'],  $w, 'width should update');
  assert_eq((int)$row2['height'], $h, 'height should update');

  // 3) Second update sanity
  $w2 = 800; $h2 = 600;
  $repo->updatePhotoSize($photoId, $w2, $h2);
  $row3 = fetch_one($pdo, 'SELECT width, height FROM photos WHERE id = ?', [$photoId]);
  assert_not_null($row3, 'Row should exist for second update');
  assert_eq((int)$row3['width'],  $w2, 'width should update (second pass)');
  assert_eq((int)$row3['height'], $h2, 'height should update (second pass)');

  if (PHP_SAPI === 'cli' && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    echo "PhotoRepoCreateAndUpdateTest: PASS\n";
  }
} catch (Throwable $e) {
  $msg = "[PhotoRepoCreateAndUpdateTest] FAIL: " . $e->getMessage();
  if (PHP_SAPI === 'cli') {
    fwrite(STDERR, $msg . "\n");
  } else {
    echo $msg;
  }
  http_response_code(500);
  exit(1);
}
