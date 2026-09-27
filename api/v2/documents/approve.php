<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../../../autoload.php';
    require_once __DIR__ . '/../../../db.php';

    \App\PROJECTS\Endpoints\ProjectDocumentApproveEndpoint::handle($pdo);

} catch (\Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_UNESCAPED_SLASHES);
}