<?php
declare(strict_types=1);
namespace App\ARTICLES\Endpoints;
use App\ARTICLES\Services\ArticleService;
use App\ARTICLES\Repos\PdoArticleRepository;
use PDO;
use Throwable;
/** Preserves authenticated editor search, including drafts and tag filters. */
final class AdminArticleListEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'OPTIONS') return;
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'GET only']); return; }
        try {
            $filters = [];
            foreach (['type','status','q'] as $key) {
                $value = trim((string)($_GET[$key] ?? ''));
                if ($value !== '') $filters[$key] = $value;
            }
            foreach (['tags'=>'tag_slugs', 'tag_ids'=>'tag_ids'] as $query => $key) {
                $value = trim((string)($_GET[$query] ?? ''));
                if ($value !== '') $filters[$key] = array_values(array_filter(array_map(
                    $key === 'tag_ids' ? 'intval' : 'trim', preg_split('/[|,]/', $value)
                )));
            }
            $service = new ArticleService(new PdoArticleRepository($pdo));
            $items = $service->listArticles($filters, (int)($_GET['limit'] ?? 100), (int)($_GET['offset'] ?? 0));
            echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Articles could not be loaded.']);
        }
    }
}
