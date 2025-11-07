<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

// Adjust these requires to your project layout.
require_once __DIR__ . '/../autoload.php'; // PSR-4 map for App\...
// If you keep db.php separate, it's OK not to include it here; this test avoids DB.

function checkAny(array $candidates): array {
  foreach ($candidates as $fqcn) {
    if (class_exists($fqcn)) return ['found' => true, 'fqcn' => $fqcn];
  }
  return ['found' => false, 'fqcn' => null];
}

$results = [
  'timestamp' => date('c'),
  'classes' => []
];

// Candidates for each piece (we’ll accept whichever exists).
$photosRepoCandidates = [
  'App\\Repos\\PdoPhotoRepository',
  'App\\Repositories\\PdoPhotoRepository',
  'ColorFix\\Repos\\PdoPhotoRepository',
];

$photosControllerCandidates = [
  'App\\Controllers\\PhotosController',
  'ColorFix\\Controllers\\PhotosController',
];

$renderServiceCandidates = [
  'App\\Services\\PhotoRenderingService',
  'ColorFix\\Services\\PhotoRenderingService',
];

$uploadServiceCandidates = [
  'App\\Services\\PhotosUploadService',      // recommended service we may add
  'ColorFix\\Services\\PhotosUploadService',
];

// Check presence
$results['classes']['PdoPhotoRepository']   = checkAny($photosRepoCandidates);
$results['classes']['PhotosController']      = checkAny($photosControllerCandidates);
$results['classes']['PhotoRenderingService'] = checkAny($renderServiceCandidates);
$results['classes']['PhotosUploadService']   = checkAny($uploadServiceCandidates);

// Optional: verify some expected methods if classes exist (no instantiation).
function expectMethods(string $label, ?string $fqcn, array $methods): array {
  if (!$fqcn || !class_exists($fqcn)) {
    return ['ok' => false, 'reason' => 'class_missing', 'missing' => $methods];
  }
  $missing = [];
  foreach ($methods as $m) {
    if (!method_exists($fqcn, $m)) $missing[] = $m;
  }
  return ['ok' => count($missing) === 0, 'missing' => $missing];
}

$results['signatures'] = [
  'PdoPhotoRepository' => expectMethods(
    'PdoPhotoRepository',
    $results['classes']['PdoPhotoRepository']['fqcn'] ?? null,
    [
      'createPhoto',
      'getPhotoById',
      'getPhotoByAssetId',
      'upsertVariant',
      'listPhotos',
    ]
  ),
  'PhotoRenderingService' => expectMethods(
    'PhotoRenderingService',
    $results['classes']['PhotoRenderingService']['fqcn'] ?? null,
    [
      // adjust later if names differ
      'resolveAppliedPalette',
      'computeRoleLightnessTargets',
    ]
  ),
  'PhotosUploadService' => expectMethods(
    'PhotosUploadService',
    $results['classes']['PhotosUploadService']['fqcn'] ?? null,
    [
      'saveBase',
      'saveMask',
      'saveRender',
      'replaceVariant',
    ]
  ),
];

http_response_code(200);
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
