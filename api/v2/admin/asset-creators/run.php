<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

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

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $jobId = (int)($payload['asset_creator_job_id'] ?? $payload['id'] ?? 0);
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $host !== '' ? $scheme . '://' . $host : '';
    $rootDir = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 4)), '/');

    $service = new AssetCreatorRunService(
        new PdoAssetCreatorRepository($pdo),
        new AssetLibraryService(new PdoAssetLibraryRepository($pdo), $baseUrl),
        $rootDir,
        $pdo
    );

    $repo = new PdoAssetCreatorRepository($pdo);
    $job = $repo->findJob($jobId);
    if (!$job) {
        respond(['ok' => false, 'error' => 'Asset creator job not found'], 404);
    }

    if (trim((string)($job['creator_key'] ?? '')) === 'youtube.playlist_video') {
        respond(['ok' => true, 'item' => $service->queueYoutubePlaylistVideoJob($jobId)]);
    }

    respond(['ok' => true, 'item' => $service->runJob($jobId)]);
} catch (RuntimeException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
