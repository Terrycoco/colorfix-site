<?php
declare(strict_types=1);
namespace App\ARTICLES\Endpoints;
use App\ARTICLES\Services\ArticlePublicationService;
use PDO;
use InvalidArgumentException;
use Throwable;
final class ArticlePublicationEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); return; }
            $body = json_decode(file_get_contents('php://input'), true);
            $action = $body['action'] ?? '';
            if (!in_array($action, ['publish', 'unpublish'], true)) throw new InvalidArgumentException('Invalid publication action.');
            $result = (new ArticlePublicationService($pdo))->$action((int)($body['article_id'] ?? 0));
            echo json_encode(['ok' => true, 'item' => $result]);
        } catch (InvalidArgumentException $e) {
            http_response_code(400); echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Publication failed.']);
        }
    }
}
