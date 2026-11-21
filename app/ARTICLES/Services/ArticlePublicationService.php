<?php
declare(strict_types=1);
namespace App\ARTICLES\Services;

use App\ARTICLES\Repos\PdoArticleRepository;
use PDO;
use InvalidArgumentException;
use Throwable;

/** Authenticated adapters authorize release; ARTICLES alone owns its state. */
final class ArticlePublicationService
{
    public function __construct(private PDO $pdo) {}
    public function publish(int $id): array { return $this->transition($id, true); }
    public function unpublish(int $id): array { return $this->transition($id, false); }

    private function transition(int $id, bool $published): array
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) $this->pdo->beginTransaction();
        try {
            $repo = new PdoArticleRepository($this->pdo);
            $article = $repo->lockArticle($id);
            if (!$article) throw new InvalidArgumentException('Article not found.');
            if ($published && (trim($article['title'] ?? '') === '' || trim($article['slug'] ?? '') === '')) {
                throw new InvalidArgumentException('Save a title and slug before publishing.');
            }
            if (($article['status'] === 'published') !== $published || ($published && !$article['published_at'])) {
                $repo->setPublication($id, $published);
            }
            $article = $repo->getArticleById($id);
            if ($owns) $this->pdo->commit();
            return ['article_id' => $id, 'status' => $article['status'],
                'published_at' => $article['published_at'], 'url' => '/articles/' . $id];
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
