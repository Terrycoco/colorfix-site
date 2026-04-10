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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    respond(['ok' => false, 'error' => 'id required'], 400);
}

try {
    $repo = new PdoArticleRepository($pdo);
    $service = new ArticleService($repo);
    $controller = new ArticleController($service);

    $item = $controller->get($id);
    if (!$item) {
        respond(['ok' => false, 'error' => 'Not found'], 404);
    }
    $sections = is_array($item['sections'] ?? null) ? $item['sections'] : [];
    $photoIds = [];
    foreach ($sections as $section) {
        if (!empty($section['asset_id'])) {
            $photoIds[] = (int)$section['asset_id'];
        }
    }
    $photoIds = array_values(array_unique(array_filter($photoIds)));
    $photoMap = [];
    if ($photoIds) {
        $placeholders = implode(',', array_fill(0, count($photoIds), '?'));
        $stmt = $pdo->prepare("SELECT photo_library_id, rel_path, title, alt_text, updated_at FROM photo_library WHERE photo_library_id IN ($placeholders)");
        $stmt->execute($photoIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $photoMap[(int)$row['photo_library_id']] = [
                'photo_library_id' => (int)$row['photo_library_id'],
                'rel_path' => $row['rel_path'],
                'title' => $row['title'],
                'alt_text' => $row['alt_text'],
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }
    }
    $item['sections'] = array_map(static function (array $section) use ($photoMap): array {
        $section['asset'] = null;
        if (!empty($section['asset_id'])) {
            $section['asset'] = $photoMap[(int)$section['asset_id']] ?? null;
        }
        return $section;
    }, $sections);
    respond(['ok' => true, 'item' => $item]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
