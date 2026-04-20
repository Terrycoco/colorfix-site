<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPhotoLibraryRepository;
use App\Services\PhotoLibraryService;

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respond(['ok' => false, 'error' => 'POST only'], 405);
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: '{}', true);
    if (!is_array($body)) {
        $body = [];
    }

    $relPath = trim((string)($body['rel_path'] ?? ''));
    if ($relPath === '') {
        respond(['ok' => false, 'error' => 'rel_path is required'], 422);
    }

    $service = new PhotoLibraryService(new PdoPhotoLibraryRepository($pdo));
    $id = $service->retireMissingRelPath($relPath, [
        'source_type' => $body['source_type'] ?? null,
        'title' => $body['title'] ?? null,
    ]);

    if ($id <= 0) {
        respond(['ok' => false, 'error' => 'Unable to retire missing row'], 500);
    }

    respond([
        'ok' => true,
        'photo_library_id' => $id,
        'rel_path' => $relPath,
        'message' => "Retired missing file as hidden photo row #{$id}.",
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
