<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

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
    if ($paletteId <= 0) {
        throw new InvalidArgumentException('palette_id required');
    }

    $repo = new PdoAppliedPaletteRepository($pdo);
    $pdo->beginTransaction();
    $repo->deleteById($paletteId);
    $pdo->commit();

    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 4), '/');
    $renderRel = "/photos/rendered/ap_{$paletteId}.jpg";
    $thumbRel = "/photos/rendered/ap_{$paletteId}-thumb.jpg";
    $renderAbs = $docRoot . $renderRel;
    $thumbAbs = $docRoot . $thumbRel;
    if (is_file($renderAbs)) {
        @unlink($renderAbs);
    }
    if (is_file($thumbAbs)) {
        @unlink($thumbAbs);
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
