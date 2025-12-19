<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoAppliedPaletteRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'GET only']);
        exit;
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        throw new InvalidArgumentException('id required');
    }

    $repo = new PdoAppliedPaletteRepository($pdo);
    $palette = $repo->findById($id);
    if (!$palette) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Palette not found']);
        exit;
    }

    $metaStmt = $pdo->prepare("SELECT needs_rerender, created_at, updated_at FROM applied_palettes WHERE id = :id");
    $metaStmt->execute([':id' => $id]);
    $meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 4), '/');
    $publicRoot = rtrim(dirname(__DIR__, 4) . '/public', '/');
    $renderRel = "/photos/rendered/ap_{$id}.jpg";
    $thumbRel = "/photos/rendered/ap_{$id}-thumb.jpg";
    $renderAbs = is_file($docRoot . $renderRel) ? $docRoot . $renderRel : $publicRoot . $renderRel;
    $thumbAbs = is_file($docRoot . $thumbRel) ? $docRoot . $thumbRel : $publicRoot . $thumbRel;

    echo json_encode([
        'ok' => true,
        'palette' => [
            'id' => $palette->id,
            'title' => $palette->title,
            'notes' => $palette->notes,
            'tags' => $palette->tags,
            'photo_id' => $palette->photoId,
            'asset_id' => $palette->assetId,
            'needs_rerender' => !empty($meta['needs_rerender']),
            'created_at' => $meta['created_at'] ?? null,
            'updated_at' => $meta['updated_at'] ?? null,
            'render_rel_path' => is_file($renderAbs) ? $renderRel : null,
            'render_thumb_rel_path' => is_file($thumbAbs) ? $thumbRel : null,
        ],
        'entries' => $palette->entries,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
