<?php
declare(strict_types=1);

namespace App\ARTICLES\Endpoints;

use App\ARTICLES\Services\ArticleListingService;
use PDO;
use Throwable;

/** Public listing, intentionally separate from admin article search. */
final class ArticleListEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                http_response_code(405);
                echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
                return;
            }
            $limit = min(48, max(1, (int)($_GET['limit'] ?? 24)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $service = new ArticleListingService($pdo);
            $items = $service->list($limit, $offset);
            $response = ['ok' => true, 'items' => $items];
            if ($offset === 0) $response['featured'] = $service->featuredPreview();
            echo json_encode($response, JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Articles could not be loaded.']);
        }
    }
}
