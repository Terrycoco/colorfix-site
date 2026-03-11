<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoAppliedPalettePhotoRepository;
use App\Repos\PdoAppliedPaletteRepository;
use App\Repos\PdoPhotoLibraryRepository;
use App\Services\PhotoLibraryService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
        respond(['ok' => false, 'error' => 'POST only'], 405);
    }

    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $paletteId = isset($payload['palette_id']) ? (int)$payload['palette_id'] : 0;
    $relPath = trim((string)($payload['rel_path'] ?? ''));
    if ($paletteId <= 0 || $relPath === '') {
        respond(['ok' => false, 'error' => 'palette_id and rel_path required'], 400);
    }

    $paletteRepo = new PdoAppliedPaletteRepository($pdo);
    if (!$paletteRepo->findById($paletteId)) {
        respond(['ok' => false, 'error' => 'Applied palette not found'], 404);
    }

    $photoType = isset($payload['photo_type']) ? trim((string)$payload['photo_type']) : 'full';
    $triggerMode = isset($payload['trigger_mode']) ? strtolower(trim((string)$payload['trigger_mode'])) : 'any';
    $triggerId = isset($payload['trigger_color_id']) ? (int)$payload['trigger_color_id'] : null;
    $caption = isset($payload['caption']) ? trim((string)$payload['caption']) : null;
    $altText = isset($payload['alt_text']) ? trim((string)$payload['alt_text']) : null;

    $repo = new PdoAppliedPalettePhotoRepository($pdo);
    $orderIndex = $repo->getMaxPhotoOrder($paletteId) + 1;
    $photoId = $repo->addPhoto($paletteId, $relPath, $caption ?: null, $altText ?: null, $orderIndex);
    if ($photoId <= 0) {
        respond(['ok' => false, 'error' => 'Failed to add photo'], 500);
    }

    $update = [
        'photo_type' => $photoType,
        'trigger_mode' => $triggerMode,
        'trigger_color_id' => $triggerId,
        'caption' => $caption ?: null,
        'alt_text' => $altText ?: null,
    ];
    $repo->updatePhoto($photoId, $paletteId, $update);

    $row = $repo->getPhotoById($photoId);
    if ($row) {
        $photoLibrary = new PhotoLibraryService(new PdoPhotoLibraryRepository($pdo));
        $photoLibrary->syncAppliedPaletteAttachmentPhoto($row);
    }

    respond(['ok' => true, 'photo' => $row]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
