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
$entry   = $input['entry'] ?? null;
if ($assetId === '' || $mask === '' || !is_array($entry)) {
    respond(['ok' => false, 'error' => 'asset_id, mask, and entry required'], 400);
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    respond(['ok' => false, 'error' => 'DB not initialized'], 500);
}

$service = new MaskBlendService(
    new PdoMaskBlendSettingRepository($pdo),
    new PdoPhotoRepository($pdo)
);

try {
    $saved = $service->saveSetting($assetId, $mask, [
        'id' => $entry['id'] ?? null,
        'color_id' => $entry['color_id'] ?? null,
        'color_name' => $entry['color_name'] ?? null,
        'color_brand' => $entry['color_brand'] ?? null,
        'color_code' => $entry['color_code'] ?? null,
        'color_hex' => $entry['color_hex'] ?? '',
        'base_lightness' => $entry['base_lightness'] ?? null,
        'target_lightness' => $entry['target_lightness'] ?? null,
        'target_h' => $entry['target_h'] ?? null,
        'target_c' => $entry['target_c'] ?? null,
        'blend_mode' => $entry['blend_mode'] ?? 'colorize',
        'blend_opacity' => $entry['blend_opacity'] ?? 1,
        'shadow_l_offset' => $entry['shadow_l_offset'] ?? null,
        'shadow_tint_hex' => $entry['shadow_tint_hex'] ?? null,
        'shadow_tint_opacity' => $entry['shadow_tint_opacity'] ?? null,
        'is_preset' => $entry['is_preset'] ?? 0,
        'notes' => $entry['notes'] ?? null,
    ]);
    $flagStmt = $pdo->prepare("UPDATE applied_palettes SET needs_rerender = 1 WHERE asset_id = :asset");
    $flagStmt->execute([':asset' => $assetId]);

    respond(['ok' => true, 'setting' => $saved]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
}
