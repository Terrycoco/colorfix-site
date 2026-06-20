<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Repos\PdoLandingPageRepository;
use App\Services\LandingPageService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

try {
    $slug = trim((string)($_GET['slug'] ?? ''));
    $src = isset($_GET['src']) ? trim((string)$_GET['src']) : null;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $baseUrl = $host !== '' ? $scheme . '://' . $host : '';
    $service = new LandingPageService(new PdoLandingPageRepository($pdo), $baseUrl);
    $page = $service->getPublicPage($slug, $src);
    if (!$page) {
        respond(['ok' => false, 'error' => 'Landing page not found'], 404);
    }
    respond(['ok' => true, 'item' => $page]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
