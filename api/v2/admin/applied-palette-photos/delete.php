<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoAppliedPalettePhotoRepository;
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

    $photoId = isset($payload['photo_id']) ? (int)$payload['photo_id'] : 0;
    if ($photoId <= 0) {
        respond(['ok' => false, 'error' => 'photo_id required'], 400);
    }

    $repo = new PdoAppliedPalettePhotoRepository($pdo);
    $photoLibrary = new PhotoLibraryService(new PdoPhotoLibraryRepository($pdo));
    $photo = $repo->getPhotoById($photoId);
    if (!$photo) {
        respond(['ok' => false, 'error' => 'photo not found'], 404);
    }

    $rel = (string)($photo['rel_path'] ?? '');
    if ($rel !== '' && str_starts_with($rel, '/photos/')) {
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 4), '/');
        $absPath = $docRoot . $rel;
        if (is_file($absPath)) {
            @unlink($absPath);
        }
    }

    $repo->deletePhoto($photoId);
    $photoLibrary->deleteAppliedPaletteAttachmentPhoto($photoId);

    respond(['ok' => true]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
