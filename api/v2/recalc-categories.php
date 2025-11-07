<?php
declare(strict_types=1);

/**
 * v2 category recalc endpoint
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Repos\PdoCategoryRepository;  // <-- v2 namespace
use App\Services\CategoriesService;   // <-- v2 namespace
use App\Lib\Logger;                   // <-- v2 namespace

try {
    $batch     = isset($_GET['batch']) ? max(100, (int)$_GET['batch']) : 2000;
    $canonical = isset($_GET['canonicalize']) ? (bool)$_GET['canonicalize'] : true;

    $repo = new PdoCategoryRepository($pdo);
    $svc  = new CategoriesService($repo);

    $summary = $svc->recalcAll($batch, $canonical);

    echo json_encode([
        'status'  => 'success',
        'summary' => $summary,
    ], JSON_UNESCAPED_SLASHES);

} catch (\Throwable $e) {
    // log but don't explode if Logger fails to autoload
    if (class_exists(Logger::class, true)) {
        Logger::error('Category recalc failed', ['error' => $e->getMessage()]);
    }
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}
