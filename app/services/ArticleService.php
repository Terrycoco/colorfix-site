<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoArticleRepository;
use InvalidArgumentException;

class ArticleService
{
    public function __construct(private PdoArticleRepository $repo) {}

    public function listArticles(array $filters, int $limit = 100, int $offset = 0): array
    {
        return $this->repo->listArticles($filters, $limit, $offset);
    }

    public function getArticle(int $id): ?array
    {
        $article = $this->repo->getArticleById($id);
        if (!$article) return null;
        $article['sections'] = $this->repo->listSections($id);
        $article['tags'] = $this->repo->getArticleTags($id);
        return $article;
    }

    public function saveArticle(array $payload): int
    {
        $title = trim((string)($payload['title'] ?? ''));
        $slug = trim((string)($payload['slug'] ?? ''));
        if ($title === '' || $slug === '') {
            throw new InvalidArgumentException('title and slug required');
        }

        $articleId = isset($payload['id']) ? (int)$payload['id'] : 0;
        $data = [
            'type' => trim((string)($payload['type'] ?? 'colorfix')) ?: 'colorfix',
            'status' => $payload['status'] ?? 'draft',
            'title' => $title,
            'dek' => $payload['dek'] ?? null,
            'slug' => $slug,
            'meta_description' => $payload['meta_description'] ?? null,
            'hero_asset_id' => $payload['hero_asset_id'] ?? null,
            'published_at' => $payload['published_at'] ?? null,
        ];

        if ($articleId > 0) {
            $this->repo->updateArticle($articleId, $data);
        } else {
            $articleId = $this->repo->createArticle($data);
        }

        $tagIds = $this->normalizeTagIds($payload['tags'] ?? []);
        if ($tagIds !== null) {
            $this->repo->setArticleTags($articleId, $tagIds);
        }

        if (array_key_exists('sections', $payload) && is_array($payload['sections'])) {
            $this->syncSections($articleId, $payload['sections']);
        }

        return $articleId;
    }

    public function listTags(): array
    {
        return $this->repo->listTags();
    }

    public function deleteArticle(int $id): void
    {
        $article = $this->repo->getArticleById($id);
        if (!$article) {
            throw new InvalidArgumentException('Article not found');
        }
        if (($article['status'] ?? '') === 'published') {
            throw new InvalidArgumentException('Cannot delete a published article');
        }
        $this->repo->deleteArticle($id);
    }

    private function normalizeTagIds($tags): ?array
    {
        if ($tags === null) return null;
        if (!is_array($tags)) return null;

        $tagIds = [];
        foreach ($tags as $tag) {
            if (is_numeric($tag)) {
                $tagIds[] = (int)$tag;
                continue;
            }
            if (is_array($tag)) {
                if (!empty($tag['id'])) {
                    $tagIds[] = (int)$tag['id'];
                    continue;
                }
                $slug = trim((string)($tag['slug'] ?? ''));
                $name = trim((string)($tag['name'] ?? ''));
                if ($slug !== '' && $name !== '') {
                    $tagIds[] = $this->repo->upsertTag($slug, $name);
                }
            }
        }
        $tagIds = array_values(array_unique(array_filter($tagIds)));
        return $tagIds;
    }

    private function syncSections(int $articleId, array $sections): void
    {
        $existing = $this->repo->listSections($articleId);
        $existingIds = array_map(static fn(array $row) => (int)$row['id'], $existing);
        $keepIds = [];

        foreach ($sections as $section) {
            if (!is_array($section)) continue;
            $sectionId = isset($section['id']) ? (int)$section['id'] : 0;
            if (!empty($section['delete']) && $sectionId > 0) {
                $this->repo->deleteSection($sectionId);
                continue;
            }
            $data = [
                'sort_order' => $section['sort_order'] ?? 0,
                'kind' => $section['kind'] ?? 'text',
                'heading' => $section['heading'] ?? null,
                'body' => $section['body'] ?? null,
                'asset_id' => $section['asset_id'] ?? null,
                'palette_id' => $section['palette_id'] ?? null,
            ];
            if ($sectionId > 0) {
                $this->repo->updateSection($sectionId, $data);
                $keepIds[] = $sectionId;
            } else {
                $newId = $this->repo->createSection($articleId, $data);
                $keepIds[] = $newId;
            }
        }

        $toDelete = array_diff($existingIds, $keepIds);
        foreach ($toDelete as $id) {
            $this->repo->deleteSection((int)$id);
        }
    }
}
