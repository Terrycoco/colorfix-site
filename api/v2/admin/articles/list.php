<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Controllers\ArticleController;
use App\Repos\PdoArticleRepository;
use App\Services\ArticleService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$filters = [];
$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
if ($type !== '') $filters['type'] = $type;

$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
if ($status !== '') $filters['status'] = $status;

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if ($q !== '') $filters['q'] = $q;

$tagsRaw = isset($_GET['tags']) ? trim((string)$_GET['tags']) : '';
if ($tagsRaw !== '') {
    $tagSlugs = array_values(array_filter(array_map('trim', preg_split('/[|,]/', $tagsRaw))));
    $filters['tag_slugs'] = $tagSlugs;
}

$tagIdsRaw = isset($_GET['tag_ids']) ? trim((string)$_GET['tag_ids']) : '';
if ($tagIdsRaw !== '') {
    $tagIds = array_values(array_filter(array_map('intval', preg_split('/[|,]/', $tagIdsRaw))));
    $filters['tag_ids'] = $tagIds;
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

try {
    $repo = new PdoArticleRepository($pdo);
    $service = new ArticleService($repo);
    $controller = new ArticleController($service);

    $items = $controller->list($filters, $limit, $offset);
    respond(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
