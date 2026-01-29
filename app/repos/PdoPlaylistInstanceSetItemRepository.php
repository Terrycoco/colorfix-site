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
                id,
                playlist_instance_set_id,
                playlist_instance_id,
                item_type,
                target_set_id,
                title,
                photo_url,
                sort_order
            FROM playlist_instance_set_items
            WHERE playlist_instance_set_id = :set_id
            ORDER BY sort_order ASC, id ASC
            SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['set_id' => $setId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = [];
        foreach ($rows as $row) {
            $items[] = new PlaylistInstanceSetItem(
                (int)$row['id'],
                (int)$row['playlist_instance_set_id'],
                $row['playlist_instance_id'] !== null ? (int)$row['playlist_instance_id'] : null,
                (string)($row['item_type'] ?? 'instance'),
                $row['target_set_id'] !== null ? (int)$row['target_set_id'] : null,
                (string)$row['title'],
                (string)$row['photo_url'],
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
                    item_type,
                    target_set_id,
                    title,
                    photo_url,
                    sort_order
                ) VALUES (
                    :set_id,
                    :playlist_instance_id,
                    :item_type,
                    :target_set_id,
                    :title,
                    :photo_url,
                    :sort_order
                )
                SQL;
            $stmt = $this->pdo->prepare($sql);
            foreach ($items as $item) {
                $itemType = (string)($item['item_type'] ?? 'instance');
                $playlistInstanceId = null;
                $targetSetId = null;
                if ($itemType === 'set') {
                    $targetSetId = isset($item['target_set_id']) ? (int)$item['target_set_id'] : null;
                } else {
                    $playlistInstanceId = isset($item['playlist_instance_id']) ? (int)$item['playlist_instance_id'] : null;
                }
                $stmt->execute([
                    'set_id' => $setId,
                    'playlist_instance_id' => $playlistInstanceId,
                    'item_type' => $itemType,
                    'target_set_id' => $targetSetId,
                    'title' => (string)($item['title'] ?? ''),
                    'photo_url' => (string)($item['photo_url'] ?? ''),
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
