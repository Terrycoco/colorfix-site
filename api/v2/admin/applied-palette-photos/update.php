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
    if ($paletteId <= 0) {
        respond(['ok' => false, 'error' => 'palette_id required'], 400);
    }

    $paletteRepo = new PdoAppliedPaletteRepository($pdo);
    if (!$paletteRepo->findById($paletteId)) {
        respond(['ok' => false, 'error' => 'Applied palette not found'], 404);
    }

    $photos = $payload['photos'] ?? [];
    if (!is_array($photos)) {
        respond(['ok' => false, 'error' => 'photos must be array'], 400);
    }

    $repo = new PdoAppliedPalettePhotoRepository($pdo);
    $photoLibrary = new PhotoLibraryService(new PdoPhotoLibraryRepository($pdo));

    foreach ($photos as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $photoId = isset($photo['id']) ? (int)$photo['id'] : 0;
        if ($photoId <= 0) {
            continue;
        }

        $photoType = isset($photo['photo_type']) ? trim((string)$photo['photo_type']) : '';
        if (!in_array($photoType, ['full', 'zoom', 'before'], true)) {
            $photoType = 'full';
        }

        $triggerMode = isset($photo['trigger_mode']) ? strtolower(trim((string)$photo['trigger_mode'])) : 'any';
        if (!in_array($triggerMode, ['any', 'none', 'color'], true)) {
            $triggerMode = 'any';
        }

        $triggerId = null;
        if (array_key_exists('trigger_color_id', $photo)) {
            $triggerId = (int)$photo['trigger_color_id'];
            if ($triggerId <= 0) {
                $triggerId = null;
            }
        }

        $caption = null;
        if (array_key_exists('caption', $photo)) {
            $cap = trim((string)$photo['caption']);
            $caption = $cap === '' ? null : $cap;
        }

        if ($photoType === 'before') {
            $triggerId = null;
            $caption = 'Before';
            $triggerMode = 'none';
        }
        if ($triggerMode !== 'color') {
            $triggerId = null;
        }

        $altText = null;
        if (array_key_exists('alt_text', $photo)) {
            $alt = trim((string)$photo['alt_text']);
            $altText = $alt === '' ? null : $alt;
        }

        $repo->updatePhoto($photoId, $paletteId, [
            'photo_type' => $photoType,
            'trigger_mode' => $triggerMode,
            'trigger_color_id' => $triggerId,
            'caption' => $caption,
            'alt_text' => $altText,
        ]);

        $updated = $repo->getPhotoById($photoId);
        if ($updated) {
            $photoLibrary->syncAppliedPaletteAttachmentPhoto($updated);
        }
    }

    respond(['ok' => true]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
