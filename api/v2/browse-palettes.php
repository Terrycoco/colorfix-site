<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(200); exit(); }

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Controllers\PaletteController;
use App\Services\PaletteTierAService;
use App\Repos\PdoPaletteRepository;

try {
  // Quick diag (optional)
  if (isset($_GET['where'])) {
    $d = __DIR__; $t = sys_get_temp_dir();
    @mkdir("$d/logs", 0775, true);
    @file_put_contents("$d/logs/browse-palettes.log", date('c')." ping\n", FILE_APPEND);
    @file_put_contents("$t/browse-palettes.log",       date('c')." ping\n", FILE_APPEND);
    echo "dir=$d\nlogDir=$d/logs\nlogFile=$d/logs/browse-palettes.log\ntmpDir=$t\ntmpFile=$t/browse-palettes.log\n";
    exit();
  }

  // Merge GET + JSON (JSON wins)
  $raw  = file_get_contents('php://input') ?: '';
  $json = json_decode($raw, true);
  $in   = is_array($json) ? array_merge($_GET, $json) : $_GET;

  // Normalize anchors (array or CSV string ok)
  if (isset($in['exact_anchor_cluster_ids']) && is_string($in['exact_anchor_cluster_ids'])) {
    $in['exact_anchor_cluster_ids'] = preg_split('/[,\s]+/', trim($in['exact_anchor_cluster_ids']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
  }

  // Normalize include_close to boolean
  $includeClose = false;
  if (array_key_exists('include_close', $in)) {
    $v = $in['include_close'];
    $includeClose = ($v === 1 || $v === '1' || $v === true || $v === 'true' || $v === 'TRUE');
  }
  $in['include_close'] = $includeClose;

  // Support legacy "match_mode" if provided (maps to include_close)
  $mode = isset($in['match_mode']) ? (string)$in['match_mode'] : '';
  if ($mode === 'includes_close') $in['include_close'] = true;

  // Derive match_mode (optional) for downstream logging
  if (!isset($in['match_mode']) || !is_string($in['match_mode']) || $in['match_mode'] === '') {
    $in['match_mode'] = $in['include_close'] ? 'includes_close' : 'includes';
  }
    // Merge GET + JSON (JSON wins)
  $raw  = file_get_contents('php://input') ?: '';
  $json = json_decode($raw, true);
  $in   = is_array($json) ? array_merge($_GET, $json) : $_GET;

  // --- NEW: normalize tag filters (accept CSV or array) ---
  foreach (['include_tags_any','include_tags_all'] as $key) {
    if (!isset($in[$key])) continue;
    $v = $in[$key];
    if (is_string($v)) {
      $parts = preg_split('/[,\s]+/', trim($v), -1, PREG_SPLIT_NO_EMPTY) ?: [];
      $in[$key] = array_values(array_unique($parts));
    } elseif (is_array($v)) {
      // coerce scalars to strings and trim empties
      $clean = [];
      foreach ($v as $s) {
        $s = trim((string)$s);
        if ($s !== '') $clean[] = $s;
      }
      $in[$key] = array_values(array_unique($clean));
    } else {
      unset($in[$key]);
    }
  }


  // Controller
  $tierA = new PaletteTierAService($pdo, new PdoPaletteRepository($pdo));
  $ctl   = new PaletteController($tierA, $pdo);

  $out = $ctl->browseByAnchors($in);
  echo json_encode($out, JSON_UNESCAPED_SLASHES);
  exit;

} catch (Throwable $e) {
  @mkdir(__DIR__ . '/logs', 0775, true);
  @file_put_contents(
    __DIR__ . '/logs/browse-palettes.log',
    date('c') . ' ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n",
    FILE_APPEND
  );

  http_response_code(500);

  $debug = (isset($_GET['debug']) && $_GET['debug'] == '1');
  echo json_encode([
    'error' => $debug ? $e->getMessage() : 'Internal Server Error',
    'debug' => $debug ? $e->getTraceAsString() : null,
  ]);
  exit;
}
