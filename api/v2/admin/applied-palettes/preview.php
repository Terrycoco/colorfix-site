<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Entities\AppliedPalette;
use App\Repos\PdoAppliedPaletteRepository;
use App\Repos\PdoPhotoRepository;
use App\Services\PhotoRenderingService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(['ok' => false, 'error' => 'POST required'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $paletteId = isset($payload['palette_id']) ? (int)$payload['palette_id'] : 0;
    $entries = $payload['entries'] ?? [];
    if ($paletteId <= 0) {
        respond(['ok' => false, 'error' => 'palette_id required'], 400);
    }
    if (!is_array($entries) || !count($entries)) {
        respond(['ok' => false, 'error' => 'entries required'], 400);
    }

    $paletteRepo = new PdoAppliedPaletteRepository($pdo);
    $palette = $paletteRepo->findById($paletteId);
    if (!$palette) {
        respond(['ok' => false, 'error' => 'Palette not found'], 404);
    }

    $photoRepo = new PdoPhotoRepository($pdo);
    $renderService = new PhotoRenderingService($photoRepo, $pdo);

    // build transient palette with incoming entries, no DB writes
    $previewPalette = new AppliedPalette(
        $palette->id,
        $palette->title,
        $palette->displayTitle,
        $palette->notes,
        $palette->tags,
        $palette->kickerId,
        $palette->photoId,
        $palette->assetId,
        $entries
    );

    $renderPath = sprintf('/photos/rendered/previews/ap_%d_preview.jpg', $paletteId);
    $renderInfo = $renderService->renderAppliedPalette($previewPalette, $renderPath);

    respond([
        'ok' => true,
        'render' => [
            'render_rel_path' => $renderInfo['render_rel_path'] ?? null,
            'render_url' => $renderInfo['render_url'] ?? null,
            'width' => $renderInfo['width'] ?? null,
            'height' => $renderInfo['height'] ?? null,
        ],
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
