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

$repo = new PdoMaskBlendSettingRepository($pdo);
$service = new MaskBlendService(
    $repo,
    new PdoPhotoRepository($pdo)
);

try {
$idFromPayload = $entry['id'] ?? null;
// if no id provided, try to find existing by asset/mask/color to avoid duplicate constraint
if (!$idFromPayload && !empty($entry['color_id'])) {
    $existing = $repo->findForAssetMaskColor($assetId, $mask, (int)$entry['color_id']);
    if ($existing && !empty($existing['id'])) {
        $idFromPayload = (int)$existing['id'];
    }
}
$existingRow = null;
if ($idFromPayload) {
    $existingRow = $repo->findById((int)$idFromPayload);
}

// Preserve approved flag if an existing row is already approved
if ($existingRow && !empty($existingRow['approved'])) {
    $entry['approved'] = 1;
}

$normalizedEntry = normalizeEntryForCompare($entry, $existingRow);
if ($existingRow && entriesMatch($existingRow, $normalizedEntry)) {
    respond(['ok' => true, 'setting' => $existingRow]);
}

$settingPayload = [
    'id' => $idFromPayload,
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
];
if (array_key_exists('approved', $entry) && $entry['approved'] !== null) {
    $settingPayload['approved'] = $entry['approved'];
}
$saved = $service->saveSetting($assetId, $mask, $settingPayload);
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
        ':color' => (int)($saved['color_id'] ?? 0),
    ]);

    respond(['ok' => true, 'setting' => $saved]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
}

function normalizeEntryForCompare(array $entry, ?array $existing): array {
    $colorHex = isset($entry['color_hex']) ? strtoupper(ltrim((string)$entry['color_hex'], '#')) : null;
    if ($colorHex === '') $colorHex = null;
    $shadowHex = isset($entry['shadow_tint_hex']) ? strtoupper(ltrim((string)$entry['shadow_tint_hex'], '#')) : null;
    if ($shadowHex === '') $shadowHex = null;
    $baseLightness = array_key_exists('base_lightness', $entry)
        ? (float)$entry['base_lightness']
        : ($existing['base_lightness'] ?? null);
    $blendMode = isset($entry['blend_mode']) && $entry['blend_mode'] !== ''
        ? strtolower((string)$entry['blend_mode'])
        : 'colorize';
    $blendOpacity = array_key_exists('blend_opacity', $entry)
        ? (float)$entry['blend_opacity']
        : 1.0;
    $shadowOffset = array_key_exists('shadow_l_offset', $entry)
        ? clampFloat($entry['shadow_l_offset'], -50.0, 50.0)
        : null;
    $shadowOpacity = array_key_exists('shadow_tint_opacity', $entry)
        ? clampFloat($entry['shadow_tint_opacity'], 0.0, 1.0)
        : null;

    $approved = array_key_exists('approved', $entry)
        ? (int)$entry['approved']
        : (int)($existing['approved'] ?? 0);
    return [
        'color_id' => isset($entry['color_id']) ? (int)$entry['color_id'] : null,
        'color_name' => normalizeText($entry['color_name'] ?? null),
        'color_brand' => normalizeText($entry['color_brand'] ?? null),
        'color_code' => normalizeText($entry['color_code'] ?? null),
        'color_hex' => $colorHex,
        'base_lightness' => $baseLightness,
        'blend_mode' => $blendMode,
        'blend_opacity' => $blendOpacity,
        'shadow_l_offset' => $shadowOffset,
        'shadow_tint_hex' => $shadowHex,
        'shadow_tint_opacity' => $shadowOpacity,
        'is_preset' => isset($entry['is_preset']) ? (int)$entry['is_preset'] : 0,
        'approved' => $approved,
        'notes' => normalizeText($entry['notes'] ?? null),
    ];
}

function normalizeText($value): ?string {
    if ($value === null) return null;
    $trim = trim((string)$value);
    return $trim === '' ? null : $trim;
}

function clampFloat($value, float $min, float $max): ?float {
    if ($value === null || $value === '') return null;
    $num = (float)$value;
    if (!is_finite($num)) return null;
    if ($num < $min) $num = $min;
    if ($num > $max) $num = $max;
    return $num;
}

function entriesMatch(array $existing, array $normalized): bool {
    $map = [
        'color_id',
        'color_name',
        'color_brand',
        'color_code',
        'color_hex',
        'base_lightness',
        'blend_mode',
        'blend_opacity',
        'shadow_l_offset',
        'shadow_tint_hex',
        'shadow_tint_opacity',
        'is_preset',
        'approved',
        'notes',
    ];
    foreach ($map as $key) {
        $left = $existing[$key] ?? null;
        $right = $normalized[$key] ?? null;
        if (is_numeric($left) && is_numeric($right)) {
            if ((float)$left !== (float)$right) return false;
            continue;
        }
        if ($left !== $right) return false;
    }
    return true;
}
