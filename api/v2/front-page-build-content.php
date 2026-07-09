<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Repos\PdoFrontPageBuildContentRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$buildKey = trim((string)($_GET['build_key'] ?? ''));
if (!in_array($buildKey, ['public_home', 'admin_home'], true)) {
    respond(['ok' => false, 'error' => 'Invalid build_key'], 400);
}

try {
    $repo = new PdoFrontPageBuildContentRepository($pdo);
    respond([
        'ok' => true,
        'build_key' => $buildKey,
        'content' => $repo->getActiveMap($buildKey),
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => 'Unable to load front page build content'], 500);
}
