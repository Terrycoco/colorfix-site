<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Repos\PdoPhotoRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    respond(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input');
$input = [];
if ($raw !== '' && $raw !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $input = $decoded;
}

$assetId = trim((string)($input['asset_id'] ?? ''));
$maskRole = trim((string)($input['mask'] ?? $input['mask_role'] ?? $input['role'] ?? ''));
$settings = $input['settings'] ?? null;
$originalTexture = $input['original_texture'] ?? null;

if ($assetId === '' || $maskRole === '' || !is_array($settings)) {
    respond(['ok' => false, 'error' => 'asset_id, mask, and settings required'], 400);
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    respond(['ok' => false, 'error' => 'DB not initialized'], 500);
}

$repo = new PdoPhotoRepository($pdo);
$photo = $repo->getPhotoByAssetId($assetId);
if (!$photo) respond(['ok' => false, 'error' => "asset not found: {$assetId}"], 404);

$normalized = normalize_settings($settings);
$textureNormalized = normalize_texture($originalTexture);
$updated = $repo->updateMaskOverlay((int)$photo['id'], $maskRole, $normalized, $textureNormalized);
if (!$updated) {
    respond(['ok' => false, 'error' => 'Mask not found or no overlay columns present'], 404);
}

$flagStmt = $pdo->prepare("
    UPDATE applied_palettes ap
    JOIN applied_palette_entries ape ON ape.applied_palette_id = ap.id
    SET ap.needs_rerender = 1
    WHERE ap.asset_id = :asset_id
      AND ape.mask_role = :mask_role
");
$flagStmt->execute([
    ':asset_id' => $assetId,
    ':mask_role' => $maskRole,
]);

respond([
    'ok' => true,
    'mask' => $maskRole,
    'settings' => $normalized,
    'original_texture' => $textureNormalized,
]);

function normalize_settings(array $settings): array {
    $tiers = ['dark','medium','light'];
    $out = [];
    foreach ($tiers as $tier) {
        $row = $settings[$tier] ?? [];
        $out[$tier] = [
            'mode'    => normalize_mode($row['mode'] ?? null),
            'opacity' => normalize_opacity($row['opacity'] ?? null),
        ];
    }
    $out['_shadow'] = normalize_shadow($settings['_shadow'] ?? null);
    return $out;
}

function normalize_texture($value): ?string {
    if (!is_string($value)) return null;
    $trim = strtolower(trim($value));
    if ($trim === '') return null;
    $trim = str_replace([' ', '-'], '_', $trim);
    $trim = preg_replace('/[^a-z0-9_]+/', '_', $trim);
    $trim = preg_replace('/_+/', '_', $trim);
    $trim = trim($trim, '_');
    if ($trim === '') return null;
    return substr($trim, 0, 64);
}

function normalize_mode($mode): ?string {
    if (!is_string($mode)) return null;
    $m = strtolower(trim($mode));
    $allowed = ['colorize','hardlight','softlight','overlay','multiply','screen','luminosity','flatpaint','original'];
    return in_array($m, $allowed, true) ? $m : null;
}

function normalize_opacity($val): ?float {
    if ($val === '' || $val === null) return null;
    $num = (float)$val;
    if (!is_finite($num)) return null;
    if ($num < 0) $num = 0;
    if ($num > 1) $num = 1;
    return $num;
}

function normalize_shadow($raw): array {
    $source = is_array($raw) ? $raw : [];
    $offset = normalize_shadow_offset($source['l_offset'] ?? null);
    $tint = normalize_shadow_tint($source['tint_hex'] ?? null);
    $opacity = normalize_shadow_tint_opacity($source['tint_opacity'] ?? null);
    return [
        'l_offset' => $offset,
        'tint_hex' => $tint,
        'tint_opacity' => $opacity,
    ];
}

function normalize_shadow_offset($val): float {
    if ($val === null || $val === '') return 0.0;
    $num = (float)$val;
    if (!is_finite($num)) $num = 0.0;
    if ($num < -50) $num = -50;
    if ($num > 50) $num = 50;
    return $num;
}

function normalize_shadow_tint($val): ?string {
    if (!is_string($val)) return null;
    $trim = strtoupper(trim($val));
    $trim = ltrim($trim, '#');
    if (strlen($trim) === 3) {
        $trim = $trim[0].$trim[0].$trim[1].$trim[1].$trim[2].$trim[2];
    }
    if (!preg_match('/^[0-9A-F]{6}$/', $trim)) return null;
    return '#'.$trim;
}

function normalize_shadow_tint_opacity($val): float {
    if ($val === null || $val === '') return 0.0;
    $num = (float)$val;
    if (!is_finite($num)) $num = 0.0;
    if ($num < 0) $num = 0;
    if ($num > 1) $num = 1;
    return $num;
}
