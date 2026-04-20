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
use App\Services\PhotoAltTextQueueService;
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

    if (empty($_POST['palette_id']) || !is_numeric($_POST['palette_id'])) {
        respond(['ok' => false, 'error' => 'palette_id required'], 400);
    }
    if (empty($_FILES['photos'])) {
        respond(['ok' => false, 'error' => 'photos[] required'], 400);
    }

    $paletteId = (int)$_POST['palette_id'];
    if ($paletteId <= 0) {
        respond(['ok' => false, 'error' => 'palette_id required'], 400);
    }

    $paletteRepo = new PdoAppliedPaletteRepository($pdo);
    if (!$paletteRepo->findById($paletteId)) {
        respond(['ok' => false, 'error' => 'Applied palette not found'], 404);
    }

    $files = $_FILES['photos'];
    $repo = new PdoAppliedPalettePhotoRepository($pdo);
    $photoLibrary = new PhotoLibraryService(new PdoPhotoLibraryRepository($pdo));
    $altTextQueue = PhotoAltTextQueueService::fromPdo($pdo);

    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 4), '/');
    $photosRoot = $docRoot . '/photos/uploads/applied-palettes/' . $paletteId;
    if (!is_dir($photosRoot) && !mkdir($photosRoot, 0775, true) && !is_dir($photosRoot)) {
        respond(['ok' => false, 'error' => 'Failed to create upload directory'], 500);
    }

    $added = [];
    $orderIndex = $repo->getMaxPhotoOrder($paletteId) + 1;

    $count = isset($files['name']) && is_array($files['name']) ? count($files['name']) : 0;
    for ($i = 0; $i < $count; $i++) {
        $tmp = $files['tmp_name'][$i] ?? '';
        if (!$tmp || !is_uploaded_file($tmp)) {
            continue;
        }
        $orig = $files['name'][$i] ?? 'photo';
        $ext = pathinfo($orig, PATHINFO_EXTENSION);
        $base = pathinfo($orig, PATHINFO_FILENAME);
        $safeBase = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $base) ?: 'photo';
        $filename = $safeBase . '-' . time() . '-' . $i . ($ext ? '.' . $ext : '');
        $absPath = $photosRoot . '/' . $filename;
        if (!move_uploaded_file($tmp, $absPath)) {
            continue;
        }

        $relPath = "/photos/uploads/applied-palettes/{$paletteId}/{$filename}";
        $photoId = $repo->addPhoto($paletteId, $relPath, null, null, $orderIndex++);
        $photoRow = [
            'id' => $photoId,
            'applied_palette_id' => $paletteId,
            'rel_path' => $relPath,
            'photo_type' => 'full',
            'trigger_mode' => 'any',
            'trigger_color_id' => null,
            'caption' => null,
            'alt_text' => null,
            'order_index' => $orderIndex - 1,
        ];
        $photoLibraryId = $photoLibrary->syncAppliedPaletteAttachmentPhoto($photoRow);
        if ($photoLibraryId > 0) {
            $altTextQueue->enqueue($photoLibraryId);
        }
        $added[] = $photoRow;
    }

    respond(['ok' => true, 'photos' => $added]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
