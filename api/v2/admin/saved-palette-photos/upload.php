<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoSavedPaletteRepository;

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
    if (!$repo->getSavedPaletteById($paletteId)) {
        respond(404, ['ok' => false, 'error' => 'Saved palette not found']);
    }

    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../../../..'), '/');
    $photosRoot = $docRoot . '/photos/uploads/saved-palettes/' . $paletteId;
    if (!is_dir($photosRoot) && !mkdir($photosRoot, 0775, true) && !is_dir($photosRoot)) {
        respond(500, ['ok' => false, 'error' => 'Failed to create upload directory']);
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $added = [];
    $orderIndex = $repo->getMaxPhotoOrder($paletteId) + 1;

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
        $photoId = $repo->addPhoto($paletteId, $relPath, null, $orderIndex);
        $added[] = [
            'id' => $photoId,
            'saved_palette_id' => $paletteId,
            'rel_path' => $relPath,
            'photo_type' => 'full',
            'trigger_color_id' => null,
            'caption' => null,
            'order_index' => $orderIndex,
        ];
        $orderIndex++;
    }

    if (!$added) {
        respond(400, ['ok' => false, 'error' => 'No valid images uploaded']);
    }

    respond(200, ['ok' => true, 'photos' => $added]);
} catch (\Throwable $e) {
    respond(500, ['ok' => false, 'error' => $e->getMessage()]);
}
