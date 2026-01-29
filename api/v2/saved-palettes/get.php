<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Repos\PdoSavedPaletteRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$hash = isset($_GET['hash']) ? trim((string)$_GET['hash']) : '';
if ($hash === '') {
    respond(['ok' => false, 'error' => 'hash required'], 400);
}

try {
    $repo = new PdoSavedPaletteRepository($pdo);
    $full = $repo->getFullPaletteByHash($hash);
    if ($full === null) {
        respond(['ok' => false, 'error' => 'palette not found'], 404);
    }

    respond([
        'ok' => true,
        'palette' => $full['palette'],
        'members' => $full['members'] ?? [],
    ]);
} catch (\Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
