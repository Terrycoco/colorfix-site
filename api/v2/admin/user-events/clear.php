<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$confirmation = trim((string)($payload['confirm'] ?? ''));
if ($confirmation !== 'CLEAR_USER_EVENTS') {
    respond(['ok' => false, 'error' => 'Confirmation required'], 400);
}

try {
    $count = (int)$pdo->query('SELECT COUNT(*) FROM user_events')->fetchColumn();
    $pdo->exec('DELETE FROM user_events');
    respond([
        'ok' => true,
        'deleted' => $count,
    ]);
} catch (Throwable $e) {
    respond([
        'ok' => false,
        'error' => 'Failed to clear user events',
    ], 500);
}
