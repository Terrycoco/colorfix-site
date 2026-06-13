<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../auth.php';

use App\Repos\PdoPhotoLibraryRepository;
use App\Services\PhotoLibraryService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'POST') {
        respond(['ok' => false, 'error' => 'GET or POST only'], 405);
    }

    $apply = false;
    if ($method === 'GET') {
        $apply = !empty($_GET['apply']) && $_GET['apply'] !== '0';
    } else {
        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (is_array($payload)) {
            $apply = !empty($payload['apply']);
        }
    }

    $service = new PhotoLibraryService(new PdoPhotoLibraryRepository($pdo));
    $result = $service->dedupeSavedPaletteRows($apply);
    respond([
        'ok' => true,
        'apply' => $apply,
        'result' => $result,
    ]);
} catch (\Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
