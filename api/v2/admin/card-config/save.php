<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../functions/card-config.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$targetUrl = normalizeCardTargetUrl((string)($payload['target_url'] ?? '/'));
if ($targetUrl === '') {
    $targetUrl = '/';
}

if (!saveCardConfig($targetUrl, $pdo ?? null)) {
    respond(['ok' => false, 'error' => 'Failed to save card config'], 500);
}

respond([
    'ok' => true,
    'item' => [
        'target_url' => $targetUrl,
    ],
]);
