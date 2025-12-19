<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../services/MaskTrainingService.php';

use App\Services\MaskTrainingService;

try {
    $svc = new MaskTrainingService($pdo);
    $colors = $svc->getTesterColors();
    echo json_encode(['ok' => true, 'items' => $colors], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
