<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoAppliedPaletteRepository;
use App\Repos\PdoPhotoLibraryRepository;
use App\Repos\PdoPhotoRepository;
use App\Repos\PdoSavedPaletteRepository;
use App\Services\AppliedPaletteConversionService;
use App\Services\PhotoLibraryService;
use App\Services\PhotoRenderingService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(['ok' => false, 'error' => 'POST only'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $paletteId = isset($payload['palette_id']) ? (int)$payload['palette_id'] : 0;
    $paletteIds = isset($payload['palette_ids']) && is_array($payload['palette_ids']) ? $payload['palette_ids'] : null;
    $convertAll = !empty($payload['all']);

    if ($paletteId <= 0 && !$convertAll && (!$paletteIds || count($paletteIds) === 0)) {
        respond(['ok' => false, 'error' => 'palette_id, palette_ids, or all required'], 400);
    }

    $appliedRepo = new PdoAppliedPaletteRepository($pdo);
    $savedRepo = new PdoSavedPaletteRepository($pdo);
    $photoRepo = new PdoPhotoRepository($pdo);
    $photoLibrary = new PhotoLibraryService(new PdoPhotoLibraryRepository($pdo));
    $renderService = new PhotoRenderingService($photoRepo, $pdo);
    $service = new AppliedPaletteConversionService(
        $appliedRepo,
        $savedRepo,
        $photoRepo,
        $renderService,
        $photoLibrary
    );

    if ($paletteId > 0) {
        $result = $service->convertPalette($paletteId);
        respond(['ok' => true, 'item' => $result]);
    }

    $result = $service->convertMany($convertAll ? null : $paletteIds);
    respond(['ok' => true, 'data' => $result]);
} catch (InvalidArgumentException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
