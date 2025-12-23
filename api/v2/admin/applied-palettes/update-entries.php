<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoAppliedPaletteRepository;
use App\Repos\PdoPhotoRepository;
use App\Repos\PdoColorRepository;
use App\Repos\PdoMaskBlendSettingRepository;
use App\Services\PhotoRenderingService;
use App\Services\MaskBlendService;
use RuntimeException;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST required'], 405);
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$paletteId = isset($input['palette_id']) ? (int)$input['palette_id'] : 0;
if ($paletteId <= 0) {
    respond(['ok' => false, 'error' => 'palette_id required'], 400);
}

$entries = $input['entries'] ?? [];
if (!is_array($entries) || !count($entries)) {
    respond(['ok' => false, 'error' => 'entries required'], 400);
}

$title = array_key_exists('title', $input) ? trim((string)$input['title']) : null;
$notes = array_key_exists('notes', $input) ? trim((string)$input['notes']) : null;
$tags = normalizeTags($input['tags'] ?? null);
$clearRender = !empty($input['clear_render']);
$renderOptions = isset($input['render']) && is_array($input['render']) ? $input['render'] : [];
$cacheRender = !empty($renderOptions['cache']);

try {
    $paletteRepo = new PdoAppliedPaletteRepository($pdo);
    $maskRepo = new PdoMaskBlendSettingRepository($pdo);
    $photoRepo = new PdoPhotoRepository($pdo);
    $maskService = new MaskBlendService($maskRepo, $photoRepo);
    $palette = $paletteRepo->findById($paletteId);
    if (!$palette) {
        respond(['ok' => false, 'error' => 'Palette not found'], 404);
    }
    $paletteAssetId = is_array($palette) ? (string)($palette['asset_id'] ?? '') : (string)$palette->assetId;
    $palettePhotoId = is_array($palette) ? (int)($palette['photo_id'] ?? 0) : (int)$palette->photoId;
    if ($paletteAssetId === '' || $palettePhotoId <= 0) {
        respond(['ok' => false, 'error' => 'Palette missing asset/photo link'], 400);
    }

    $pdo->beginTransaction();

    $fields = [];
    if ($title !== null) $fields['title'] = $title === '' ? null : $title;
    if ($notes !== null) $fields['notes'] = $notes === '' ? null : $notes;
    if (array_key_exists('tags', $input)) $fields['tags'] = $tags;
    if ($fields) {
        $paletteRepo->updatePalette($paletteId, $fields);
    }

    $pdo->prepare("DELETE FROM applied_palette_entries WHERE applied_palette_id = :id")
        ->execute([':id' => $paletteId]);

    $saved = 0;
    $flagPairs = [];
    foreach ($entries as $entry) {
        $maskRole = trim((string)($entry['mask_role'] ?? ''));
        $colorId = isset($entry['color_id']) ? (int)$entry['color_id'] : 0;
        if ($maskRole === '' || $colorId <= 0) {
            continue;
        }

        // Upsert shared mask setting (single source of truth)
        $blendMode = normalizeMode($entry['blend_mode'] ?? null);
        $blendOpacity = normalizeOpacity($entry['blend_opacity'] ?? null);
        $shadowLOffset = normalizeFloat($entry['shadow_l_offset'] ?? $entry['lightness_offset'] ?? null) ?? 0.0;
        $shadowTintHex = normalizeHex($entry['shadow_tint_hex'] ?? $entry['tint_hex'] ?? null);
        $shadowTintOpacity = normalizeOpacity($entry['shadow_tint_opacity'] ?? $entry['tint_opacity'] ?? null) ?? 0.0;

        // Save into shared mask_blend_settings (single source of truth)
        $settingId = $entry['mask_setting_id'] ?? null;
        if (!$settingId && $colorId > 0) {
            $existing = $maskRepo->findForAssetMaskColor($paletteAssetId, $maskRole, $colorId);
            if ($existing && !empty($existing['id'])) {
                $settingId = (int)$existing['id'];
            }
        }
        $settingPayload = [
            'id' => $settingId,
            'photo_id' => $palettePhotoId,
            'asset_id' => $paletteAssetId,
            'mask_role' => $maskRole,
            'color_id' => $colorId,
            'color_name' => $entry['color_name'] ?? null,
            'color_brand' => $entry['color_brand'] ?? null,
            'color_code' => $entry['color_code'] ?? null,
            'color_hex' => $entry['color_hex'] ?? null,
            'base_lightness' => normalizeFloat($entry['base_lightness'] ?? null),
            'target_lightness' => normalizeFloat($entry['target_lightness'] ?? null),
            'target_h' => normalizeFloat($entry['target_h'] ?? null),
            'target_c' => normalizeFloat($entry['target_c'] ?? null),
            'blend_mode' => $blendMode,
            'blend_opacity' => $blendOpacity,
            'shadow_l_offset' => $shadowLOffset,
            'shadow_tint_hex' => $shadowTintHex,
            'shadow_tint_opacity' => $shadowTintOpacity,
            'is_preset' => (int)($entry['is_preset'] ?? 0),
            'notes' => $entry['notes'] ?? null,
        ];
        if (array_key_exists('approved', $entry) && $entry['approved'] !== null) {
            $settingPayload['approved'] = $entry['approved'];
        }
        $setting = $maskService->saveSetting($paletteAssetId, $maskRole, $settingPayload);

        $paletteRepo->insertPaletteEntry($paletteId, [
            'mask_role' => $maskRole,
            'color_id' => $colorId,
            'mask_setting_id' => $setting['id'] ?? null,
            'mask_setting_revision' => $setting['revision'] ?? null,
            // legacy fields remain null to avoid stale duplication
            'blend_mode' => null,
            'blend_opacity' => null,
            'shadow_l_offset' => null,
            'shadow_tint_hex' => null,
            'shadow_tint_opacity' => null,
        ]);
        $saved++;
        $flagPairs[$maskRole . ':' . $colorId] = [
            'mask_role' => $maskRole,
            'color_id' => $colorId,
        ];
    }

    if ($saved === 0) {
        throw new RuntimeException('No entries saved');
    }

    if ($flagPairs) {
        $flagStmt = $pdo->prepare("
            UPDATE applied_palettes ap
            JOIN applied_palette_entries ape ON ape.applied_palette_id = ap.id
            SET ap.needs_rerender = 1, ap.updated_at = NOW()
            WHERE ap.asset_id = :asset_id
              AND ape.mask_role = :mask_role
              AND ape.color_id = :color_id
        ");
        foreach ($flagPairs as $pair) {
            $flagStmt->execute([
                ':asset_id' => $paletteAssetId,
                ':mask_role' => $pair['mask_role'],
                ':color_id' => $pair['color_id'],
            ]);
        }
    }

    $pdo->prepare("UPDATE applied_palettes SET needs_rerender = 1, updated_at = NOW() WHERE id = :id")
        ->execute([':id' => $paletteId]);

    $pdo->commit();

    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 4), '/');
    $renderRel = "/photos/rendered/ap_{$paletteId}.jpg";
    $thumbRel = "/photos/rendered/ap_{$paletteId}-thumb.jpg";
    $renderAbs = $docRoot . $renderRel;
    $thumbAbs = $docRoot . $thumbRel;

    if ($clearRender || $cacheRender) {
        if (is_file($renderAbs)) @unlink($renderAbs);
        if (is_file($thumbAbs)) @unlink($thumbAbs);
    }

    $renderInfo = null;
    $renderError = null;
    if ($cacheRender) {
        $photoRepo = new PdoPhotoRepository($pdo);
        $renderService = new PhotoRenderingService($photoRepo, $pdo, new PdoColorRepository($pdo));
        $latest = $paletteRepo->findById($paletteId);
        if ($latest) {
            try {
                $renderInfo = $renderService->cacheAppliedPalette($latest);
                $pdo->prepare("UPDATE applied_palettes SET needs_rerender = 0, updated_at = NOW() WHERE id = :id")
                    ->execute([':id' => $paletteId]);
            } catch (Throwable $renderEx) {
                $renderError = $renderEx->getMessage();
            }
        } else {
            $renderError = 'Palette not found after update';
        }
    }

    respond([
        'ok' => true,
        'palette_id' => $paletteId,
        'entries_saved' => $saved,
        'render_cache' => $renderInfo,
        'render_cache_error' => $renderError,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}

function normalizeHex(?string $hex): ?string {
    if (!$hex) return null;
    $value = ltrim(trim($hex), '#');
    if ($value === '') return null;
    if (strlen($value) === 3) {
        $value = strtoupper($value);
        $value = "{$value[0]}{$value[0]}{$value[1]}{$value[1]}{$value[2]}{$value[2]}";
    }
    $value = strtoupper($value);
    return ctype_xdigit($value) && strlen($value) === 6 ? $value : null;
}

function normalizeMode($mode): ?string {
    if (!is_string($mode)) return null;
    $value = strtolower(trim($mode));
    $value = str_replace([' ', '_', '-'], '', $value);
    return $value !== '' ? $value : null;
}

function normalizeOpacity($value): ?float {
    if ($value === null || $value === '') return null;
    $num = (float)$value;
    if (!is_finite($num)) return null;
    if ($num > 1.0) {
        $num = $num / 100;
    }
    return max(0.0, min(1.0, $num));
}

function normalizeFloat($value): ?float {
    if ($value === null || $value === '') return null;
    $num = (float)$value;
    return is_finite($num) ? $num : null;
}

function normalizeTags($input): ?string {
    if ($input === null || $input === '') return null;
    if (is_array($input)) {
        $sanitized = array_filter(array_map(function ($tag) {
            return trim((string)$tag);
        }, $input), fn($val) => $val !== '');
        if (!$sanitized) return null;
        return implode(',', array_slice(array_unique($sanitized), 0, 40));
    }
    $parts = array_filter(array_map('trim', preg_split('/[,;]/', (string)$input)));
    if (!$parts) return null;
    $unique = array_slice(array_unique($parts), 0, 40);
    return implode(',', $unique);
}
