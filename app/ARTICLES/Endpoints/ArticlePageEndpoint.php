<?php
declare(strict_types=1);
namespace App\ARTICLES\Endpoints;
use App\ARTICLES\Services\PublicArticleService;
use App\ARTICLES\Services\ArticleHtmlService;
use App\SYSTEM\Services\AdminAuthService;
use PDO;
use Throwable;
final class ArticlePageEndpoint
{
    public static function handle(PDO $pdo, string $root): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        try {
            $id = (int)($_GET['id'] ?? 0);
            if (preg_match('~^/articles/([0-9]+)/?$~', (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), $match)) {
                $id = (int)$match[1];
            }
            $admin = AdminAuthService::fromPdo($pdo)->currentUser(true) !== null;
            $result = (new PublicArticleService($pdo))->get($id, $admin);
            $item = $result['item'] ?? null;
            $preview = ($_GET['admin'] ?? '') === '1' || ($item['article']['status'] ?? '') !== 'published';
            http_response_code($item ? 200 : 404);
            if (!$item || $preview) header('X-Robots-Tag: noindex, nofollow');
            $path = is_file($root . '/app-shell.html') ? $root . '/app-shell.html' : $root . '/dist/app-shell.html';
            if (!is_file($path)) throw new \RuntimeException('Build the application shell first.');
            echo ArticleHtmlService::render(file_get_contents($path), $item, $preview);
        } catch (Throwable $e) {
            http_response_code(503); header('X-Robots-Tag: noindex, nofollow');
            echo '<!doctype html><title>Article unavailable</title><meta name="robots" content="noindex,nofollow"><p>Article temporarily unavailable.</p>';
        }
    }
}
