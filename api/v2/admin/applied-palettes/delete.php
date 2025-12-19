<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use PDO;
use App\Repos\PdoAppliedPaletteRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required']);
        exit;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Invalid JSON');
    }
    $paletteId = isset($payload['palette_id']) ? (int)$payload['palette_id'] : 0;
    $assetIdInput = isset($payload['asset_id']) ? trim((string)$payload['asset_id']) : '';
    if ($paletteId <= 0) {
        throw new InvalidArgumentException('palette_id required');
    }

    $repo = new PdoAppliedPaletteRepository($pdo);
    $assetId = null;
    $stmt = $pdo->prepare("SELECT asset_id FROM applied_palettes WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $paletteId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['asset_id'])) {
        $assetId = (string)$row['asset_id'];
    }
    if (!$assetId && $assetIdInput !== '') {
        $assetId = $assetIdInput;
    }

    $pdo->beginTransaction();
    $debug = [];

    $debug['entries_deleted'] = execDelete($pdo, "DELETE FROM applied_palette_entries WHERE applied_palette_id = :id", [':id' => $paletteId]);
    $debug['shares_deleted'] = execDelete($pdo, "DELETE FROM applied_palette_shares WHERE applied_palette_id = :id", [':id' => $paletteId]);
    $debug['client_links_deleted'] = execDelete($pdo, "DELETE FROM client_applied_palettes WHERE applied_palette_id = :id", [':id' => $paletteId]);
    $debug['palette_deleted'] = execDelete($pdo, "DELETE FROM applied_palettes WHERE id = :id", [':id' => $paletteId]);

    // Fallback: if nothing deleted and we have an asset id, attempt delete by asset id.
    if (
        !$debug['entries_deleted']
        && !$debug['shares_deleted']
        && !$debug['client_links_deleted']
        && !$debug['palette_deleted']
        && $assetId
    ) {
        $debug['fallback_asset_entries_deleted'] = execDelete($pdo, "DELETE FROM applied_palette_entries WHERE applied_palette_id IN (SELECT id FROM applied_palettes WHERE asset_id = :asset LIMIT 10)", [':asset' => $assetId]);
        $debug['fallback_asset_palettes_deleted'] = execDelete($pdo, "DELETE FROM applied_palettes WHERE asset_id = :asset", [':asset' => $assetId]);
    }

    $pdo->commit();

    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 4), '/');
    $renderRel = "/photos/rendered/ap_{$paletteId}.jpg";
    $thumbRel = "/photos/rendered/ap_{$paletteId}-thumb.jpg";
    $renderAbs = $docRoot . $renderRel;
    $thumbAbs = $docRoot . $thumbRel;

    $deletedRender = false;
    if (is_file($renderAbs) && @unlink($renderAbs)) $deletedRender = true;
    if (is_file($thumbAbs)) @unlink($thumbAbs);

    $stillExists = false;
    if ($assetId !== null) {
        $checkStmt = $pdo->prepare("SELECT id FROM applied_palettes WHERE id = :id LIMIT 1");
        $checkStmt->execute([':id' => $paletteId]);
        $stillExists = (bool)$checkStmt->fetchColumn();

        if (!$stillExists && $assetId) {
            $checkAssetStmt = $pdo->prepare("SELECT id FROM applied_palettes WHERE asset_id = :asset LIMIT 1");
            $checkAssetStmt->execute([':asset' => $assetId]);
            $stillExists = (bool)$checkAssetStmt->fetchColumn();
        }
    }

    $ok = !$stillExists;
    $payload = [
        'ok' => $ok,
        'palette_id' => $paletteId,
        'asset_id' => $assetId,
        'deleted_render' => $deletedRender,
        'found' => (bool)$row,
        'debug' => $debug,
        'still_exists' => $stillExists,
    ];
    if (!$ok) {
        $payload['error'] = 'Palette still present after delete attempt';
    }

    logDeleteEvent($payload);

    echo json_encode($payload);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    $payload = ['ok' => false, 'error' => $e->getMessage()];
    logDeleteEvent($payload);
    echo json_encode($payload);
}

function execDelete(PDO $pdo, string $sql, array $params): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->rowCount();
    } catch (Throwable $e) {
        // log to error log but don't block deletion
        error_log('AP delete ignore: ' . $e->getMessage());
        return 0;
    }
}

function logDeleteEvent(array $payload): void
{
    $line = json_encode([
        'ts' => date('c'),
        'payload' => $payload,
    ], JSON_UNESCAPED_SLASHES);
    $logDir = dirname(__DIR__, 3) . '/logs';
    if (!is_dir($logDir)) {
        return;
    }
    @file_put_contents($logDir . '/ap-delete.log', $line . PHP_EOL, FILE_APPEND);
}
