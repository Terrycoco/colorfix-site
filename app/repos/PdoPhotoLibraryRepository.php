<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

class PdoPhotoLibraryRepository
{
    public function __construct(private PDO $pdo) {}

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT photo_library_id, source_type, source_id, client_id, rel_path, title
               FROM photo_library
              WHERE photo_library_id = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findIdBySource(string $sourceType, ?int $sourceId): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT photo_library_id
               FROM photo_library
              WHERE source_type = :source_type
                AND source_id <=> :source_id
              LIMIT 1"
        );
        $stmt->execute([
            ':source_type' => $sourceType,
            ':source_id' => $sourceId,
        ]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    public function findIdBySourceAndRel(string $sourceType, ?int $sourceId, string $relPath): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT photo_library_id FROM photo_library WHERE source_type = :source_type AND source_id <=> :source_id AND rel_path = :rel_path LIMIT 1"
        );
        $stmt->execute([
            ':source_type' => $sourceType,
            ':source_id' => $sourceId,
            ':rel_path' => $relPath,
        ]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    public function findIdBySourceAndTitle(string $sourceType, ?int $sourceId, string $title): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT photo_library_id FROM photo_library WHERE source_type = :source_type AND source_id <=> :source_id AND title = :title LIMIT 1"
        );
        $stmt->execute([
            ':source_type' => $sourceType,
            ':source_id' => $sourceId,
            ':title' => $title,
        ]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    public function insert(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO photo_library
                (source_type, source_id, client_id, rel_path, title, tags, alt_text, note, show_in_gallery, has_palette, created_at)
             VALUES
                (:source_type, :source_id, :client_id, :rel_path, :title, :tags, :alt_text, :note, :show_in_gallery, :has_palette, NOW())"
        );
        $stmt->execute([
            ':source_type' => $data['source_type'],
            ':source_id' => $data['source_id'] ?? null,
            ':client_id' => $data['client_id'] ?? null,
            ':rel_path' => $data['rel_path'],
            ':title' => $data['title'] ?? null,
            ':tags' => $data['tags'] ?? null,
            ':alt_text' => $data['alt_text'] ?? null,
            ':note' => $data['note'] ?? null,
            ':show_in_gallery' => !empty($data['show_in_gallery']) ? 1 : 0,
            ':has_palette' => !empty($data['has_palette']) ? 1 : 0,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $allowed = ['source_type', 'rel_path', 'title', 'tags', 'alt_text', 'note', 'show_in_gallery', 'has_palette', 'client_id'];
        $setParts = [];
        $params = [':id' => $id];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $paramKey = ':' . $key;
            if (in_array($key, ['show_in_gallery', 'has_palette'], true)) {
                $params[$paramKey] = !empty($data[$key]) ? 1 : 0;
            } else {
                $params[$paramKey] = $data[$key];
            }
            $setParts[] = "{$key} = {$paramKey}";
        }
        if (!$setParts) {
            return;
        }
        $sql = "UPDATE photo_library SET " . implode(', ', $setParts) . ", updated_at = NOW() WHERE photo_library_id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function listUsages(int $photoLibraryId): array
    {
        $sql = <<<SQL
            SELECT
                'playlist_item' AS usage_type,
                p.playlist_id AS ref_id,
                p.title AS ref_title,
                CONCAT('Playlist #', p.playlist_id, ': ', p.title) AS label,
                CONCAT('Item #', pi.playlist_item_id) AS detail,
                '/admin/playlists' AS admin_path
            FROM playlist_items pi
            JOIN playlists p
              ON p.playlist_id = pi.playlist_id
            WHERE pi.photo_library_id = :photo_library_id_playlist

            UNION ALL

            SELECT
                'playlist_set_item' AS usage_type,
                ps.id AS ref_id,
                ps.title AS ref_title,
                CONCAT('Playlist Set #', ps.id, ': ', ps.title) AS label,
                CONCAT('Set item #', psi.id) AS detail,
                '/admin/playlist-instance-sets' AS admin_path
            FROM playlist_instance_set_items psi
            JOIN playlist_instance_sets ps
              ON ps.id = psi.playlist_instance_set_id
            WHERE psi.photo_library_id = :photo_library_id_set

            UNION ALL

            SELECT
                'article_hero' AS usage_type,
                a.id AS ref_id,
                a.title AS ref_title,
                CONCAT('Article #', a.id, ': ', a.title) AS label,
                'Hero image' AS detail,
                '/admin/articles' AS admin_path
            FROM articles a
            WHERE a.hero_asset_id = :photo_library_id_article_hero

            UNION ALL

            SELECT
                'article_section' AS usage_type,
                a.id AS ref_id,
                a.title AS ref_title,
                CONCAT('Article #', a.id, ': ', a.title) AS label,
                CONCAT('Section #', s.id) AS detail,
                '/admin/articles' AS admin_path
            FROM article_sections s
            JOIN articles a
              ON a.id = s.article_id
            WHERE s.asset_id = :photo_library_id_article_section

            UNION ALL

            SELECT
                'photo_group' AS usage_type,
                pg.group_id AS ref_id,
                pg.title AS ref_title,
                CONCAT('Photo Group #', pg.group_id, ': ', pg.title) AS label,
                'Grouped in Photo Library' AS detail,
                '/admin/photo-library' AS admin_path
            FROM photo_group_items pgi
            JOIN photo_groups pg
              ON pg.group_id = pgi.group_id
            WHERE pgi.photo_library_id = :photo_library_id_group
            ORDER BY usage_type ASC, ref_title ASC, ref_id ASC
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':photo_library_id_playlist' => $photoLibraryId,
            ':photo_library_id_set' => $photoLibraryId,
            ':photo_library_id_article_hero' => $photoLibraryId,
            ':photo_library_id_article_section' => $photoLibraryId,
            ':photo_library_id_group' => $photoLibraryId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function deleteBySource(string $sourceType, ?int $sourceId): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM photo_library WHERE source_type = :source_type AND source_id <=> :source_id"
        );
        $stmt->execute([
            ':source_type' => $sourceType,
            ':source_id' => $sourceId,
        ]);
    }

    public function deleteBySourceAndTitle(string $sourceType, ?int $sourceId, string $title): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM photo_library WHERE source_type = :source_type AND source_id <=> :source_id AND title = :title"
        );
        $stmt->execute([
            ':source_type' => $sourceType,
            ':source_id' => $sourceId,
            ':title' => $title,
        ]);
    }
}
