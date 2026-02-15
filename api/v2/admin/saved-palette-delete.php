<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Controllers\SavedPaletteController;
use App\Repos\PdoSavedPaletteRepository;
use App\Repos\PdoPhotoLibraryRepository;
use App\Services\PhotoLibraryService;
use App\Services\SavedPaletteService;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Use POST'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $paletteId = isset($payload['palette_id']) ? (int)$payload['palette_id'] : 0;
    if ($paletteId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'palette_id required'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $paletteRepo = new PdoSavedPaletteRepository($pdo);
    $photoLibraryRepo = new PdoPhotoLibraryRepository($pdo);
    $photoLibrary = new PhotoLibraryService($photoLibraryRepo);
    $service     = new SavedPaletteService($paletteRepo, null, null, $photoLibrary);
    $controller  = new SavedPaletteController($service);

    $controller->deleteSavedPalette($paletteId);

    echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
