<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPhotoLibraryRepository;

function respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'Use POST']);
    }

    $id = isset($_POST['photo_library_id']) ? (int)$_POST['photo_library_id'] : 0;
    if ($id <= 0) {
        respond(400, ['ok' => false, 'error' => 'photo_library_id required']);
    }

    if (empty($_FILES['photo'])) {
        respond(400, ['ok' => false, 'error' => 'photo file required']);
    }

    $file = $_FILES['photo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respond(400, ['ok' => false, 'error' => 'Upload failed']);
    }

    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        respond(400, ['ok' => false, 'error' => 'Invalid upload']);
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $orig = $file['name'] ?? 'photo';
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        respond(400, ['ok' => false, 'error' => 'Unsupported file type']);
    }

    $info = @getimagesize($tmp);
    if (!$info) {
        respond(400, ['ok' => false, 'error' => 'Invalid image file']);
    }

    $repo = new PdoPhotoLibraryRepository($pdo);
    $row = $repo->findById($id);
    if (!$row || empty($row['rel_path'])) {
        respond(404, ['ok' => false, 'error' => 'Photo not found']);
    }

    $relPathRaw = (string)$row['rel_path'];
    $relPath = $relPathRaw === '' ? '' : ('/' . ltrim($relPathRaw, '/'));
    $publicRoot = realpath(__DIR__ . '/../../../..') ?: rtrim(__DIR__ . '/../../../..', '/');
    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? $publicRoot), '/');

    $primaryAbs = $docRoot . $relPath;
    $fallbackAbs = $publicRoot . $relPath;

    $ensureDir = static function (string $path): void {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            respond(500, ['ok' => false, 'error' => 'Failed to create target folder']);
        }
    };

    $writeTo = function (string $target) use ($tmp, $ensureDir): bool {
        $ensureDir($target);
        return move_uploaded_file($tmp, $target);
    };

    $writtenTo = null;
    if ($writeTo($primaryAbs)) {
        $writtenTo = $primaryAbs;
    } elseif ($primaryAbs !== $fallbackAbs && $writeTo($fallbackAbs)) {
        $writtenTo = $fallbackAbs;
    } else {
        respond(500, ['ok' => false, 'error' => 'Failed to write image']);
    }

    if ($primaryAbs !== $fallbackAbs && is_file($writtenTo)) {
        $copyTarget = $writtenTo === $primaryAbs ? $fallbackAbs : $primaryAbs;
        if (!is_file($copyTarget)) {
            $ensureDir($copyTarget);
            @copy($writtenTo, $copyTarget);
        }
    }

    // Touch row updated_at (rel_path unchanged)
    $repo->update($id, ['rel_path' => $relPathRaw]);

    $writtenPath = $writtenTo ?? null;
    respond(200, [
        'ok' => true,
        'rel_path' => $relPathRaw,
        'written_to' => $writtenPath,
        'doc_root' => $docRoot,
        'public_root' => $publicRoot,
        'exists_after' => $writtenPath ? is_file($writtenPath) : false,
        'size_after' => $writtenPath && is_file($writtenPath) ? filesize($writtenPath) : null,
    ]);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'error' => $e->getMessage()]);
}
