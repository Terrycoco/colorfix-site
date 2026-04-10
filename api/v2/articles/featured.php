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

    $photoIds = array_values(array_unique(array_filter([
        !empty($article['hero_asset_id']) ? (int)$article['hero_asset_id'] : 0,
        !empty($article['hero_mobile_asset_id']) ? (int)$article['hero_mobile_asset_id'] : 0,
    ])));
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

    $hero = !empty($article['hero_asset_id']) ? ($photoMap[(int)$article['hero_asset_id']] ?? null) : null;
    $heroMobile = !empty($article['hero_mobile_asset_id']) ? ($photoMap[(int)$article['hero_mobile_asset_id']] ?? null) : null;

    respond(['ok' => true, 'item' => ['article' => $article, 'hero' => $hero, 'hero_mobile' => $heroMobile]]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
