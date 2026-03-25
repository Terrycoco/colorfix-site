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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

try {
    $photoLibraryId = isset($_GET['photo_library_id']) ? (int)$_GET['photo_library_id'] : 0;
    if ($photoLibraryId <= 0) {
        respond(['ok' => false, 'error' => 'photo_library_id required'], 400);
    }

    $service = new PhotoLibraryUsageService(new PdoPhotoLibraryRepository($pdo));
    $usages = $service->getUsageSummary($photoLibraryId);

    respond([
        'ok' => true,
        'photo_library_id' => $photoLibraryId,
        'in_use' => !empty($usages),
        'usages' => $usages,
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
