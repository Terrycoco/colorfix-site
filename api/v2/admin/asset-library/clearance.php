<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoAssetLibraryRepository;
use App\Services\AssetLibraryService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function decode_metadata(mixed $value): array {
    if (is_array($value)) return $value;
    if ($value === null || $value === '') return [];
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(['ok' => false, 'error' => 'POST only'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    $assetLibraryId = (int)($payload['asset_library_id'] ?? 0);
    if ($assetLibraryId <= 0) {
        respond(['ok' => false, 'error' => 'asset_library_id required'], 400);
    }

    $clearance = strtolower(trim((string)($payload['rights_clearance'] ?? '')));
    $allowed = ['cleared', 'review', 'blocked', 'unknown'];
    if (!in_array($clearance, $allowed, true)) {
        respond(['ok' => false, 'error' => 'Invalid clearance status'], 400);
    }

    $repo = new PdoAssetLibraryRepository($pdo);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $baseUrl = $host !== '' ? $scheme . '://' . $host : '';
    $service = new AssetLibraryService($repo, $baseUrl);
    $asset = $service->getAsset($assetLibraryId);
    if (!$asset) {
        respond(['ok' => false, 'error' => 'Asset not found'], 404);
    }

    $metadata = decode_metadata($asset['metadata_json'] ?? null);
    $metadata['rights_clearance'] = $clearance;
    $repo->update($assetLibraryId, ['metadata_json' => $metadata]);

    respond(['ok' => true, 'item' => $service->getAsset($assetLibraryId)]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
