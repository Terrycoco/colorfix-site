<?php
declare(strict_types=1);

namespace App\Repos;

use App\Entities\Playlist;
use App\Entities\PlaylistItem;
use App\Entities\PlaylistStep;
use PDO;

class PdoPlaylistRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function getById(string $playlistId): ?Playlist
    {
        $meta = $this->getPlaylistMeta($playlistId);
        if ($meta === null) {
            return null;
        }

        $items = $this->getItemsFromDb($playlistId) ?? [];

        return new Playlist(
            $playlistId,
            $meta['type'],
            $meta['title'],
            [
                new PlaylistStep('all', false, $items),
            ],
            [
                'slug' => $meta['slug'] ?? null,
                'headline' => $meta['headline'] ?? null,
                'meta_description' => $meta['meta_description'] ?? null,
                'dek' => $meta['dek'] ?? null,
            ]
        );
    }

    public function getAdminRowById(int $playlistId): ?array
    {
        $sql = <<<SQL
            SELECT
                p.playlist_id,
                p.title,
                p.type,
                p.is_active,
                p.is_public,
                p.slug,
                p.headline,
                p.page_title,
                p.meta_description,
                p.dek,
                p.intro_html,
                p.body_html,
                p.hero_image_id,
                p.hero_image_url,
                p.hero_alt,
                p.indexable,
                p.published_at,
                p.updated_at,
                hero.rel_path AS hero_rel_path,
                hero.alt_text AS hero_photo_alt,
                hero.title AS hero_photo_title
            FROM playlists p
            LEFT JOIN photo_library hero
              ON hero.photo_library_id = p.hero_image_id
            WHERE p.playlist_id = :playlist_id
            LIMIT 1
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['playlist_id' => $playlistId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->hydrateSeoRow($row);
    }

    public function findSeoLandingBySlug(string $slug): ?array
    {
        $sql = <<<SQL
            SELECT
                p.playlist_id,
                p.title,
                p.type,
                p.is_active,
                p.is_public,
                p.slug,
                p.headline,
                p.page_title,
                p.meta_description,
                p.dek,
                p.intro_html,
                p.body_html,
                p.hero_image_id,
                p.hero_image_url,
                p.hero_alt,
                p.indexable,
                p.published_at,
                p.updated_at,
                hero.rel_path AS hero_rel_path,
                hero.alt_text AS hero_photo_alt,
                hero.title AS hero_photo_title,
                shareable.playlist_instance_id AS watch_playlist_instance_id
            FROM playlists p
            LEFT JOIN photo_library hero
              ON hero.photo_library_id = p.hero_image_id
            LEFT JOIN (
                SELECT playlist_id, MIN(playlist_instance_id) AS playlist_instance_id
                FROM playlist_instances
                WHERE is_active = 1
                  AND share_enabled = 1
                GROUP BY playlist_id
            ) shareable
              ON shareable.playlist_id = p.playlist_id
            WHERE p.slug = :slug
              AND p.is_active = 1
              AND p.is_public = 1
              AND p.indexable = 1
              AND p.headline IS NOT NULL
              AND TRIM(p.headline) <> ''
              AND p.meta_description IS NOT NULL
              AND TRIM(p.meta_description) <> ''
              AND p.dek IS NOT NULL
              AND TRIM(p.dek) <> ''
              AND p.intro_html IS NOT NULL
              AND TRIM(p.intro_html) <> ''
            LIMIT 1
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['slug' => $slug]);
        return $this->hydratePublicSeoResult($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    public function findWatchPlaylistInstanceIdBySlug(string $slug): ?int
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $sql = <<<SQL
            SELECT pi.playlist_instance_id
            FROM playlist_instances pi
            JOIN playlists p
              ON p.playlist_id = pi.playlist_id
            WHERE pi.slug = :slug
              AND pi.is_active = 1
              AND pi.share_enabled = 1
              AND p.is_active = 1
            ORDER BY pi.playlist_instance_id ASC
            LIMIT 1
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['slug' => $slug]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    public function findSeoLandingByPlaylistId(int $playlistId): ?array
    {
        $sql = <<<SQL
            SELECT
                p.playlist_id,
                p.title,
                p.type,
                p.is_active,
                p.is_public,
                p.slug,
                p.headline,
                p.page_title,
                p.meta_description,
                p.dek,
                p.intro_html,
                p.body_html,
                p.hero_image_id,
                p.hero_image_url,
                p.hero_alt,
                p.indexable,
                p.published_at,
                p.updated_at,
                hero.rel_path AS hero_rel_path,
                hero.alt_text AS hero_photo_alt,
                hero.title AS hero_photo_title,
                shareable.playlist_instance_id AS watch_playlist_instance_id
            FROM playlists p
            LEFT JOIN photo_library hero
              ON hero.photo_library_id = p.hero_image_id
            LEFT JOIN (
                SELECT playlist_id, MIN(playlist_instance_id) AS playlist_instance_id
                FROM playlist_instances
                WHERE is_active = 1
                  AND share_enabled = 1
                GROUP BY playlist_id
            ) shareable
              ON shareable.playlist_id = p.playlist_id
            WHERE p.playlist_id = :playlist_id
              AND p.is_active = 1
              AND p.is_public = 1
              AND p.indexable = 1
              AND p.slug IS NOT NULL
              AND TRIM(p.slug) <> ''
              AND p.headline IS NOT NULL
              AND TRIM(p.headline) <> ''
              AND p.meta_description IS NOT NULL
              AND TRIM(p.meta_description) <> ''
              AND p.dek IS NOT NULL
              AND TRIM(p.dek) <> ''
              AND p.intro_html IS NOT NULL
              AND TRIM(p.intro_html) <> ''
            LIMIT 1
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['playlist_id' => $playlistId]);
        return $this->hydratePublicSeoResult($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSeoLandingPages(): array
    {
        $sql = <<<SQL
            SELECT
                p.playlist_id,
                p.title,
                p.type,
                p.is_active,
                p.is_public,
                p.slug,
                p.headline,
                p.page_title,
                p.meta_description,
                p.dek,
                p.intro_html,
                p.body_html,
                p.hero_image_id,
                p.hero_image_url,
                p.hero_alt,
                p.indexable,
                p.published_at,
                p.updated_at,
                hero.rel_path AS hero_rel_path,
                hero.alt_text AS hero_photo_alt,
                hero.title AS hero_photo_title,
                shareable.playlist_instance_id AS watch_playlist_instance_id
            FROM playlists p
            LEFT JOIN photo_library hero
              ON hero.photo_library_id = p.hero_image_id
            INNER JOIN (
                SELECT playlist_id, MIN(playlist_instance_id) AS playlist_instance_id
                FROM playlist_instances
                WHERE is_active = 1
                  AND share_enabled = 1
                GROUP BY playlist_id
            ) shareable
              ON shareable.playlist_id = p.playlist_id
            WHERE p.is_active = 1
              AND p.is_public = 1
              AND p.indexable = 1
              AND p.slug IS NOT NULL
              AND p.slug <> ''
              AND p.headline IS NOT NULL
              AND TRIM(p.headline) <> ''
              AND p.meta_description IS NOT NULL
              AND TRIM(p.meta_description) <> ''
              AND p.dek IS NOT NULL
              AND TRIM(p.dek) <> ''
              AND p.intro_html IS NOT NULL
              AND TRIM(p.intro_html) <> ''
            ORDER BY
              COALESCE(p.published_at, p.updated_at) DESC,
              p.playlist_id DESC
            SQL;

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn(array $row): array => $this->hydrateSeoRow($row), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPublicPlayerPages(): array
    {
        $sql = <<<SQL
            SELECT
                pi.playlist_instance_id,
                pi.playlist_id,
                COALESCE(NULLIF(pi.display_title, ''), p.title) AS title,
                pi.slug,
                p.headline,
                p.meta_description,
                p.dek,
                p.updated_at,
                p.published_at
            FROM playlists p
            JOIN playlist_instances pi
              ON pi.playlist_id = p.playlist_id
            WHERE p.is_active = 1
              AND p.is_public = 1
              AND p.indexable = 1
              AND pi.is_active = 1
              AND pi.share_enabled = 1
              AND pi.slug IS NOT NULL
              AND TRIM(pi.slug) <> ''
            ORDER BY
                COALESCE(p.published_at, p.updated_at) DESC,
                pi.playlist_instance_id DESC
            SQL;

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function slugExists(string $slug, ?int $excludePlaylistId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM playlists WHERE slug = :slug';
        $params = ['slug' => $slug];
        if ($excludePlaylistId !== null && $excludePlaylistId > 0) {
            $sql .= ' AND playlist_id <> :exclude_playlist_id';
            $params['exclude_playlist_id'] = $excludePlaylistId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function generateUniqueSlug(string $baseSlug, ?int $excludePlaylistId = null): string
    {
        $slug = trim($baseSlug);
        if ($slug === '') {
            $slug = 'playlist';
        }

        $candidate = $slug;
        $suffix = 2;
        while ($this->slugExists($candidate, $excludePlaylistId)) {
            $candidate = sprintf('%s-%d', $slug, $suffix);
            $suffix++;
        }
        return $candidate;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private function hydratePublicSeoResult(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        if (empty($row['watch_playlist_instance_id'])) {
            return null;
        }

        return $this->hydrateSeoRow($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listItemRows(?int $playlistId = null): array
    {
        $photoSelect = $this->getPhotoLibraryIdSelect();
        $sql = <<<SQL
            SELECT
                playlist_item_id,
                playlist_id,
                image_url,
                {$photoSelect},
                title,
                item_type,
                is_active
            FROM playlist_items
            SQL;

        $params = [];
        $where = [];
        if ($playlistId !== null && $playlistId > 0) {
            $where[] = 'playlist_id = :playlist_id';
            $params['playlist_id'] = $playlistId;
        }
        if ($where) {
            $sql .= "\nWHERE " . implode(' AND ', $where);
        }
        $sql .= "\nORDER BY playlist_id ASC, order_index ASC, playlist_item_id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updateItemImageReference(int $playlistItemId, string $imageUrl, ?int $photoLibraryId): void
    {
        if ($playlistItemId <= 0) {
            return;
        }

        if ($this->hasPhotoLibraryIdColumn()) {
            $stmt = $this->pdo->prepare(
                "UPDATE playlist_items
                    SET image_url = :image_url,
                        photo_library_id = :photo_library_id
                  WHERE playlist_item_id = :playlist_item_id"
            );
            $stmt->execute([
                'image_url' => $imageUrl,
                'photo_library_id' => $photoLibraryId,
                'playlist_item_id' => $playlistItemId,
            ]);
            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE playlist_items
                SET image_url = :image_url
              WHERE playlist_item_id = :playlist_item_id"
        );
        $stmt->execute([
            'image_url' => $imageUrl,
            'playlist_item_id' => $playlistItemId,
        ]);
    }

    /**
     * @return PlaylistItem[]|null
     */
    private function getItemsFromDb(string $playlistId): ?array
    {
        $excludeSelect = $this->getExcludeFromThumbsSelect();
        $photoSelect = $this->getPhotoLibraryIdSelect();
        $savedPaletteSetSelect = $this->getSavedPaletteSetIdSelect();
        $shareImageSelect = $this->getIsShareImageSelect();
        $sql = <<<SQL
            SELECT
                playlist_item_id,
                playlist_id,
                order_index,
                ap_id,
                palette_hash,
                image_url,
                {$photoSelect},
                {$savedPaletteSetSelect},
                title,
                subtitle,
                body,
                item_type,
                layout,
                title_mode,
                star,
                transition,
                duration_ms,
                {$excludeSelect},
                {$shareImageSelect}
            FROM playlist_items
            WHERE playlist_id = :playlist_id
              AND is_active = 1
            ORDER BY order_index ASC
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['playlist_id' => (int)$playlistId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }

        $items = [];
        foreach ($rows as $row) {
            $star = null;
            if (array_key_exists('star', $row)) {
                $star = $row['star'] === null ? null : (bool)$row['star'];
            }

            $items[] = new PlaylistItem(
                (string)($row['ap_id'] ?? ''),
                $row['palette_hash'] ?? null,
                $row['image_url'] ?? null,
                isset($row['photo_library_id']) ? (int)$row['photo_library_id'] : null,
                isset($row['saved_palette_set_id']) ? (int)$row['saved_palette_set_id'] : null,
                null,
                $row['title'] ?? null,
                $row['subtitle'] ?? null,
                $row['body'] ?? null,
                $row['item_type'] ?? null,
                $star,
                $row['layout'] ?? null,
                $row['transition'] ?? null,
                $row['duration_ms'] !== null ? (int)$row['duration_ms'] : null,
                $row['title_mode'] ?? null,
                isset($row['exclude_from_thumbs']) ? (bool)$row['exclude_from_thumbs'] : null,
                isset($row['is_share_image']) ? (bool)$row['is_share_image'] : null
            );
        }

        return $items;
    }

    private function getExcludeFromThumbsSelect(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'playlist_items'
              AND COLUMN_NAME = 'exclude_from_thumbs'
            SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $hasColumn = (int)$stmt->fetchColumn() > 0;
        $cached = $hasColumn ? 'exclude_from_thumbs' : '0 AS exclude_from_thumbs';
        return $cached;
    }

    private function getPhotoLibraryIdSelect(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $hasColumn = $this->hasPhotoLibraryIdColumn();
        $cached = $hasColumn ? 'photo_library_id' : 'NULL AS photo_library_id';
        return $cached;
    }

    private function getIsShareImageSelect(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'playlist_items'
              AND COLUMN_NAME = 'is_share_image'
            SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $hasColumn = (int)$stmt->fetchColumn() > 0;
        $cached = $hasColumn ? 'is_share_image' : '0 AS is_share_image';
        return $cached;
    }

    private function getSavedPaletteSetIdSelect(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'playlist_items'
              AND COLUMN_NAME = 'saved_palette_set_id'
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $cached = (int)$stmt->fetchColumn() > 0 ? 'saved_palette_set_id' : 'NULL AS saved_palette_set_id';
        return $cached;
    }

    private function hasPhotoLibraryIdColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'playlist_items'
              AND COLUMN_NAME = 'photo_library_id'
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $cached = (int)$stmt->fetchColumn() > 0;
        return $cached;
    }

    private function getPlaylistMeta(string $playlistId): ?array
    {
        $sql = <<<SQL
            SELECT playlist_id, title, type, slug, headline, meta_description, dek
            FROM playlists
            WHERE playlist_id = :playlist_id
            LIMIT 1
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['playlist_id' => (int)$playlistId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'playlist_id' => (string)$row['playlist_id'],
            'title' => (string)$row['title'],
            'type' => (string)$row['type'],
            'slug' => $row['slug'] !== null ? (string)$row['slug'] : null,
            'headline' => $row['headline'] !== null ? (string)$row['headline'] : null,
            'meta_description' => $row['meta_description'] !== null ? (string)$row['meta_description'] : null,
            'dek' => $row['dek'] !== null ? (string)$row['dek'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateSeoRow(array $row): array
    {
        $heroUrl = trim((string)($row['hero_image_url'] ?? ''));
        if ($heroUrl === '') {
            $heroUrl = trim((string)($row['hero_rel_path'] ?? ''));
        }

        $heroAlt = trim((string)($row['hero_alt'] ?? ''));
        if ($heroAlt === '') {
            $heroAlt = trim((string)($row['hero_photo_alt'] ?? ''));
        }

        return [
            'playlist_id' => (int)($row['playlist_id'] ?? 0),
            'title' => (string)($row['title'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'is_active' => (int)($row['is_active'] ?? 0),
            'is_public' => (int)($row['is_public'] ?? 0),
            'slug' => $row['slug'] !== null ? (string)$row['slug'] : null,
            'headline' => $row['headline'] !== null ? (string)$row['headline'] : null,
            'page_title' => $row['page_title'] !== null ? (string)$row['page_title'] : null,
            'meta_description' => $row['meta_description'] !== null ? (string)$row['meta_description'] : null,
            'dek' => $row['dek'] !== null ? (string)$row['dek'] : null,
            'intro_html' => $row['intro_html'] !== null ? (string)$row['intro_html'] : null,
            'body_html' => $row['body_html'] !== null ? (string)$row['body_html'] : null,
            'hero_image_id' => isset($row['hero_image_id']) && $row['hero_image_id'] !== null ? (int)$row['hero_image_id'] : null,
            'hero_image_url' => $heroUrl !== '' ? $heroUrl : null,
            'hero_alt' => $heroAlt !== '' ? $heroAlt : null,
            'hero_photo_title' => $row['hero_photo_title'] !== null ? (string)$row['hero_photo_title'] : null,
            'indexable' => (int)($row['indexable'] ?? 1),
            'published_at' => $row['published_at'] !== null ? (string)$row['published_at'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
            'watch_playlist_instance_id' => isset($row['watch_playlist_instance_id']) && $row['watch_playlist_instance_id'] !== null
                ? (int)$row['watch_playlist_instance_id']
                : null,
        ];
    }
}
