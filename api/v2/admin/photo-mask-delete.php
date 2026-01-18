<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Repos\PdoPhotoRepository;

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

$assetId = trim((string)($input['asset_id'] ?? ''));
$maskRole = trim((string)($input['mask_role'] ?? ''));

if ($assetId === '' || $maskRole === '') {
    respond(['ok' => false, 'error' => 'asset_id and mask_role required'], 400);
}

try {
    $photoRepo = new PdoPhotoRepository($pdo);
    $photo = $photoRepo->getPhotoByAssetId($assetId);
    if (!$photo) {
        respond(['ok' => false, 'error' => 'Asset not found'], 404);
    }
    $photoId = (int)$photo['id'];

    $variants = $photoRepo->listVariants($photoId);
    $maskVariants = array_values(array_filter($variants, function ($row) use ($maskRole) {
        $kind = (string)($row['kind'] ?? '');
        $role = (string)($row['role'] ?? '');
        if ($kind === 'masks' && $role === $maskRole) return true;
        if ($kind === 'mask' && $role === $maskRole) return true;
        if (str_starts_with($kind, 'mask:')) {
            $legacyRole = $role !== '' ? $role : substr($kind, 5);
            return $legacyRole === $maskRole;
        }
        return false;
    }));

    if (!$maskVariants) {
        respond(['ok' => false, 'error' => 'Mask not found for role'], 404);
    }

    $pdo->beginTransaction();

    $variantIds = array_map(fn($row) => (int)$row['id'], $maskVariants);
    $inClause = implode(',', array_fill(0, count($variantIds), '?'));
    $stmt = $pdo->prepare("DELETE FROM photos_variants WHERE id IN ({$inClause})");
    $stmt->execute($variantIds);

    $stmt = $pdo->prepare("DELETE FROM photos_mask_stats WHERE photo_id = ? AND role = ?");
    $stmt->execute([$photoId, $maskRole]);

    $stmt = $pdo->prepare("DELETE FROM mask_blend_settings WHERE photo_id = ? AND mask_role = ?");
    $stmt->execute([$photoId, $maskRole]);

    $stmt = $pdo->prepare("
        DELETE ape
        FROM applied_palette_entries ape
        JOIN applied_palettes ap ON ap.id = ape.applied_palette_id
        WHERE ap.asset_id = ?
          AND ape.mask_role = ?
    ");
    $stmt->execute([$assetId, $maskRole]);

    $stmt = $pdo->prepare("
        UPDATE applied_palettes
        SET needs_rerender = 1, updated_at = NOW()
        WHERE asset_id = ?
    ");
    $stmt->execute([$assetId]);

    $stmt = $pdo->prepare("DELETE FROM hoa_scheme_mask_maps WHERE asset_id = ? AND mask_role = ?");
    $stmt->execute([$assetId, $maskRole]);

    $pdo->commit();

    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 3), '/');
    foreach ($maskVariants as $variant) {
        $rel = (string)($variant['path'] ?? '');
        if ($rel === '') continue;
        $abs = $docRoot . '/' . ltrim($rel, '/');
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    respond(['ok' => true, 'deleted_variants' => count($variantIds)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
