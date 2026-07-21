<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/_worker_auth.php';

use App\Repos\PdoAssetCreatorRepository;
use App\Repos\PdoAssetLibraryRepository;
use App\Services\AssetCreatorRunService;
use App\Services\AssetLibraryService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

cf_require_worker_token();

try {
    $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    $json = [];
    if (stripos($contentType, 'application/json') !== false) {
        $decoded = json_decode(file_get_contents('php://input') ?: '', true);
        $json = is_array($decoded) ? $decoded : [];
    }

    $jobId = (int)($_POST['asset_creator_job_id'] ?? $_POST['job_id'] ?? $json['asset_creator_job_id'] ?? $json['job_id'] ?? 0);
    if ($jobId <= 0) {
        respond(['ok' => false, 'error' => 'asset_creator_job_id required'], 400);
    }

    $error = trim((string)($_POST['error'] ?? $json['error'] ?? ''));
    $repo = new PdoAssetCreatorRepository($pdo);
    if ($error !== '') {
        $repo->updateJob($jobId, [
            'status' => 'failed',
            'notes' => 'Mac YouTube render worker failed at ' . gmdate('c') . ': ' . $error,
        ]);
        respond(['ok' => true, 'failed' => true, 'job' => $repo->findJob($jobId)]);
    }

    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respond(['ok' => false, 'error' => 'Rendered MP4 file required'], 400);
    }

    $file = $_FILES['file'];
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        respond(['ok' => false, 'error' => 'Invalid upload'], 400);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)($finfo->file($tmp) ?: ($file['type'] ?? ''));
    if ($mime !== '' && $mime !== 'video/mp4' && !str_starts_with($mime, 'video/')) {
        respond(['ok' => false, 'error' => 'Rendered upload must be a video file'], 400);
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $host !== '' ? $scheme . '://' . $host : '';
    $rootDir = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 4)), '/');

    $service = new AssetCreatorRunService(
        $repo,
        new AssetLibraryService(new PdoAssetLibraryRepository($pdo), $baseUrl),
        $rootDir,
        $pdo
    );

    $item = $service->completeYoutubePlaylistVideoJob($jobId, $tmp, 'mac-youtube-render-worker');
    respond(['ok' => true, 'item' => $item]);
} catch (RuntimeException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
