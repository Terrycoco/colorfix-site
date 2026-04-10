<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoSavedPaletteRepository;
use App\Services\PhotoLibraryService;
use App\Repos\PdoPhotoLibraryRepository;

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
    $rawRelPath = trim((string)($payload['raw_rel_path'] ?? ''));
    $photoLibraryId = isset($payload['photo_library_id']) ? (int)$payload['photo_library_id'] : 0;
    $setId = isset($payload['set_id']) ? (int)$payload['set_id'] : 0;
    $setTitle = trim((string)($payload['set_title'] ?? ''));
    $setSlug = trim((string)($payload['set_slug'] ?? ''));
    $createNewSet = !empty($payload['create_new_set']);
    if ($rawRelPath !== '') {
        $relPath = $rawRelPath;
    } elseif ($relPath !== '') {
        $parsedPath = (string)(parse_url($relPath, PHP_URL_PATH) ?: '');
        if ($parsedPath !== '') {
            $relPath = $parsedPath;
        }
    }
    if ($paletteId <= 0 || $relPath === '') {
        respond(['ok' => false, 'error' => 'palette_id and rel_path required'], 400);
    }

    $photoType = isset($payload['photo_type']) ? trim((string)$payload['photo_type']) : 'full';
    $triggerMode = isset($payload['trigger_mode']) ? strtolower(trim((string)$payload['trigger_mode'])) : 'any';
    $triggerId = isset($payload['trigger_color_id']) ? (int)$payload['trigger_color_id'] : null;
    $showInGallery = !empty($payload['show_in_gallery']);
    $replaceExistingFull = !empty($payload['replace_existing_full']);
    $caption = isset($payload['caption']) ? trim((string)$payload['caption']) : null;
    $altText = isset($payload['alt_text']) ? trim((string)$payload['alt_text']) : null;

    $repo = new PdoSavedPaletteRepository($pdo);
    if ($setId <= 0 && $createNewSet) {
        $setId = $repo->createAutoSetForPalette($paletteId);
    } elseif ($setId <= 0 && ($setTitle !== '' || $setSlug !== '')) {
        $setId = $repo->ensureSetForPalette($paletteId, $setTitle !== '' ? $setTitle : null, $setSlug !== '' ? $setSlug : null);
    }
    $existingExact = null;
    $photos = $repo->getPhotosForPalette($paletteId, $setId > 0 ? $setId : null);
    foreach ($photos as $photo) {
        if ((string)($photo['rel_path'] ?? '') !== $relPath) {
            continue;
        }
        if ((string)($photo['photo_type'] ?? 'full') !== $photoType) {
            continue;
        }
        $existingExact = $photo;
        break;
    }

    $existingFull = null;
    if ($photoType === 'full' && !$createNewSet) {
        foreach ($photos as $photo) {
            if ((string)($photo['photo_type'] ?? 'full') !== 'full') {
                continue;
            }
            if ($existingExact && (int)$photo['id'] === (int)$existingExact['id']) {
                continue;
            }
            $existingFull = $photo;
            break;
        }
        if ($existingFull && !$replaceExistingFull) {
            respond(['ok' => false, 'error' => 'This viewer already has a Full photo. Replace it or create a new viewer.'], 400);
        }
        if (!$existingFull) {
            $excludePhotoId = $existingExact ? (int)$existingExact['id'] : null;
            if ($repo->hasFullPhotoInPaletteSet($paletteId, $setId > 0 ? $setId : null, $excludePhotoId)) {
                respond(['ok' => false, 'error' => 'This viewer already has a Full photo. Replace it or create a new viewer.'], 400);
            }
        }
    }

    if ($photoType === 'full' && $existingFull && $replaceExistingFull) {
        $photoId = (int)$existingFull['id'];
        $update = [
            'photo_library_id' => $photoLibraryId > 0 ? $photoLibraryId : ($existingFull['photo_library_id'] ?? null),
            'rel_path' => $relPath,
            'photo_type' => 'full',
            'trigger_mode' => $triggerMode,
            'trigger_color_id' => $triggerId,
            'show_in_gallery' => $showInGallery ? 1 : 0,
            'caption' => $caption ?: null,
            'alt_text' => $altText ?: null,
        ];
        $repo->updatePhoto($photoId, $paletteId, $update);
    } elseif ($existingExact) {
        $photoId = (int)$existingExact['id'];
        $update = [
            'photo_library_id' => $photoLibraryId > 0 ? $photoLibraryId : ($existingExact['photo_library_id'] ?? null),
            'caption' => $caption ?: null,
            'alt_text' => $altText ?: null,
            'show_in_gallery' => $showInGallery ? 1 : 0,
        ];
        if ($photoType === 'before') {
            $update['trigger_mode'] = 'none';
            $update['trigger_color_id'] = null;
            $update['caption'] = 'Before';
            $update['show_in_gallery'] = 0;
        } else {
            $update['trigger_mode'] = $triggerMode;
            $update['trigger_color_id'] = $triggerId;
        }
        $repo->updatePhoto($photoId, $paletteId, $update);
    } else {
        $orderIndex = $repo->getMaxPhotoOrder($paletteId, $setId > 0 ? $setId : null) + 1;
        $photoId = $repo->addPhoto($paletteId, $relPath, $caption ?: null, $altText ?: null, $orderIndex, $setId > 0 ? $setId : null, $photoLibraryId > 0 ? $photoLibraryId : null);
        if ($photoId <= 0) {
            respond(['ok' => false, 'error' => 'Failed to add photo'], 500);
        }

        $update = [
            'photo_library_id' => $photoLibraryId > 0 ? $photoLibraryId : null,
            'photo_type' => $photoType,
            'trigger_mode' => $triggerMode,
            'trigger_color_id' => $triggerId,
            'show_in_gallery' => $showInGallery ? 1 : 0,
            'caption' => $caption ?: null,
            'alt_text' => $altText ?: null,
        ];
        if ($photoType === 'before') {
            $update['trigger_mode'] = 'none';
            $update['trigger_color_id'] = null;
            $update['caption'] = 'Before';
            $update['show_in_gallery'] = 0;
        }
        $repo->updatePhoto($photoId, $paletteId, $update);
    }

    $row = $repo->getPhotoById($photoId);
    if ($row) {
        // If the user explicitly picked an existing library asset, don't clone it into
        // photo_library again under a saved-palette-specific source row.
        if ($photoLibraryId <= 0) {
            $photoLibrary = new PhotoLibraryService(new PdoPhotoLibraryRepository($pdo));
            $canonicalId = $photoLibrary->syncSavedPalettePhoto($row);
            if ($canonicalId > 0 && (int)($row['photo_library_id'] ?? 0) !== $canonicalId) {
                $repo->updatePhoto($photoId, $paletteId, ['photo_library_id' => $canonicalId]);
                $row = $repo->getPhotoById($photoId) ?: $row;
            }
        }
    }

    respond(['ok' => true, 'photo' => $row]);
} catch (\Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
