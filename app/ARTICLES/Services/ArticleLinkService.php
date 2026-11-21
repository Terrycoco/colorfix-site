<?php
declare(strict_types=1);
namespace App\ARTICLES\Services;
use App\ARTICLES\Repos\PdoArticleRepository;
use PDO;

/** Public discovery metadata for the existing article_link action. */
final class ArticleLinkService
{
    public function __construct(private PDO $pdo) {}
    public function hydrate(array $ctas): array
    {
        $repo = new PdoArticleRepository($this->pdo);
        foreach ($ctas as $idx => &$cta) {
            $action = $cta['action_key'] ?? $cta['action'] ?? $cta['key'] ?? '';
            if ($action !== 'article_link') continue;
            $raw = $cta['params'] ?? null;
            $params = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : []);
            $params = is_array($params) ? $params : [];
            $id = (int)($params['article_id'] ?? $params['articleId'] ?? 0);
            $url = trim((string)($params['url'] ?? ''));
            $host = parse_url($url, PHP_URL_HOST);
            if ((!$host || $host === parse_url(ArticleHtmlService::ORIGIN, PHP_URL_HOST))
                && preg_match('~^/articles/([0-9]+)/?$~', (string)parse_url($url, PHP_URL_PATH), $match)) {
                // An explicit local URL is the destination the visitor will actually open.
                $id = (int)$match[1];
            }
            if ($id <= 0) continue;
            $article = $repo->getPublishedArticle($id);
            if (!$article) { unset($ctas[$idx]); continue; }
            foreach (['title', 'dek'] as $key) {
                if (empty($params[$key]) && !empty($article[$key])) $params[$key] = $article[$key];
            }
            if ($url === '') $params['url'] = '/articles/' . $id;
            $cta['params'] = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        unset($cta);
        return array_values($ctas);
    }
}
