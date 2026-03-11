<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

class PdoArticleRepository
{
    public function __construct(private PDO $pdo) {}

    public function createArticle(array $data): int
    {
        $sql = "INSERT INTO articles
                (type, status, title, dek, slug, meta_description, hero_asset_id, cta_overrides, featured, published_at, created_at, updated_at)
                VALUES
                (:type, :status, :title, :dek, :slug, :meta_description, :hero_asset_id, :cta_overrides, :featured, :published_at, NOW(), NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':type' => $data['type'],
            ':status' => $data['status'] ?? 'draft',
            ':title' => $data['title'],
            ':dek' => $data['dek'] ?? null,
            ':slug' => $data['slug'],
            ':meta_description' => $data['meta_description'] ?? null,
            ':hero_asset_id' => $data['hero_asset_id'] ?? null,
            ':cta_overrides' => $data['cta_overrides'] ?? null,
            ':featured' => !empty($data['featured']) ? 1 : 0,
            ':published_at' => $data['published_at'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateArticle(int $id, array $fields): void
    {
        if (empty($fields)) return;
        $allowed = [
            'type',
            'status',
            'title',
            'dek',
            'slug',
            'meta_description',
            'hero_asset_id',
            'cta_overrides',
            'featured',
            'published_at',
        ];
        $setParts = [];
        $params = [':id' => $id];
        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) continue;
            $paramKey = ':' . $column;
            $setParts[] = "{$column} = {$paramKey}";
            $params[$paramKey] = $value;
        }
        if (!$setParts) return;
        $sql = "UPDATE articles SET " . implode(', ', $setParts) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function deleteArticle(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM articles WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    public function getArticleById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM articles WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getArticleBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM articles WHERE slug = :slug LIMIT 1");
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listArticles(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = [];
        $joins = [];
        $params = [];

        if (!empty($filters['type'])) {
            $where[] = "a.type = :type";
            $params[':type'] = $filters['type'];
        }
        if (!empty($filters['status'])) {
            $where[] = "a.status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['q'])) {
            $where[] = "(a.title LIKE :q OR a.slug LIKE :q)";
            $params[':q'] = '%' . $filters['q'] . '%';
        }

        $tagIds = $filters['tag_ids'] ?? [];
        if (!is_array($tagIds)) $tagIds = [];
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds))));

        $tagSlugs = $filters['tag_slugs'] ?? [];
        if (!is_array($tagSlugs)) $tagSlugs = [];
        $tagSlugs = array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $tagSlugs)))));

        if ($tagIds || $tagSlugs) {
            $joins[] = "JOIN article_tag_map atm ON atm.article_id = a.id";
            if ($tagSlugs) {
                $joins[] = "JOIN article_tags t ON t.id = atm.tag_id";
            }

            $tagWheres = [];
            foreach ($tagIds as $idx => $tagId) {
                $paramKey = ':tag_id_' . $idx;
                $tagWheres[] = "atm.tag_id = {$paramKey}";
                $params[$paramKey] = $tagId;
            }
            foreach ($tagSlugs as $idx => $slug) {
                $paramKey = ':tag_slug_' . $idx;
                $tagWheres[] = "t.slug = {$paramKey}";
                $params[$paramKey] = $slug;
            }
            if ($tagWheres) {
                $where[] = '(' . implode(' OR ', $tagWheres) . ')';
            }
        }

        $sql = "SELECT DISTINCT a.* FROM articles a";
        if ($joins) $sql .= " " . implode(' ', $joins);
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY a.published_at DESC, a.created_at DESC";
        $sql .= " LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function clearFeaturedExcept(int $articleId): void
    {
        $stmt = $this->pdo->prepare("UPDATE articles SET featured = 0 WHERE id <> :id");
        $stmt->execute([':id' => $articleId]);
    }

    public function getFeaturedOrLatest(?string $type = null): ?array
    {
        $params = [];
        $typeWhere = '';
        if ($type !== null && $type !== '') {
            $typeWhere = ' AND type = :type';
            $params[':type'] = $type;
        }

        $sqlFeatured = "SELECT * FROM articles
                        WHERE status = 'published'
                          AND featured = 1{$typeWhere}
                        ORDER BY published_at DESC, id DESC
                        LIMIT 1";
        $stmt = $this->pdo->prepare($sqlFeatured);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;

        $sqlLatest = "SELECT * FROM articles
                      WHERE status = 'published'{$typeWhere}
                      ORDER BY published_at DESC, id DESC
                      LIMIT 1";
        $stmt = $this->pdo->prepare($sqlLatest);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listSections(int $articleId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM article_sections WHERE article_id = :id ORDER BY sort_order ASC, id ASC");
        $stmt->execute([':id' => $articleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function bumpSectionSortOrders(int $articleId, int $offset): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE article_sections
               SET sort_order = sort_order + :offset
             WHERE article_id = :id
        ");
        $stmt->execute([
            ':offset' => $offset,
            ':id' => $articleId,
        ]);
    }

    public function createSection(int $articleId, array $data): int
    {
        $sql = "INSERT INTO article_sections
                (article_id, sort_order, kind, heading, heading_level, body, caption, asset_id, palette_id, created_at, updated_at)
                VALUES
                (:article_id, :sort_order, :kind, :heading, :heading_level, :body, :caption, :asset_id, :palette_id, NOW(), NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':article_id' => $articleId,
            ':sort_order' => $data['sort_order'] ?? 0,
            ':kind' => $data['kind'] ?? 'text',
            ':heading' => $data['heading'] ?? null,
            ':heading_level' => $data['heading_level'] ?? null,
            ':body' => $data['body'] ?? null,
            ':caption' => $data['caption'] ?? null,
            ':asset_id' => $data['asset_id'] ?? null,
            ':palette_id' => $data['palette_id'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateSection(int $sectionId, array $fields): void
    {
        if (empty($fields)) return;
        $allowed = ['sort_order', 'kind', 'heading', 'heading_level', 'body', 'caption', 'asset_id', 'palette_id'];
        $setParts = [];
        $params = [':id' => $sectionId];
        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) continue;
            $paramKey = ':' . $column;
            $setParts[] = "{$column} = {$paramKey}";
            $params[$paramKey] = $value;
        }
        if (!$setParts) return;
        $sql = "UPDATE article_sections SET " . implode(', ', $setParts) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function deleteSection(int $sectionId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM article_sections WHERE id = :id");
        $stmt->execute([':id' => $sectionId]);
    }

    public function listTags(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM article_tags ORDER BY name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function upsertTag(string $slug, string $name): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO article_tags (slug, name, created_at)
             VALUES (:slug, :name, NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name)"
        );
        $stmt->execute([':slug' => $slug, ':name' => $name]);
        return (int)$this->pdo->lastInsertId();
    }

    public function setArticleTags(int $articleId, array $tagIds): void
    {
        $this->pdo->prepare("DELETE FROM article_tag_map WHERE article_id = :id")
            ->execute([':id' => $articleId]);
        if (!$tagIds) return;
        $stmt = $this->pdo->prepare("INSERT INTO article_tag_map (article_id, tag_id, created_at) VALUES (:article_id, :tag_id, NOW())");
        foreach ($tagIds as $tagId) {
            $stmt->execute([
                ':article_id' => $articleId,
                ':tag_id' => (int)$tagId,
            ]);
        }
    }

    public function getArticleTags(int $articleId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT t.id, t.slug, t.name
            FROM article_tag_map atm
            JOIN article_tags t ON t.id = atm.tag_id
            WHERE atm.article_id = :id
            ORDER BY t.name ASC
        ");
        $stmt->execute([':id' => $articleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
