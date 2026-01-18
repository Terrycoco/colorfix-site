<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../services/MaskTrainingService.php';

use App\Services\MaskTrainingService;

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_REQUEST;
    $maskRole = trim((string)($input['mask_role'] ?? ''));
    $colorId = (int)($input['color_id'] ?? 0);
    $photoId = isset($input['photo_id']) ? (int)$input['photo_id'] : null;
    $assetId = isset($input['asset_id']) ? trim((string)$input['asset_id']) : null;

    if ($maskRole === '' || $colorId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'mask_role and color_id are required'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $debug = null;
    $svc = new MaskTrainingService($pdo);
    $guess = $svc->guessSettings($maskRole, $colorId, 5, $photoId, $assetId, $debug);
    if (!$guess) {
        echo json_encode(['ok' => false, 'error' => 'No training samples'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $payload = ['ok' => true, 'guess' => $guess];
    if (!empty($input['debug'])) {
        $payload['debug'] = $debug;
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
