<?php
declare(strict_types=1);
namespace App\PUB\Endpoints;
use App\PUB\Services\ArticlePublicationManager;
use PDO;
use Throwable;
final class ArticlePublicationEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json'); header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); return; }
        try {
            $body = json_decode(file_get_contents('php://input'), true);
            $item = (new ArticlePublicationManager($pdo))->release((int)($body['pub_asset_id'] ?? 0), (string)($body['action'] ?? ''));
            echo json_encode(['ok' => true, 'item' => $item]);
        } catch (Throwable $e) {
            http_response_code(400); echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
