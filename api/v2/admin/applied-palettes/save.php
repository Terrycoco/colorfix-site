<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoAppliedPaletteRepository;
use App\Repos\PdoClientRepository;
use App\Repos\PdoColorRepository;
use App\Repos\PdoMaskBlendSettingRepository;
use App\Repos\PdoPhotoRepository;
use App\Services\MaskBlendService;
use App\Services\PhotoRenderingService;
use RuntimeException;

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
$title = trim((string)($input['title'] ?? ($input['nickname'] ?? '')));
$notes = trim((string)($input['notes'] ?? ''));
$entries = $input['entries'] ?? [];
$clientId = isset($input['client_id']) ? (int)$input['client_id'] : null;
$clientPayload = isset($input['client']) && is_array($input['client']) ? $input['client'] : null;
$renderOptions = isset($input['render']) && is_array($input['render']) ? $input['render'] : [];
$cacheRender = !empty($renderOptions['cache']);

if ($assetId === '' || !is_array($entries) || !count($entries)) {
    respond(['ok' => false, 'error' => 'asset_id and entries required'], 400);
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    respond(['ok' => false, 'error' => 'DB not initialized'], 500);
}

$photoRepo = new PdoPhotoRepository($pdo);
$paletteRepo = new PdoAppliedPaletteRepository($pdo);
$clientRepo = new PdoClientRepository($pdo);
$maskRepo = new PdoMaskBlendSettingRepository($pdo);
$maskService = new MaskBlendService($maskRepo, $photoRepo);
$renderService = null;
if ($cacheRender) {
    $renderService = new PhotoRenderingService($photoRepo, $pdo, new PdoColorRepository($pdo));
}
$photo = $photoRepo->getPhotoByAssetId($assetId);
if (!$photo) {
    respond(['ok' => false, 'error' => "asset not found: {$assetId}"], 404);
}

if ($clientId !== null && $clientId <= 0) {
    $clientId = null;
}

if ($clientId && !$clientRepo->findById($clientId)) {
    $clientId = null;
}

if (!$clientId && $clientPayload) {
    $clientId = resolveClientFromPayload($clientRepo, $clientPayload);
}

