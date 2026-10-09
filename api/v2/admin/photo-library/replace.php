<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../auth.php';

use App\PHOTOS\Services\PhotoReplacementService;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;

header('Content-Type: application/json; charset=UTF-8');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Use POST']);
        exit;
    }
    $id = (int)($_POST['photo_library_id'] ?? 0);
    if ($id <= 0) { throw new InvalidArgumentException('photo_library_id required'); }
    $root = realpath(__DIR__ . '/../../../..');
    if (!$root) { throw new RuntimeException('Photo root missing.'); }
    $service = new PhotoReplacementService($pdo, $root);
    $sourceId = (int)($_POST['source_photo_library_id'] ?? 0);
    if ($sourceId > 0) {
        $sourceRow = (new PdoPhotoLibraryRepository($pdo))->findById($sourceId);
        $source = $service->localPath((string)($sourceRow['rel_path'] ?? ''));
        if (!$source) { throw new InvalidArgumentException('Selected library photo is missing.'); }
    } else {
        $file = $_FILES['photo'] ?? [];
        $source = (string)($file['tmp_name'] ?? '');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($source)) {
            throw new InvalidArgumentException('Valid photo upload required.');
        }
    }
    $photo = $service->replace($id, $source, $_POST);
    echo json_encode(['ok' => true, 'rel_path' => $photo['rel_path'], 'photo' => $photo], JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
