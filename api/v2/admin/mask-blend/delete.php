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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$assetId = trim((string)($input['asset_id'] ?? ''));
$mask    = trim((string)($input['mask'] ?? ''));
$id      = isset($input['id']) ? (int)$input['id'] : 0;

if ($assetId === '' || $mask === '' || $id <= 0) {
    respond(['ok' => false, 'error' => 'asset_id, mask, and id required'], 400);
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    respond(['ok' => false, 'error' => 'DB not initialized'], 500);
}

$repo = new PdoMaskBlendSettingRepository($pdo);
$service = new MaskBlendService(
    $repo,
    new PdoPhotoRepository($pdo)
);

try {
    $row = $repo->findById($id);
    $service->deleteSetting($assetId, $mask, $id);
    if ($row && !empty($row['color_id'])) {
        $flagStmt = $pdo->prepare("
            UPDATE applied_palettes ap
            JOIN applied_palette_entries ape ON ape.applied_palette_id = ap.id
            SET ap.needs_rerender = 1
            WHERE ap.asset_id = :asset
              AND ape.mask_role = :mask
              AND ape.color_id = :color
        ");
        $flagStmt->execute([
            ':asset' => $assetId,
            ':mask' => $mask,
            ':color' => (int)$row['color_id'],
        ]);
    }
    respond(['ok' => true]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
}
