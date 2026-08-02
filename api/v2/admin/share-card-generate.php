<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/auth.php';

use App\Repos\PdoSavedPaletteRepository;
use App\Services\ShareCardService;

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

    $repo = new PdoSavedPaletteRepository($pdo);
    $service = new ShareCardService($repo, dirname(__DIR__, 3));
    $result = $service->generateSavedPaletteCard($paletteId, $setId, $templateKey);

    respond(['ok' => true, 'data' => $result]);
} catch (\InvalidArgumentException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
