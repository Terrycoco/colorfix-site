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
            []
        );
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
                item_type,
                layout,
                title_mode,
                star,
                transition,
                duration_ms,
                {$excludeSelect}
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
                $row['item_type'] ?? null,
                $star,
                $row['layout'] ?? null,
                $row['transition'] ?? null,
                $row['duration_ms'] !== null ? (int)$row['duration_ms'] : null,
                $row['title_mode'] ?? null,
                isset($row['exclude_from_thumbs']) ? (bool)$row['exclude_from_thumbs'] : null
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
            SELECT playlist_id, title, type
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
        ];
    }
}
