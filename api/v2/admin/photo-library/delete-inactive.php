<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPhotoLibraryRepository;
use App\Services\PhotoLibraryUsageService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

try {
    $repo = new PdoPhotoLibraryRepository($pdo);
    $usageService = new PhotoLibraryUsageService($repo);
    $rows = $repo->listInactiveRows();

    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../../../..'), '/');
    $deleted = 0;
    $skipped = 0;
    $items = [];

    foreach ($rows as $row) {
        $id = (int)($row['photo_library_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $usages = $usageService->getUsageSummary($id);
        if ($usages) {
            $skipped++;
            $items[] = [
                'photo_library_id' => $id,
                'status' => 'in_use',
                'rel_path' => (string)($row['rel_path'] ?? ''),
                'title' => (string)($row['title'] ?? ''),
                'usage_count' => count($usages),
            ];
            continue;
        }

        $rel = (string)($row['rel_path'] ?? '');
        if ($rel !== '' && str_starts_with($rel, '/photos/')) {
            $abs = $docRoot . $rel;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }

        $repo->deleteById($id);
        $deleted++;
        $items[] = [
            'photo_library_id' => $id,
            'status' => 'deleted',
            'rel_path' => $rel,
            'title' => (string)($row['title'] ?? ''),
            'usage_count' => 0,
        ];
    }

    respond([
        'ok' => true,
        'result' => [
            'checked' => count($rows),
            'deleted' => $deleted,
            'skipped' => $skipped,
            'items' => $items,
        ],
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
