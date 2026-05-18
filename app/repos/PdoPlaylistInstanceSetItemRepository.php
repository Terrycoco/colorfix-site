<?php
declare(strict_types=1);

namespace App\Repos;

use App\Entities\PlaylistInstanceSetItem;
use PDO;

final class PdoPlaylistInstanceSetItemRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    /**
     * @return PlaylistInstanceSetItem[]
     */
    public function listBySetId(int $setId): array
    {
        $sql = <<<SQL
            SELECT
                psi.id,
                psi.playlist_instance_set_id,
                psi.playlist_instance_id,
                COALESCE(psi.playlist_id, pi.playlist_id) AS playlist_id,
                psi.item_type,
                psi.target_set_id,
                psi.title,
                psi.subtitle,
                pi.display_subtitle,
                p.title AS playlist_title,
                psi.photo_url,
                psi.photo_library_id,
                psi.sort_order
            FROM playlist_instance_set_items psi
            LEFT JOIN playlist_instances pi
              ON pi.playlist_instance_id = psi.playlist_instance_id
            LEFT JOIN playlists p
              ON p.playlist_id = COALESCE(psi.playlist_id, pi.playlist_id)
            WHERE psi.playlist_instance_set_id = :set_id
            ORDER BY psi.sort_order ASC, psi.id ASC
            SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['set_id' => $setId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = [];
        foreach ($rows as $row) {
            $itemType = (string)($row['item_type'] ?? 'instance');
            $items[] = new PlaylistInstanceSetItem(
                (int)$row['id'],
                (int)$row['playlist_instance_set_id'],
                $row['playlist_instance_id'] !== null ? (int)$row['playlist_instance_id'] : null,
                $row['playlist_id'] !== null ? (int)$row['playlist_id'] : null,
                $itemType,
                $row['target_set_id'] !== null ? (int)$row['target_set_id'] : null,
                ((string)($row['title'] ?? '')) !== ''
                    ? (string)$row['title']
                    : (string)($row['playlist_title'] ?? ''),
                ((string)($row['subtitle'] ?? '')) !== ''
                    ? (string)$row['subtitle']
                    : ($itemType === 'set' ? '' : (string)($row['display_subtitle'] ?? '')),
                (string)$row['photo_url'],
                $row['photo_library_id'] !== null ? (int)$row['photo_library_id'] : null,
                (int)$row['sort_order']
            );
        }
        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function replaceItems(int $setId, array $items): void
    {
        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare('DELETE FROM playlist_instance_set_items WHERE playlist_instance_set_id = :set_id');
            $del->execute(['set_id' => $setId]);

            $sql = <<<SQL
                INSERT INTO playlist_instance_set_items (
                    playlist_instance_set_id,
                    playlist_instance_id,
                    playlist_id,
                    item_type,
                    target_set_id,
                    title,
                    subtitle,
                    photo_url,
                    photo_library_id,
                    sort_order
                ) VALUES (
                    :set_id,
                    :playlist_instance_id,
                    :playlist_id,
                    :item_type,
                    :target_set_id,
                    :title,
                    :subtitle,
                    :photo_url,
                    :photo_library_id,
                    :sort_order
                )
                SQL;
            $stmt = $this->pdo->prepare($sql);
            foreach ($items as $item) {
                $itemType = (string)($item['item_type'] ?? 'instance');
                $playlistInstanceId = null;
                $playlistId = null;
                $targetSetId = null;
                if ($itemType === 'set') {
                    $targetSetId = isset($item['target_set_id']) ? (int)$item['target_set_id'] : null;
                } elseif ($itemType === 'playlist') {
                    $playlistId = isset($item['playlist_id']) ? (int)$item['playlist_id'] : null;
                } else {
                    $playlistInstanceId = isset($item['playlist_instance_id']) ? (int)$item['playlist_instance_id'] : null;
                    $playlistId = isset($item['playlist_id']) ? (int)$item['playlist_id'] : null;
                }
                $stmt->execute([
                    'set_id' => $setId,
                    'playlist_instance_id' => $playlistInstanceId,
                    'playlist_id' => $playlistId,
                    'item_type' => $itemType,
                    'target_set_id' => $targetSetId,
                    'title' => (string)($item['title'] ?? ''),
                    'subtitle' => (string)($item['subtitle'] ?? ''),
                    'photo_url' => (string)($item['photo_url'] ?? ''),
                    'photo_library_id' => isset($item['photo_library_id']) ? (int)$item['photo_library_id'] : null,
                    'sort_order' => (int)($item['sort_order'] ?? 0),
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
