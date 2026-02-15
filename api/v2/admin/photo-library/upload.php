<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPhotoLibraryRepository;
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

    $sourceType = isset($_POST['source_type']) ? trim((string)$_POST['source_type']) : '';
    if (!in_array($sourceType, ['progression', 'article'], true)) {
        respond(400, ['ok' => false, 'error' => 'source_type must be progression or article']);
    }

    if (empty($_FILES['photos'])) {
        respond(400, ['ok' => false, 'error' => 'photos[] required']);
    }

    $files = $_FILES['photos'];
    $count = is_array($files['name']) ? count($files['name']) : 0;
    if ($count === 0) {
        respond(400, ['ok' => false, 'error' => 'No files uploaded']);
    }

    $seriesRaw = trim((string)($_POST['series'] ?? ''));
    $series = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $seriesRaw);
    if ($series === '') {
        $series = date('Ymd');
    }

    $titlePrefix = trim((string)($_POST['title_prefix'] ?? ''));
    $tags = trim((string)($_POST['tags'] ?? ''));
    $altText = trim((string)($_POST['alt_text'] ?? ''));
    $showInGallery = !empty($_POST['show_in_gallery']);
    $hasPalette = !empty($_POST['has_palette']);

    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../../../..'), '/');
    $folderBase = $sourceType === 'article' ? 'articles' : 'progressions';
    $photosRoot = $docRoot . '/photos/' . $folderBase . '/' . $series;
    if (!is_dir($photosRoot) && !mkdir($photosRoot, 0775, true) && !is_dir($photosRoot)) {
        respond(500, ['ok' => false, 'error' => 'Failed to create upload directory']);
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $added = [];

    $repo = new PdoPhotoLibraryRepository($pdo);
    $library = new PhotoLibraryService($repo);

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
        $filename = "{$sourceType}_{$series}_{$slug}.{$ext}";
        $absPath = $photosRoot . '/' . $filename;
        if (!move_uploaded_file($tmp, $absPath)) {
            continue;
        }

        $relPath = "/photos/{$folderBase}/{$series}/{$filename}";
        $baseName = pathinfo($orig, PATHINFO_FILENAME);
        $title = $titlePrefix !== '' ? trim($titlePrefix . ' ' . $baseName) : $baseName;

        $libraryId = $library->createStandalone($sourceType, $relPath, [
            'title' => $title,
            'tags' => $tags !== '' ? $tags : null,
            'alt_text' => $altText !== '' ? $altText : null,
            'show_in_gallery' => $showInGallery ? 1 : 0,
            'has_palette' => $hasPalette ? 1 : 0,
        ]);

        if ($libraryId > 0) {
            $added[] = [
                'photo_library_id' => $libraryId,
                'rel_path' => $relPath,
                'title' => $title,
            ];
        }
    }

    if (!$added) {
        respond(400, ['ok' => false, 'error' => 'No valid images uploaded']);
    }

    respond(200, ['ok' => true, 'items' => $added]);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'error' => $e->getMessage()]);
}
