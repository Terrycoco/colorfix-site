<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/auth.php';

use App\Repos\PdoSavedPaletteRepository;
use App\Services\PaletteViewerTokenService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respond(['ok' => false, 'error' => 'POST only'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        respond(['ok' => false, 'error' => 'Invalid JSON body'], 400);
    }

    $paletteId = isset($payload['palette_id']) ? (int)$payload['palette_id'] : 0;
    $setId = isset($payload['set_id']) ? (int)$payload['set_id'] : 0;
    $templateKey = strtolower(trim((string)($payload['template_key'] ?? 'full_palette')));
    if (!in_array($templateKey, ['full_palette', 'concept', 'client', 'painter'], true)) {
        $templateKey = 'full_palette';
    }

    if ($paletteId <= 0) {
        respond(['ok' => false, 'error' => 'palette_id required'], 400);
    }

    $repo = new PdoSavedPaletteRepository($pdo);
    $palette = $repo->getSavedPaletteById($paletteId);
    if (!$palette) {
        respond(['ok' => false, 'error' => 'Saved palette not found'], 404);
    }

    if ($setId > 0) {
        $set = $repo->getSetById($setId);
        if (!$set || (int)($set['saved_palette_id'] ?? 0) !== $paletteId) {
            respond(['ok' => false, 'error' => 'Saved palette set not found'], 404);
        }
    }

    $tokenService = new PaletteViewerTokenService($pdo);
    $url = $tokenService->createSavedPaletteUrl(
        (string)($palette['palette_hash'] ?? ''),
        $setId > 0 ? $setId : null,
        $templateKey,
        null
    );

    respond(['ok' => true, 'url' => $url]);
} catch (\InvalidArgumentException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
