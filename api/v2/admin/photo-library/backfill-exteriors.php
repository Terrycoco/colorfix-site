<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPhotoLibraryRepository;
use App\Services\PhotoLibraryService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

try {
    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../../../..'), '/');
    $baseDir = $docRoot . '/photos/exteriors';
    if (!is_dir($baseDir)) {
        respond(['ok' => false, 'error' => 'photos/exteriors not found'], 400);
    }

    $repo = new PdoPhotoLibraryRepository($pdo);
    $library = new PhotoLibraryService($repo);

    $added = 0;
    $skipped = 0;
    $items = [];

    $categoryDirs = glob($baseDir . '/*', GLOB_ONLYDIR) ?: [];
    foreach ($categoryDirs as $categoryPath) {
        $category = basename($categoryPath);
        $assetDirs = glob($categoryPath . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($assetDirs as $assetPath) {
            $asset = basename($assetPath);
            $preparedPath = $assetPath . '/prepared/base.jpg';
            if (!is_file($preparedPath)) {
                $skipped++;
                continue;
            }
            $relPath = "/photos/exteriors/{$category}/{$asset}/prepared/base.jpg";
            $tags = implode(', ', array_filter([$category, $asset]));
            $id = $library->createStandalone('photo_base', $relPath, [
                'title' => $asset,
                'tags' => $tags,
            ]);
            if ($id > 0) {
                $added++;
                $items[] = [
                    'photo_library_id' => $id,
                    'rel_path' => $relPath,
                ];
            } else {
                $skipped++;
            }
        }
    }

    respond(['ok' => true, 'added' => $added, 'skipped' => $skipped, 'items' => $items]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
