<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\ArticleService;

class ArticleController
{
    public function __construct(private ArticleService $service) {}

    public function list(array $filters, int $limit, int $offset): array
    {
        return $this->service->listArticles($filters, $limit, $offset);
    }

    public function get(int $id): ?array
    {
        return $this->service->getArticle($id);
    }

    public function save(array $payload): int
    {
        return $this->service->saveArticle($payload);
    }

    public function listTags(): array
    {
        return $this->service->listTags();
    }

    public function delete(int $id): void
    {
        $this->service->deleteArticle($id);
    }
}
