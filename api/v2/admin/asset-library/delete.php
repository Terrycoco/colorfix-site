<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../auth.php';

use App\Controllers\AssetLibraryController;
use App\Repos\PdoAssetLibraryRepository;
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

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $baseUrl = $host !== '' ? $scheme . '://' . $host : '';
    $controller = new AssetLibraryController(
        new AssetLibraryService(new PdoAssetLibraryRepository($pdo), $baseUrl)
    );
    $rootDir = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../../../..'), '/');

    if (isset($payload['asset_library_ids']) && is_array($payload['asset_library_ids'])) {
        $result = $controller->hardDeleteUnpublishedGenerated(
            $payload['asset_library_ids'],
            $rootDir
        );
        respond(['ok' => true, 'item' => $result]);
    }

    $assetLibraryId = isset($payload['asset_library_id']) ? (int)$payload['asset_library_id'] : 0;
    if ($assetLibraryId <= 0) {
        respond(['ok' => false, 'error' => 'asset_library_id required'], 400);
    }

    $result = $controller->hardDeleteUnpublished(
        $assetLibraryId,
        $rootDir
    );

    if (!empty($result['blocked'])) {
        respond(['ok' => false, 'error' => 'Asset is published or locked.', 'item' => $result], 409);
    }

    respond(['ok' => true, 'item' => $result]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
