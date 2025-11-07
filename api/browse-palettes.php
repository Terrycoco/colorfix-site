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

try {
    // Merge GET + JSON (JSON wins on conflicts)
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    $in = is_array($json) ? array_merge($_GET, $json) : $_GET;

    // Canonical params ONLY (no aliases):
    // - anchors: exact_anchor_cluster_ids (array or CSV string)
    // - include_close: 0/1 (or true/false)
    if (isset($in['exact_anchor_cluster_ids']) && is_string($in['exact_anchor_cluster_ids'])) {
        $in['exact_anchor_cluster_ids'] = preg_split('/[,\s]+/', trim($in['exact_anchor_cluster_ids']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    // Normalize include_close to boolean (no aliasing to match_mode)
    $includeClose = false;
    if (array_key_exists('include_close', $in)) {
        $v = $in['include_close'];
        $includeClose = ($v === 1 || $v === '1' || $v === true || $v === 'true' || $v === 'TRUE');
    }
    $in['include_close'] = $includeClose;

    // Controller
    $tierA = new PaletteTierAService($pdo);
    $ctl   = new PaletteController($tierA, $pdo);

    $out = $ctl->browseByAnchors($in);
    echo json_encode($out, JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    @file_put_contents(__DIR__ . '/logs/browse-palettes.log', date('c') . ' ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
