<?php
// /api/v2/admin/color-save.php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Services\ColorSaveService;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Use POST with a JSON body'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $raw  = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Normalize booleans to 0/1 if provided
    if (array_key_exists('exterior', $data)) $data['exterior'] = $data['exterior'] ? 1 : 0;
    if (array_key_exists('interior', $data)) $data['interior'] = $data['interior'] ? 1 : 0;

    $svc = new ColorSaveService();
    $res = $svc->save($data, $pdo);

    echo json_encode($res, JSON_UNESCAPED_SLASHES);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
