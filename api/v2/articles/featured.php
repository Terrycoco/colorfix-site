<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Repos\PdoArticleRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';

try {
    $repo = new PdoArticleRepository($pdo);
    $article = $repo->getFeaturedOrLatest($type !== '' ? $type : null);
    if (!$article) {
        respond(['ok' => false, 'error' => 'No published articles'], 404);
    }

    $hero = null;
    if (!empty($article['hero_asset_id'])) {
        $stmt = $pdo->prepare("SELECT photo_library_id, rel_path, title, alt_text, updated_at FROM photo_library WHERE photo_library_id = :id LIMIT 1");
        $stmt->execute([':id' => (int)$article['hero_asset_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $hero = [
                'photo_library_id' => (int)$row['photo_library_id'],
                'rel_path' => $row['rel_path'],
                'title' => $row['title'],
                'alt_text' => $row['alt_text'],
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }
    }

    respond(['ok' => true, 'item' => ['article' => $article, 'hero' => $hero]]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