try {
    $pdo->beginTransaction();
    $palette = $paletteRepo->insertPalette([
        'photo_id' => (int)$photo['id'],
        'asset_id' => $assetId,
        'title' => $title,
        'notes' => $notes,
    ]);
    $paletteId = $palette['id'];

    $savedEntries = 0;

    foreach ($entries as $entry) {
        $maskRole = trim((string)($entry['mask_role'] ?? ''));
        if ($maskRole === '') {
            continue;
        }
        $colorHex = normalizeHex($entry['color_hex'] ?? null);
        $blendMode = normalizeMode($entry['blend_mode'] ?? null);
        $blendOpacity = normalizeOpacity($entry['blend_opacity'] ?? null);
        $shadow = [
            'l_offset' => normalizeFloat($entry['shadow_l_offset'] ?? null),
            'tint_hex' => normalizeHex($entry['shadow_tint_hex'] ?? null),
            'tint_opacity' => normalizeOpacity($entry['shadow_tint_opacity'] ?? null),
        ];
        $colorId = isset($entry['color_id']) ? (int)$entry['color_id'] : null;
        if (!$colorId) continue;

        $settingId = null;
        $existing = $maskRepo->findForAssetMaskColor($assetId, $maskRole, $colorId);
        if ($existing && !empty($existing['id'])) {
            $settingId = (int)$existing['id'];
        }
        $setting = $maskService->saveSetting($assetId, $maskRole, [
            'id' => $settingId,
            'photo_id' => (int)$photo['id'],
            'asset_id' => $assetId,
            'mask_role' => $maskRole,
            'color_id' => $colorId,
            'color_name' => $entry['color_name'] ?? null,
            'color_brand' => $entry['color_brand'] ?? null,
            'color_code' => $entry['color_code'] ?? null,
            'color_hex' => $colorHex,
            'base_lightness' => normalizeFloat($entry['base_lightness'] ?? null),
            'target_lightness' => normalizeFloat($entry['target_lightness'] ?? null),
            'target_h' => normalizeFloat($entry['target_h'] ?? null),
            'target_c' => normalizeFloat($entry['target_c'] ?? null),
            'blend_mode' => $blendMode,
            'blend_opacity' => $blendOpacity,
            'shadow_l_offset' => $shadow['l_offset'],
            'shadow_tint_hex' => $shadow['tint_hex'],
            'shadow_tint_opacity' => $shadow['tint_opacity'],
            'is_preset' => (int)($entry['is_preset'] ?? 0),
            'approved' => array_key_exists('approved', $entry) ? $entry['approved'] : null,
            'notes' => $entry['notes'] ?? null,
        ]);

        $paletteRepo->insertPaletteEntry($paletteId, [
            'mask_role' => $maskRole,
            'color_id' => $colorId,
            'mask_setting_id' => $setting['id'] ?? null,
            'mask_setting_revision' => $setting['revision'] ?? null,
            'blend_mode' => null,
            'blend_opacity' => null,
            'shadow_l_offset' => null,
            'shadow_tint_hex' => null,
            'shadow_tint_opacity' => null,
        ]);
        $savedEntries++;
    }

    if ($savedEntries === 0) {
        throw new RuntimeException('No entries saved');
    }

    if ($clientId) {
        $paletteRepo->linkPaletteToClient($clientId, $paletteId, 'owner');
    }

    $pdo->commit();
    $renderInfo = null;
    $renderError = null;
    if ($cacheRender) {
        $entity = $paletteRepo->findById($paletteId);
        if ($entity) {
            try {
                $renderInfo = $renderService?->cacheAppliedPalette($entity);
            } catch (Throwable $renderEx) {
                $renderError = $renderEx->getMessage();
            }
        } else {
            $renderError = 'Palette not found after save';
        }
    }
    respond([
        'ok' => true,
        'palette_id' => $paletteId,
        'entries_saved' => $savedEntries,
        'render_cache' => $renderInfo,
        'render_cache_error' => $renderError,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
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

function resolveClientFromPayload(PdoClientRepository $clientRepo, array $payload): ?int {
    $explicitId = isset($payload['id']) ? (int)$payload['id'] : null;
    if ($explicitId) {
        $row = $clientRepo->findById($explicitId);
        if ($row) return $explicitId;
    }
    $email = trim((string)($payload['email'] ?? ''));
    $name = trim((string)($payload['name'] ?? ''));
    if ($name === '') {
        $first = trim((string)($payload['first_name'] ?? ''));
        $last = trim((string)($payload['last_name'] ?? ''));
        $name = trim(($first . ' ' . $last));
    }
    $phone = trim((string)($payload['phone'] ?? ''));
    $notes = trim((string)($payload['notes'] ?? ''));

    if ($email !== '') {
        $existing = $clientRepo->findByEmail($email);
        if ($existing) {
            $fields = [];
            if ($name !== '' && !empty($existing['name']) && $existing['name'] !== $name) {
                $fields['name'] = $name;
            } elseif ($name !== '' && empty($existing['name'])) {
                $fields['name'] = $name;
            }
            if ($phone !== '' && ($existing['phone'] ?? '') !== $phone) {
                $fields['phone'] = $phone;
            }
            if ($notes !== '' && ($existing['notes'] ?? '') !== $notes) {
                $fields['notes'] = $notes;
            }
            if ($fields) {
                $clientRepo->update((int)$existing['id'], $fields);
            }
            return (int)$existing['id'];
        }
    }

    if ($name === '' && $email === '') {
        return null;
    }

    return $clientRepo->create([
        'name' => $name !== '' ? $name : 'Client',
        'email' => $email !== '' ? $email : null,
        'phone' => $phone !== '' ? $phone : null,
        'notes' => $notes !== '' ? $notes : null,
    ]);
}
