<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../auth.php';

use App\REX\Resources\RexRouteCatalog;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'ok' => false,
            'error' => 'GET only',
        ]);
        exit;
    }

    $items = [];

    foreach (RexRouteCatalog::all() as $id => $route) {
        $items[] = [
            'resource_id' => (int)$id,
            'title' => (string)($route['title'] ?? ''),
            'path' => (string)($route['path'] ?? ''),
        ];
    }

    echo json_encode([
        'ok' => true,
        'items' => $items,
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}