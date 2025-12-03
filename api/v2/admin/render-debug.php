<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Controllers\PhotosController;

function respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(405, ['error' => 'Use POST']);
    }

    $payload = json_decode(file_get_contents('php://input') ?? '', true);
    if (!is_array($payload)) {
        respond(400, ['error' => 'Invalid JSON body']);
    }

    $controller = new PhotosController($pdo);
    $result = $controller->renderApply($payload);

    respond(200, [
        'ok' => true,
        'render_url' => $result['render_url'] ?? null,
        'render_rel_path' => $result['render_rel_path'] ?? null,
        'details' => $result,
    ]);
} catch (\Throwable $e) {
    respond(500, [
        'error' => 'server',
        'message' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
        'type' => get_class($e),
    ]);
}
