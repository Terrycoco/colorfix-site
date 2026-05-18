<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$startedAt = microtime(true);

require_once __DIR__ . '/../db.php';

function elapsed_ms(float $startedAt): float {
    return round((microtime(true) - $startedAt) * 1000, 1);
}

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$dbStartedAt = microtime(true);
try {
    $stmt = $pdo->query('SELECT 1');
    $ok = (int)$stmt->fetchColumn() === 1;
    respond([
        'ok' => $ok,
        'service' => 'colorfix',
        'time_utc' => gmdate('c'),
        'checks' => [
            'php' => 'ok',
            'db' => $ok ? 'ok' : 'failed',
        ],
        'timing_ms' => [
            'db' => elapsed_ms($dbStartedAt),
            'total' => elapsed_ms($startedAt),
        ],
    ], $ok ? 200 : 503);
} catch (Throwable $e) {
    respond([
        'ok' => false,
        'service' => 'colorfix',
        'time_utc' => gmdate('c'),
        'checks' => [
            'php' => 'ok',
            'db' => 'failed',
        ],
        'error' => $e->getMessage(),
        'timing_ms' => [
            'db' => elapsed_ms($dbStartedAt),
            'total' => elapsed_ms($startedAt),
        ],
    ], 503);
}
