<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoLandingPageRepository
{
    public function __construct(private PDO $pdo) {}

    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(lp.slug LIKE :q OR lp.title LIKE :q OR lp.search_title LIKE :q OR lp.description LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'lp.status = :status';
            $params[':status'] = $status;
        }

        $sql = $this->baseSelect();
        if ($where) {
            $sql .= "\nWHERE " . implode("\n  AND ", $where);
        }
        $sql .= "\nORDER BY lp.updated_at DESC, lp.id DESC\nLIMIT 200";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare($this->baseSelect() . "\nWHERE lp.id = :id\nLIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeRow($row) : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare($this->baseSelect() . "\nWHERE lp.slug = :slug\nLIMIT 1");
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeRow($row) : null;
    }

    public function save(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        if ($id > 0) {
            $this->update($id, $data);
            return $id;
        }
        return $this->create($data);
    }

    private function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO landing_pages
                (slug, title, search_title, description, status, page_type,
                 primary_playlist_instance_id, featured_pin_asset_id, redirect_url, created_at, updated_at)
             VALUES
                (:slug, :title, :search_title, :description, :status, :page_type,
                 :primary_playlist_instance_id, :featured_pin_asset_id, :redirect_url, NOW(), NOW())'
        );
        $stmt->execute($this->bindPayload($data));
        return (int)$this->pdo->lastInsertId();
    }

    private function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE landing_pages
                SET slug = :slug,
                    title = :title,
                    search_title = :search_title,
                    description = :description,
                    status = :status,
                    page_type = :page_type,
                    primary_playlist_instance_id = :primary_playlist_instance_id,
                    featured_pin_asset_id = :featured_pin_asset_id,
                    redirect_url = :redirect_url,
                    updated_at = NOW()
              WHERE id = :id'
        );
        $payload = $this->bindPayload($data);
        $payload[':id'] = $id;
        $stmt->execute($payload);
    }

    private function baseSelect(): string
    {
        return <<<SQL
            SELECT
              lp.id,
              lp.slug,
              lp.title,
              lp.search_title,
              lp.description,
              lp.status,
              lp.page_type,
              lp.primary_playlist_instance_id,
              lp.featured_pin_asset_id,
              lp.redirect_url,
              lp.created_at,
              lp.updated_at,
              pi.playlist_id AS primary_playlist_id,
              pi.slug AS primary_playlist_slug,
              pi.instance_name AS primary_playlist_instance_name,
              pi.display_title AS primary_playlist_display_title,
              p.title AS playlist_title,
              al.rel_path AS featured_pin_rel_path,
              al.title AS featured_pin_title
            FROM landing_pages lp
            LEFT JOIN playlist_instances pi
              ON pi.playlist_instance_id = lp.primary_playlist_instance_id
            LEFT JOIN playlists p
              ON p.playlist_id = pi.playlist_id
            LEFT JOIN asset_library al
              ON al.asset_library_id = lp.featured_pin_asset_id
            SQL;
    }

    private function bindPayload(array $data): array
    {
        return [
            ':slug' => (string)$data['slug'],
            ':title' => (string)$data['title'],
            ':search_title' => $this->nullableString($data['search_title'] ?? null),
            ':description' => $this->nullableString($data['description'] ?? null),
            ':status' => (string)$data['status'],
            ':page_type' => (string)$data['page_type'],
            ':primary_playlist_instance_id' => $this->nullableInt($data['primary_playlist_instance_id'] ?? null),
            ':featured_pin_asset_id' => $this->nullableInt($data['featured_pin_asset_id'] ?? null),
            ':redirect_url' => $this->nullableString($data['redirect_url'] ?? null),
        ];
    }

    private function normalizeRow(array $row): array
    {
        foreach (['id', 'primary_playlist_instance_id', 'featured_pin_asset_id', 'primary_playlist_id'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                $row[$key] = (int)$row[$key];
            }
        }
        return $row;
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string)($value ?? ''));
        return $string !== '' ? $string : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $int = (int)$value;
        return $int > 0 ? $int : null;
    }
}
