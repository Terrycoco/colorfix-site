<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoMaskBlendSettingRepository;
use App\Repos\PdoPhotoRepository;
use App\Services\MaskBlendService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$assetId = trim((string)($_GET['asset_id'] ?? ''));
$mask    = trim((string)($_GET['mask'] ?? ''));
if ($assetId === '' || $mask === '') {
    respond(['ok' => false, 'error' => 'asset_id and mask required'], 400);
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    respond(['ok' => false, 'error' => 'DB not initialized'], 500);
}

$service = new MaskBlendService(
    new PdoMaskBlendSettingRepository($pdo),
    new PdoPhotoRepository($pdo)
);

try {
    $data = $service->listSettings($assetId, $mask);
    respond(['ok' => true] + $data);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
}
