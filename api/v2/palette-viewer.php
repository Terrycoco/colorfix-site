<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Repos\PdoAppliedPaletteRepository;
use App\Repos\PdoSavedPaletteRepository;
use App\Repos\PdoPhotoRepository;
use App\Services\PhotoRenderingService;
use App\Services\PaletteViewerService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $source = isset($_GET['source']) ? strtolower(trim((string)$_GET['source'])) : '';
    if (!in_array($source, ['applied', 'saved'], true)) {
        respond(['ok' => false, 'error' => 'source must be applied or saved'], 400);
    }

    $appliedRepo = new PdoAppliedPaletteRepository($pdo);
    $savedRepo = new PdoSavedPaletteRepository($pdo);
    $photoRepo = new PdoPhotoRepository($pdo);
    $renderSvc = new PhotoRenderingService($photoRepo, $pdo);
    $svc = new PaletteViewerService($appliedRepo, $savedRepo, $renderSvc);

    if ($source === 'applied') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $data = $svc->getApplied($id);
        respond(['ok' => true, 'data' => $data]);
    }

    $hash = isset($_GET['hash']) ? trim((string)$_GET['hash']) : '';
    $data = $svc->getSaved($hash);
    respond(['ok' => true, 'data' => $data]);
} catch (\InvalidArgumentException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (\RuntimeException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 404);
} catch (\Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
