<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoAppliedPaletteRepository;
use App\Repos\PdoPhotoRepository;
use App\Services\PhotoRenderingService;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required']);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Invalid JSON');
    }
    $paletteId = isset($payload['palette_id']) ? (int)$payload['palette_id'] : 0;
    if ($paletteId <= 0) {
        throw new InvalidArgumentException('palette_id required');
    }

    $paletteRepo = new PdoAppliedPaletteRepository($pdo);
    $palette = $paletteRepo->findById($paletteId);
    if (!$palette) {
        throw new RuntimeException('Palette not found');
    }

    $photoRepo = new PdoPhotoRepository($pdo);
    $service = new PhotoRenderingService($photoRepo, $pdo);
    $renderInfo = $service->cacheAppliedPalette($palette);

    $pdo->prepare("UPDATE applied_palettes SET needs_rerender = 0, updated_at = NOW() WHERE id = :id")
        ->execute([':id' => $paletteId]);

    echo json_encode([
        'ok' => true,
        'render' => $renderInfo,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
