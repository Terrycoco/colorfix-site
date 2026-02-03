<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Controllers\SavedPaletteController;
use App\Repos\PdoSavedPaletteRepository;
use App\Services\SavedPaletteService;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Use POST with a JSON body'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (empty($payload['color_ids']) || !is_array($payload['color_ids'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'color_ids required'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $paletteRepo = new PdoSavedPaletteRepository($pdo);
    $service     = new SavedPaletteService($paletteRepo);
    $controller  = new SavedPaletteController($service);

    $result = $controller->saveFromPayload($payload);
    echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
