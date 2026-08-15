<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond([
            'ok' => false,
            'error' => 'POST only',
        ], 405);
    }

    $stmt = $pdo->prepare(
        "DELETE FROM analytics_events
         WHERE is_test = 1"
    );

    $stmt->execute();

    respond([
        'ok' => true,
        'deleted' => $stmt->rowCount(),
    ]);
} catch (Throwable $e) {
    respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}