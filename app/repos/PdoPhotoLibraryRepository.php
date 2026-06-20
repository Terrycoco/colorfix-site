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
            "SELECT photo_library_id, asset_library_id, source_type, source_id, client_id, photo_permission_status, rel_path, title, tags, alt_text, show_in_gallery, has_palette, is_inactive
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

    public function findIdByRelPath(string $relPath): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT photo_library_id
               FROM photo_library
              WHERE rel_path = :rel_path
              ORDER BY updated_at DESC, created_at DESC, photo_library_id DESC
              LIMIT 1"
        );
        $stmt->execute([
            ':rel_path' => $relPath,
        ]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    public function findCanonicalIdByRelPath(string $relPath): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT photo_library_id
               FROM photo_library
              WHERE rel_path = :rel_path
           ORDER BY CASE WHEN source_type IN ('saved_palette_photo', 'saved_before') THEN 1 ELSE 0 END ASC,
                    updated_at DESC,
                    created_at DESC,
                    photo_library_id DESC
              LIMIT 1"
        );
        $stmt->execute([
            ':rel_path' => $relPath,
        ]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    public function findIdBySourceTypeAndRelPath(string $sourceType, string $relPath): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT photo_library_id
               FROM photo_library
              WHERE source_type = :source_type
                AND rel_path = :rel_path
              ORDER BY updated_at DESC, created_at DESC, photo_library_id DESC
              LIMIT 1"
        );
        $stmt->execute([
            ':source_type' => $sourceType,
            ':rel_path' => $relPath,
        ]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    public function findLatestIdBySourceTypeAndRelPrefix(string $sourceType, string $relPrefix): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT photo_library_id
               FROM photo_library
              WHERE source_type = :source_type
                AND rel_path LIKE :rel_prefix
              ORDER BY updated_at DESC, created_at DESC, photo_library_id DESC
              LIMIT 1"
        );
        $stmt->execute([
            ':source_type' => $sourceType,
            ':rel_prefix' => $relPrefix . '%',
        ]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    public function insert(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO photo_library
                (source_type, source_id, client_id, photo_permission_status, rel_path, title, tags, alt_text, note, show_in_gallery, has_palette, created_at)
             VALUES
                (:source_type, :source_id, :client_id, :photo_permission_status, :rel_path, :title, :tags, :alt_text, :note, :show_in_gallery, :has_palette, NOW())"
        );
        $stmt->execute([
            ':source_type' => $data['source_type'],
            ':source_id' => $data['source_id'] ?? null,
            ':client_id' => $data['client_id'] ?? null,
            ':photo_permission_status' => $this->normalizePermissionStatus($data['photo_permission_status'] ?? null),
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
        $allowed = ['source_type', 'asset_library_id', 'rel_path', 'title', 'tags', 'alt_text', 'note', 'show_in_gallery', 'has_palette', 'is_inactive', 'client_id', 'photo_permission_status'];
        $setParts = [];
        $params = [':id' => $id];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $paramKey = ':' . $key;
            if (in_array($key, ['show_in_gallery', 'has_palette', 'is_inactive'], true)) {
                $params[$paramKey] = !empty($data[$key]) ? 1 : 0;
            } elseif ($key === 'asset_library_id') {
                $params[$paramKey] = !empty($data[$key]) ? (int)$data[$key] : null;
            } elseif ($key === 'photo_permission_status') {
                $params[$paramKey] = $this->normalizePermissionStatus($data[$key] ?? null);
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

    public function updatePermissionStatus(int $id, ?string $status): array
    {
        $this->update($id, ['photo_permission_status' => $this->normalizePermissionStatus($status)]);
        return $this->getPermissionStatus($id) ?? [
            'photo_library_id' => $id,
            'photo_permission_status' => 'unknown',
            'photo_permission_override_status' => null,
        ];
    }

    public function getPermissionStatus(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                pl.photo_library_id,
                pl.photo_permission_status AS photo_permission_override_status,
                c.id AS client_id,
                c.name AS client_name,
                c.email AS client_email,
                c.photo_permission_status AS client_photo_permission_status,
                COALESCE(NULLIF(pl.photo_permission_status, ''), c.photo_permission_status, 'unknown') AS photo_permission_status
               FROM photo_library pl
          LEFT JOIN clients c
                 ON c.id = pl.client_id
              WHERE pl.photo_library_id = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        foreach (['photo_library_id', 'client_id'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int)$row[$key];
            }
        }
        return $row;
    }

    private function normalizePermissionStatus(?string $status): ?string
    {
        $value = strtolower(trim((string)$status));
        if ($value === '' || $value === 'client' || $value === 'default') {
            return null;
        }
        return in_array($value, ['not_needed', 'unknown', 'requested', 'granted', 'declined'], true)
            ? $value
            : null;
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
                'playlist_instance_intro' AS usage_type,
                pi.playlist_instance_id AS ref_id,
                pi.instance_name AS ref_title,
                CONCAT('Playlist Instance #', pi.playlist_instance_id, ': ', pi.instance_name) AS label,
                'Intro image' AS detail,
                '/admin/playlist-instances' AS admin_path
            FROM playlist_instances pi
            WHERE pi.intro_image_url = CONCAT('photo:', :photo_library_id_instance_intro)
               OR pi.intro_image_url LIKE CONCAT('photo:', :photo_library_id_instance_intro_prefix, '|%')

            UNION ALL

            SELECT
                'playlist_instance_share' AS usage_type,
                pi.playlist_instance_id AS ref_id,
                pi.instance_name AS ref_title,
                CONCAT('Playlist Instance #', pi.playlist_instance_id, ': ', pi.instance_name) AS label,
                'Share image' AS detail,
                '/admin/playlist-instances' AS admin_path
            FROM playlist_instances pi
            WHERE pi.share_image_url = CONCAT('photo:', :photo_library_id_instance_share)
               OR pi.share_image_url LIKE CONCAT('photo:', :photo_library_id_instance_share_prefix, '|%')

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
                'article_hero_mobile' AS usage_type,
                a.id AS ref_id,
                a.title AS ref_title,
                CONCAT('Article #', a.id, ': ', a.title) AS label,
                'Mobile hero image' AS detail,
                '/admin/articles' AS admin_path
            FROM articles a
            WHERE a.hero_mobile_asset_id = :photo_library_id_article_hero_mobile

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
                'saved_palette_set_photo' AS usage_type,
                s.saved_palette_id AS ref_id,
                p.nickname AS ref_title,
                CONCAT('Saved Palette #', s.saved_palette_id, ': ', COALESCE(NULLIF(p.nickname, ''), p.palette_hash)) AS label,
                CONCAT('Set ', COALESCE(NULLIF(s.title, ''), s.slug), ' / ', sp.photo_type) AS detail,
                '/admin/saved-palettes' AS admin_path
            FROM saved_palette_set_photos sp
            JOIN saved_palette_sets s
              ON s.id = sp.saved_palette_set_id
            JOIN saved_palettes p
              ON p.id = s.saved_palette_id
            WHERE sp.photo_library_id = :photo_library_id_saved_set_photo

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
            ':photo_library_id_instance_intro' => $photoLibraryId,
            ':photo_library_id_instance_intro_prefix' => $photoLibraryId,
            ':photo_library_id_instance_share' => $photoLibraryId,
            ':photo_library_id_instance_share_prefix' => $photoLibraryId,
            ':photo_library_id_article_hero' => $photoLibraryId,
            ':photo_library_id_article_hero_mobile' => $photoLibraryId,
            ':photo_library_id_article_section' => $photoLibraryId,
            ':photo_library_id_saved_set_photo' => $photoLibraryId,
            ':photo_library_id_group' => $photoLibraryId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getUsageSummaryForPhoto(int $photoLibraryId): array
    {
        return $this->listUsages($photoLibraryId);
    }

    public function listSavedPaletteSourceRows(): array
    {
        $stmt = $this->pdo->query(
            "SELECT photo_library_id, source_type, rel_path
               FROM photo_library
              WHERE source_type IN ('saved_palette_photo', 'saved_before')
                AND rel_path IS NOT NULL
                AND rel_path <> ''
           ORDER BY rel_path ASC, photo_library_id ASC"
        );
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function listAllRowsWithRelPath(): array
    {
        $stmt = $this->pdo->query(
            "SELECT photo_library_id, source_type, rel_path
               FROM photo_library
              WHERE rel_path IS NOT NULL
                AND rel_path <> ''
           ORDER BY rel_path ASC, photo_library_id ASC"
        );
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function listInactiveRows(): array
    {
        $stmt = $this->pdo->query(
            "SELECT photo_library_id, source_type, rel_path, title, is_inactive
               FROM photo_library
              WHERE is_inactive = 1
           ORDER BY updated_at DESC, created_at DESC, photo_library_id DESC"
        );
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function reassignAllUsages(int $fromId, int $toId): void
    {
        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            return;
        }

        $updates = [
            "UPDATE playlist_items SET photo_library_id = :to_id WHERE photo_library_id = :from_id",
            "UPDATE playlist_instance_set_items SET photo_library_id = :to_id WHERE photo_library_id = :from_id",
            "UPDATE articles SET hero_asset_id = :to_id WHERE hero_asset_id = :from_id",
            "UPDATE articles SET hero_mobile_asset_id = :to_id WHERE hero_mobile_asset_id = :from_id",
            "UPDATE article_sections SET asset_id = :to_id WHERE asset_id = :from_id",
            "UPDATE saved_palette_set_photos SET photo_library_id = :to_id WHERE photo_library_id = :from_id",
            "UPDATE photo_group_items SET photo_library_id = :to_id WHERE photo_library_id = :from_id",
        ];

        foreach ($updates as $sql) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':from_id' => $fromId,
                ':to_id' => $toId,
            ]);
        }
    }

    public function deleteById(int $photoLibraryId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM photo_library WHERE photo_library_id = :id");
        $stmt->execute([':id' => $photoLibraryId]);
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
