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
     * @return PlaylistItem[]|null
     */
    private function getItemsFromDb(string $playlistId): ?array
    {
        $excludeSelect = $this->getExcludeFromThumbsSelect();
        $sql = <<<SQL
            SELECT
                playlist_item_id,
                playlist_id,
                order_index,
                ap_id,
                palette_hash,
                image_url,
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

    private function getPlaylistMeta(string $playlistId): ?array
    {
        $sql = <<<SQL
            SELECT playlist_id, title, type
            FROM playlists
            WHERE playlist_id = :playlist_id
              AND is_active = 1
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
