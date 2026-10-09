<?php
declare(strict_types=1);

namespace App\PHOTOS\Repos;

use PDO;

class PdoPhotoLibraryRepository
{
    private ?bool $paletteColumnAvailable = null;
    public function __construct(private PDO $pdo) {}

    public function paletteColumnAvailable(): bool
    {
        if ($this->paletteColumnAvailable !== null) { return $this->paletteColumnAvailable; }
        try {
            $this->pdo->query('SELECT palette_id FROM photo_library WHERE 1 = 0');
            return $this->paletteColumnAvailable = true;
        } catch (\PDOException $e) {
            if (in_array($e->getCode(), ['42S22', 'HY000'], true)) { return $this->paletteColumnAvailable = false; }
            throw $e;
        }
    }

    public function findById(int $id): ?array
    {
        $paletteColumn = $this->paletteColumnAvailable() ? ', palette_id' : '';
        $stmt = $this->pdo->prepare(
            "SELECT photo_library_id, asset_library_id, source_type, source_id, client_id, photo_permission_status, rel_path, title, tags, alt_text, show_in_gallery, has_palette, is_inactive{$paletteColumn}
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
        $paletteColumns = $this->paletteColumnAvailable() ? ', palette_id' : '';
        $paletteValues = $this->paletteColumnAvailable() ? ', :palette_id' : '';
        $stmt = $this->pdo->prepare(
            "INSERT INTO photo_library
                (source_type, source_id, client_id, photo_permission_status, rel_path, title, tags, alt_text, note, show_in_gallery, has_palette, created_at{$paletteColumns})
             VALUES
                (:source_type, :source_id, :client_id, :photo_permission_status, :rel_path, :title, :tags, :alt_text, :note, :show_in_gallery, :has_palette, NOW(){$paletteValues})"
        );
        $params = [
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
        ];
        if ($this->paletteColumnAvailable()) { $params[':palette_id'] = !empty($data['palette_id']) ? (int)$data['palette_id'] : null; }
        $stmt->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        if (!array_key_exists('palette_id', $data)) { $this->updateFields($id, $data); return; }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            $lock = $this->pdo->prepare('SELECT palette_id FROM photo_library WHERE photo_library_id = ?'
                . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $lock->execute([$id]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$current) { throw new \InvalidArgumentException('Photo not found.'); }
            if (array_key_exists('expected_palette_id', $data)
                && (int)($data['expected_palette_id'] ?? 0) !== (int)($current['palette_id'] ?? 0)) {
                throw new \RuntimeException('The photo palette changed elsewhere. Reload before saving.');
            }
            $this->updateFields($id, $data);
            if ($ownsTransaction) { $this->pdo->commit(); }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    private function updateFields(int $id, array $data): void
    {
        $allowed = ['source_type', 'asset_library_id', 'rel_path', 'title', 'tags', 'alt_text', 'note', 'show_in_gallery', 'has_palette', 'is_inactive', 'client_id', 'photo_permission_status'];
        if (array_key_exists('palette_id', $data)) {
            if (!$this->paletteColumnAvailable()) { throw new \RuntimeException('Photo Library palette migration has not been installed.'); }
            $paletteId = (int)($data['palette_id'] ?? 0);
            if ($paletteId < 0) { throw new \InvalidArgumentException('Invalid palette ID.'); }
            if ($paletteId > 0) {
                $palette = $this->pdo->prepare('SELECT id FROM saved_palettes WHERE id = ?');
                $palette->execute([$paletteId]);
                if (!$palette->fetchColumn()) { throw new \InvalidArgumentException('Palette not found.'); }
                $before = $this->pdo->prepare('SELECT 1 FROM project_photos WHERE photo_library_id = ? AND `before` = 1 LIMIT 1');
                $before->execute([$id]);
                if ($before->fetchColumn()) { throw new \InvalidArgumentException('Before photos cannot have a palette.'); }
            }
            $data['palette_id'] = $paletteId ?: null;
            $data['has_palette'] = $paletteId > 0;
            $allowed[] = 'palette_id';
        }
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
        $photo = $this->findById($photoLibraryId);
        if (!$photo) { return []; }
        $path = (string)(parse_url((string)$photo['rel_path'], PHP_URL_PATH) ?: '');
        $params = [];
        // Legacy and current tables use different collations; return text without UNION collation coercion.
        $binary = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'BINARY ' : '';
        $playlistMatch = $this->usagePhotoMatch($photoLibraryId, $path, 'pi.photo_library_id', 'pi.image_url', 'playlist', $params);
        $heroMatch = $this->usagePhotoMatch($photoLibraryId, $path, 'p.hero_image_id', 'p.hero_image_url', 'hero', $params);
        $pvMatch = $this->usagePhotoMatch($photoLibraryId, $path, 'vp.photo_library_id', 'vp.rel_path', 'pv', $params);
        $introMatch = $this->usagePhotoMatch($photoLibraryId, $path, null, 'pi.intro_image_url', 'intro', $params);
        $shareMatch = $this->usagePhotoMatch($photoLibraryId, $path, null, 'pi.share_image_url', 'share', $params);
        $savedMatch = $this->usagePhotoMatch($photoLibraryId, $path, 'sp.photo_library_id', 'sp.rel_path', 'saved', $params);
        $libraryJoin = $this->paletteColumnAvailable() ? 'JOIN photo_library shared_photo ON shared_photo.photo_library_id = pp.photo_library_id' : '';
        $sharedPalette = $this->paletteColumnAvailable() ? 'shared_photo.palette_id' : 'pp.palette_id';
        $beforeMatch = $this->paletteColumnAvailable() ? ' OR pp.`before` = 1' : '';
        $sql = <<<SQL
            SELECT
                'playlist_item' AS usage_type,
                p.playlist_id AS ref_id,
                {$binary}p.title AS ref_title,
                {$binary}CONCAT('Playlist #', p.playlist_id, ': ', p.title) AS label,
                {$binary}CONCAT('Item #', pi.playlist_item_id) AS detail,
                '/admin/playlists' AS admin_path
            FROM playlist_items pi
            LEFT JOIN playlists p
              ON p.playlist_id = pi.playlist_id
            WHERE {$playlistMatch}

            UNION ALL

            SELECT
                'playlist_hero' AS usage_type,
                p.playlist_id AS ref_id,
                {$binary}p.title AS ref_title,
                {$binary}CONCAT('Playlist #', p.playlist_id, ': ', p.title) AS label,
                'Hero image' AS detail,
                '/admin/playlists' AS admin_path
            FROM playlists p
            WHERE {$heroMatch}

            UNION ALL

            SELECT
                'playlist_set_cover' AS usage_type,
                ps.id AS ref_id,
                {$binary}ps.title AS ref_title,
                {$binary}CONCAT('Playlist Set #', ps.id, ': ', ps.title) AS label,
                'Cover image' AS detail,
                '/admin/playlist-sets' AS admin_path
            FROM playlist_sets ps
            WHERE ps.cover_photo_library_id = :photo_library_id_set_cover

            UNION ALL

            SELECT
                'project_photo' AS usage_type,
                pp.project_id AS ref_id,
                {$binary}p.project_name AS ref_title,
                {$binary}CONCAT('Project #', pp.project_id, ': ', p.project_name) AS label,
                {$binary}CASE WHEN pp.`use` = 1 THEN 'Project Photos: Use enabled' ELSE 'Project Photos: Use disabled (still linked)' END AS detail,
                '/admin/project' AS admin_path
            FROM project_photos pp
            LEFT JOIN projects p ON p.id = pp.project_id
            WHERE pp.photo_library_id = :photo_library_id_project

            UNION ALL

            SELECT
                'palette_viewer_photo' AS usage_type,
                vp.palette_viewer_id AS ref_id,
                {$binary}pv.title AS ref_title,
                {$binary}CONCAT('PV #', vp.palette_viewer_id, ': ', pv.title) AS label,
                {$binary}CONCAT(pv.format, ' / ', vp.photo_type) AS detail,
                '/admin/project' AS admin_path
            FROM palette_viewer_photos vp
            LEFT JOIN palette_viewers pv ON pv.palette_viewer_id = vp.palette_viewer_id
            WHERE {$pvMatch}

            UNION ALL

            SELECT DISTINCT
                'project_palette_viewer_photo' AS usage_type,
                pv.palette_viewer_id AS ref_id,
                {$binary}pv.title AS ref_title,
                {$binary}CONCAT('PV #', pv.palette_viewer_id, ': ', pv.title) AS label,
                {$binary}CONCAT(pv.format, ' / shared Project Photos') AS detail,
                '/admin/project' AS admin_path
            FROM project_photos pp
            {$libraryJoin}
            JOIN project_palettes pal ON pal.project_id = pp.project_id AND (pal.saved_palette_id = {$sharedPalette}{$beforeMatch})
            JOIN palette_viewers pv ON pv.saved_palette_id = pal.saved_palette_id
                OR (pv.project_id = pp.project_id AND LOWER(TRIM(pv.format)) = 'painter')
            WHERE pp.photo_library_id = :photo_library_id_shared_pv AND pp.`use` = 1 AND pal.is_final = 1

            UNION ALL

            SELECT
                'playlist_set_item' AS usage_type,
                ps.id AS ref_id,
                {$binary}ps.title AS ref_title,
                {$binary}CONCAT('Playlist Set #', ps.id, ': ', ps.title) AS label,
                {$binary}CONCAT('Set item #', psi.id) AS detail,
                '/admin/playlist-instance-sets' AS admin_path
            FROM playlist_instance_set_items psi
            LEFT JOIN playlist_instance_sets ps
              ON ps.id = psi.playlist_instance_set_id
            WHERE psi.photo_library_id = :photo_library_id_set

            UNION ALL

            SELECT
                'playlist_instance_intro' AS usage_type,
                pi.playlist_instance_id AS ref_id,
                {$binary}pi.instance_name AS ref_title,
                {$binary}CONCAT('Playlist Instance #', pi.playlist_instance_id, ': ', pi.instance_name) AS label,
                'Intro image' AS detail,
                '/admin/playlist-instances' AS admin_path
            FROM playlist_instances pi
            WHERE {$introMatch}

            UNION ALL

            SELECT
                'playlist_instance_share' AS usage_type,
                pi.playlist_instance_id AS ref_id,
                {$binary}pi.instance_name AS ref_title,
                {$binary}CONCAT('Playlist Instance #', pi.playlist_instance_id, ': ', pi.instance_name) AS label,
                'Share image' AS detail,
                '/admin/playlist-instances' AS admin_path
            FROM playlist_instances pi
            WHERE {$shareMatch}

            UNION ALL

            SELECT
                'article_hero' AS usage_type,
                a.id AS ref_id,
                {$binary}a.title AS ref_title,
                {$binary}CONCAT('Article #', a.id, ': ', a.title) AS label,
                'Hero image' AS detail,
                '/admin/articles' AS admin_path
            FROM articles a
            WHERE a.hero_asset_id = :photo_library_id_article_hero

            UNION ALL

            SELECT
                'article_hero_mobile' AS usage_type,
                a.id AS ref_id,
                {$binary}a.title AS ref_title,
                {$binary}CONCAT('Article #', a.id, ': ', a.title) AS label,
                'Mobile hero image' AS detail,
                '/admin/articles' AS admin_path
            FROM articles a
            WHERE a.hero_mobile_asset_id = :photo_library_id_article_hero_mobile

            UNION ALL

            SELECT
                'article_section' AS usage_type,
                a.id AS ref_id,
                {$binary}a.title AS ref_title,
                {$binary}CONCAT('Article #', a.id, ': ', a.title) AS label,
                {$binary}CONCAT('Section #', s.id) AS detail,
                '/admin/articles' AS admin_path
            FROM article_sections s
            JOIN articles a
              ON a.id = s.article_id
            WHERE s.asset_id = :photo_library_id_article_section

            UNION ALL

            SELECT
                'saved_palette_set_photo' AS usage_type,
                s.saved_palette_id AS ref_id,
                {$binary}p.nickname AS ref_title,
                {$binary}CONCAT('Saved Palette #', s.saved_palette_id, ': ', COALESCE(NULLIF(p.nickname, ''), p.palette_hash)) AS label,
                {$binary}CONCAT('Set ', COALESCE(NULLIF(s.title, ''), s.slug), ' / ', sp.photo_type) AS detail,
                '/admin/saved-palettes' AS admin_path
            FROM saved_palette_set_photos sp
            LEFT JOIN saved_palette_sets s
              ON s.id = sp.saved_palette_set_id
            LEFT JOIN saved_palettes p
              ON p.id = s.saved_palette_id
            WHERE {$savedMatch}

            UNION ALL

            SELECT
                'photo_group' AS usage_type,
                pg.group_id AS ref_id,
                {$binary}pg.title AS ref_title,
                {$binary}CONCAT('Photo Group #', pg.group_id, ': ', pg.title) AS label,
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
            ...$params,
            ':photo_library_id_set_cover' => $photoLibraryId,
            ':photo_library_id_project' => $photoLibraryId,
            ':photo_library_id_shared_pv' => $photoLibraryId,
            ':photo_library_id_set' => $photoLibraryId,
            ':photo_library_id_article_hero' => $photoLibraryId,
            ':photo_library_id_article_hero_mobile' => $photoLibraryId,
            ':photo_library_id_article_section' => $photoLibraryId,
            ':photo_library_id_group' => $photoLibraryId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function usagePhotoMatch(int $id, string $path, ?string $idColumn, string $urlColumn, string $key, array &$params): string
    {
        $conditions = [];
        if ($idColumn) {
            $conditions[] = "{$idColumn} = :{$key}_id";
            $params[":{$key}_id"] = $id;
        }
        $conditions[] = "{$urlColumn} = :{$key}_ref";
        $conditions[] = "{$urlColumn} LIKE :{$key}_ref_path";
        $params[":{$key}_ref"] = 'photo:' . $id;
        $params[":{$key}_ref_path"] = 'photo:' . $id . '|%';
        if ($path !== '') {
            $cleanUrl = "SUBSTRING_INDEX(SUBSTRING_INDEX({$urlColumn}, '?', 1), '#', 1)";
            $conditions[] = "{$cleanUrl} = :{$key}_path";
            $conditions[] = "{$cleanUrl} LIKE :{$key}_path_suffix ESCAPE '!'";
            $params[":{$key}_path"] = ltrim($path, '/');
            $params[":{$key}_path_suffix"] = '%/' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], ltrim($path, '/'));
        }
        return '(' . implode(' OR ', $conditions) . ')';
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
