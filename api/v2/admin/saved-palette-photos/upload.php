<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoSavedPaletteRepository;
use App\Repos\PdoPhotoLibraryRepository;
use App\Services\PhotoAltTextQueueService;
use App\Services\PhotoLibraryService;

function respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'Use POST']);
    }

    $paletteId = isset($_POST['palette_id']) ? (int) $_POST['palette_id'] : 0;
    $replacePhotoId = isset($_POST['replace_photo_id']) ? (int) $_POST['replace_photo_id'] : 0;
    $setId = isset($_POST['set_id']) ? (int) $_POST['set_id'] : 0;
    $setTitle = trim((string)($_POST['set_title'] ?? ''));
    $setSlug = trim((string)($_POST['set_slug'] ?? ''));
    $createNewSet = !empty($_POST['create_new_set']);
    $photoType = isset($_POST['photo_type']) ? trim((string)$_POST['photo_type']) : 'full';
    $tags = trim((string)($_POST['tags'] ?? ''));
    $altText = trim((string)($_POST['alt_text'] ?? ''));
    if ($paletteId <= 0) {
        respond(400, ['ok' => false, 'error' => 'palette_id required']);
    }

    if (empty($_FILES['photos'])) {
        respond(400, ['ok' => false, 'error' => 'photos[] required']);
    }

    $files = $_FILES['photos'];
    $count = is_array($files['name']) ? count($files['name']) : 0;
    if ($count === 0) {
        respond(400, ['ok' => false, 'error' => 'No files uploaded']);
    }

    $repo = new PdoSavedPaletteRepository($pdo);
    $photoLibraryRepo = new PdoPhotoLibraryRepository($pdo);
    $photoLibrary = new PhotoLibraryService($photoLibraryRepo);
    $altTextQueue = PhotoAltTextQueueService::fromPdo($pdo);
    if (!$repo->getSavedPaletteById($paletteId)) {
        respond(404, ['ok' => false, 'error' => 'Saved palette not found']);
    }
    if ($setId <= 0 && $createNewSet) {
        $setId = $repo->createAutoSetForPalette($paletteId);
    } elseif ($setId <= 0 && ($setTitle !== '' || $setSlug !== '')) {
        $setId = $repo->ensureSetForPalette($paletteId, $setTitle !== '' ? $setTitle : null, $setSlug !== '' ? $setSlug : null);
    }
    if (!in_array($photoType, ['full', 'before', 'zoom'], true)) {
        $photoType = 'full';
    }

    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../../../..'), '/');
    $photosRoot = $docRoot . '/photos/uploads/saved-palettes/' . $paletteId;
    if (!is_dir($photosRoot) && !mkdir($photosRoot, 0775, true) && !is_dir($photosRoot)) {
        respond(500, ['ok' => false, 'error' => 'Failed to create upload directory']);
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $added = [];
    $orderIndex = $repo->getMaxPhotoOrder($paletteId, $setId > 0 ? $setId : null) + 1;
    $replaceRow = null;
    if ($replacePhotoId > 0) {
        $replaceRow = $repo->getPhotoById($replacePhotoId);
        if (!$replaceRow || (int)($replaceRow['saved_palette_id'] ?? 0) !== $paletteId) {
            respond(404, ['ok' => false, 'error' => 'Photo to replace not found']);
        }
        if ($count !== 1) {
            respond(400, ['ok' => false, 'error' => 'Replace expects exactly one file']);
        }
        if ($photoType === 'full' && $repo->hasFullPhotoInPaletteSet($paletteId, $setId > 0 ? $setId : null, (int)$replaceRow['id'])) {
            respond(400, ['ok' => false, 'error' => 'This group already has a different Full photo. Replace that one directly or create a new group.']);
        }
    } elseif ($photoType === 'full' && !$createNewSet && $repo->hasFullPhotoInPaletteSet($paletteId, $setId > 0 ? $setId : null)) {
        respond(400, ['ok' => false, 'error' => 'This group already has a Full photo. Replace the existing one or create a new group.']);
    }

    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmp = $files['tmp_name'][$i] ?? '';
        $orig = $files['name'][$i] ?? 'photo';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            continue;
        }

        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            continue;
        }

        $info = @getimagesize($tmp);
        if (!$info) {
            continue;
        }

        $slug = bin2hex(random_bytes(6));
        $filename = "sp_{$paletteId}_{$slug}.{$ext}";
        $absPath = $photosRoot . '/' . $filename;
        if (!move_uploaded_file($tmp, $absPath)) {
            continue;
        }

        $relPath = "/photos/uploads/saved-palettes/{$paletteId}/{$filename}";
        if ($replaceRow) {
            $photoId = (int)$replaceRow['id'];
            $oldRelPath = (string)($replaceRow['rel_path'] ?? '');
            $repo->updatePhoto($photoId, $paletteId, ['rel_path' => $relPath]);
            $photoRow = array_merge($replaceRow, ['id' => $photoId, 'rel_path' => $relPath]);
            $oldAbsPath = $oldRelPath !== '' ? $docRoot . $oldRelPath : '';
            if ($oldRelPath !== '' && $oldRelPath !== $relPath && is_file($oldAbsPath)) {
                @unlink($oldAbsPath);
            }
        } else {
            $photoId = $repo->addPhoto($paletteId, $relPath, null, null, $orderIndex, $setId > 0 ? $setId : null);
            $photoRow = [
                'id' => $photoId,
                'saved_palette_id' => $paletteId,
                'saved_palette_set_id' => $setId > 0 ? $setId : null,
                'rel_path' => $relPath,
                'photo_type' => $photoType,
                'trigger_mode' => $photoType === 'before' ? 'none' : 'any',
                'trigger_color_id' => null,
                'caption' => $photoType === 'before' ? 'Before' : null,
                'alt_text' => null,
                'order_index' => $orderIndex,
            ];
            $repo->updatePhoto($photoId, $paletteId, [
                'photo_type' => $photoRow['photo_type'],
                'trigger_mode' => $photoRow['trigger_mode'],
                'caption' => $photoRow['caption'],
            ]);
        }

        $canonicalId = 0;
        $existingLibraryId = $replaceRow ? (int)($replaceRow['photo_library_id'] ?? 0) : 0;
        if ($existingLibraryId > 0) {
            $libraryUpdate = ['rel_path' => $relPath];
            if ($tags !== '') {
                $libraryUpdate['tags'] = $tags;
            }
            if ($altText !== '') {
                $libraryUpdate['alt_text'] = $altText;
            }
            $photoLibraryRepo->update($existingLibraryId, $libraryUpdate);
            $canonicalId = $existingLibraryId;
        } else {
            $canonicalId = $photoLibrary->syncSavedPalettePhoto($photoRow, [
                'tags' => $tags !== '' ? $tags : null,
                'alt_text' => $altText !== '' ? $altText : null,
            ]);
        }

        if ($canonicalId > 0) {
            if ($altText === '') {
                $altTextQueue->enqueue($canonicalId, $replaceRow !== null);
            }
            $repo->updatePhoto($photoId, $paletteId, ['photo_library_id' => $canonicalId]);
            $photoRow = $repo->getPhotoById($photoId) ?: $photoRow;
        }
        $added[] = $photoRow;
        if (!$replaceRow) {
            $orderIndex++;
        }
    }

    if (!$added) {
        respond(400, ['ok' => false, 'error' => 'No valid images uploaded']);
    }

    respond(200, ['ok' => true, 'photos' => $added]);
} catch (\Throwable $e) {
    respond(500, ['ok' => false, 'error' => $e->getMessage()]);
}
