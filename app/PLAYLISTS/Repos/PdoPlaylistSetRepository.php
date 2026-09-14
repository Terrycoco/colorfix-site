<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Repos;

use App\PLAYLISTS\Entities\PlaylistSet;
use App\PLAYLISTS\Entities\PlaylistSetItem;
use PDO;

final class PdoPlaylistSetRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    /**
     * @return PlaylistSet[]
     */
    public function listAll(
        bool $includeRetired = false
    ): array {
        $where =
            $includeRetired
                ? ''
                : 'WHERE is_retired = 0';

        $stmt =
            $this->pdo->query(
                "SELECT
                    id,
                    handle,
                    title,
                    subtitle,
                    context,
                    cover_photo_library_id,
                    end_cta_label,
                    end_cta_url,
                    end_cta_enabled,
                    is_retired,
                    retired_at,
                    created_at,
                    updated_at
                 FROM playlist_sets
                 {$where}
                 ORDER BY id DESC"
            );

        return array_map(
            fn(array $row): PlaylistSet =>
                $this->rowToSet($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    public function getById(
        int $id
    ): ?PlaylistSet {
        $stmt =
            $this->pdo->prepare(
                'SELECT
                    id,
                    handle,
                    title,
                    subtitle,
                    context,
                    cover_photo_library_id,
                    end_cta_label,
                    end_cta_url,
                    end_cta_enabled,
                    is_retired,
                    retired_at,
                    created_at,
                    updated_at
                 FROM playlist_sets
                 WHERE id = :id
                 LIMIT 1'
            );

        $stmt->execute([
            ':id' => $id,
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return $row
            ? $this->rowToSet($row)
            : null;
    }

    public function getByHandle(
        string $handle
    ): ?PlaylistSet {
        $stmt =
            $this->pdo->prepare(
                'SELECT
                    id,
                    handle,
                    title,
                    subtitle,
                    context,
                    cover_photo_library_id,
                    end_cta_label,
                    end_cta_url,
                    end_cta_enabled,
                    is_retired,
                    retired_at,
                    created_at,
                    updated_at
                 FROM playlist_sets
                 WHERE handle = :handle
                 LIMIT 1'
            );

        $stmt->execute([
            ':handle' =>
                trim($handle),
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return $row
            ? $this->rowToSet($row)
            : null;
    }

    public function save(
        PlaylistSet $set
    ): PlaylistSet {
        if ($set->id === null) {
            $stmt =
                $this->pdo->prepare(
                    'INSERT INTO playlist_sets (
                        handle,
                        title,
                        subtitle,
                        context,
                        cover_photo_library_id,
                        end_cta_label,
                        end_cta_url,
                        end_cta_enabled,
                        is_retired,
                        retired_at
                    ) VALUES (
                        :handle,
                        :title,
                        :subtitle,
                        :context,
                        :cover_photo_library_id,
                        :end_cta_label,
                        :end_cta_url,
                        :end_cta_enabled,
                        :is_retired,
                        :retired_at
                    )'
                );

            $stmt->execute(
                $this->setParams($set)
            );

            $set->id =
                (int)$this->pdo
                    ->lastInsertId();

            return $this->getById($set->id)
                ?? $set;
        }

        $stmt =
            $this->pdo->prepare(
                'UPDATE playlist_sets
                    SET handle = :handle,
                        title = :title,
                        subtitle = :subtitle,
                        context = :context,
                        cover_photo_library_id = :cover_photo_library_id,
                        end_cta_label = :end_cta_label,
                        end_cta_url = :end_cta_url,
                        end_cta_enabled = :end_cta_enabled,
                        is_retired = :is_retired,
                        retired_at = :retired_at,
                        updated_at = NOW()
                  WHERE id = :id'
            );

        $params =
            $this->setParams($set);

        $params[':id'] =
            $set->id;

        $stmt->execute(
            $params
        );

        return $this->getById($set->id)
            ?? $set;
    }

    /**
     * @return PlaylistSetItem[]
     */
    public function listItems(
        int $setId
    ): array {
        $stmt =
            $this->pdo->prepare(
                'SELECT
                    id,
                    playlist_set_id,
                    playlist_id,
                    target_set_id,
                    item_type,
                    sort_order,
                    created_at,
                    updated_at
                 FROM playlist_set_items
                 WHERE playlist_set_id = :set_id
                 ORDER BY sort_order ASC, id ASC'
            );

        $stmt->execute([
            ':set_id' =>
                $setId,
        ]);

        return array_map(
            fn(array $row): PlaylistSetItem =>
                $this->rowToItem($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function replaceItems(
        int $setId,
        array $items
    ): void {
        $delete =
            $this->pdo->prepare(
                'DELETE FROM playlist_set_items
                  WHERE playlist_set_id = :set_id'
            );

        $delete->execute([
            ':set_id' =>
                $setId,
        ]);

        $insert =
            $this->pdo->prepare(
                'INSERT INTO playlist_set_items (
                    playlist_set_id,
                    playlist_id,
                    target_set_id,
                    item_type,
                    sort_order
                 ) VALUES (
                    :playlist_set_id,
                    :playlist_id,
                    :target_set_id,
                    :item_type,
                    :sort_order
                 )'
            );

        foreach ($items as $index => $item) {
            $itemType =
                strtolower(
                    trim(
                        (string)(
                            $item['item_type']
                            ?? 'playlist'
                        )
                    )
                );

            if (!in_array(
                $itemType,
                ['playlist', 'set'],
                true
            )) {
                $itemType =
                    'playlist';
            }

            $playlistId =
                $itemType === 'playlist'
                    ? (int)(
                        $item['playlist_id']
                        ?? 0
                    )
                    : 0;

            $targetSetId =
                $itemType === 'set'
                    ? (int)(
                        $item['target_set_id']
                        ?? 0
                    )
                    : 0;

            if (
                $itemType === 'playlist'
                && $playlistId <= 0
            ) {
                continue;
            }

            if (
                $itemType === 'set'
                && $targetSetId <= 0
            ) {
                continue;
            }

            $insert->execute([
                ':playlist_set_id' =>
                    $setId,

                ':playlist_id' =>
                    $itemType === 'playlist'
                        ? $playlistId
                        : null,

                ':target_set_id' =>
                    $itemType === 'set'
                        ? $targetSetId
                        : null,

                ':item_type' =>
                    $itemType,

                ':sort_order' =>
                    isset($item['sort_order'])
                        ? (int)$item['sort_order']
                        : $index,
            ]);
        }

        $this->touch(
            $setId
        );
    }

    public function touch(
        int $setId
    ): void {
        $stmt =
            $this->pdo->prepare(
                'UPDATE playlist_sets
                    SET updated_at = NOW()
                  WHERE id = :id'
            );

        $stmt->execute([
            ':id' =>
                $setId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function setParams(
        PlaylistSet $set
    ): array {
        return [
            ':handle' =>
                trim($set->handle),

            ':title' =>
                trim($set->title),

            ':subtitle' =>
                $this->nullableTrim(
                    $set->subtitle
                ),

            ':context' =>
                $this->nullableTrim(
                    $set->context
                ),

            ':cover_photo_library_id' =>
                $set->coverPhotoLibraryId,

            ':end_cta_label' =>
                $this->nullableTrim(
                    $set->endCtaLabel
                ),

            ':end_cta_url' =>
                $this->nullableTrim(
                    $set->endCtaUrl
                ),

            ':end_cta_enabled' =>
                $set->endCtaEnabled
                    ? 1
                    : 0,

            ':is_retired' =>
                $set->isRetired
                    ? 1
                    : 0,

            ':retired_at' =>
                $set->retiredAt,
        ];
    }

    private function rowToSet(
        array $row
    ): PlaylistSet {
        return new PlaylistSet(
            id:
                (int)$row['id'],

            handle:
                (string)$row['handle'],

            title:
                (string)$row['title'],

            subtitle:
                $row['subtitle'] !== null
                    ? (string)$row['subtitle']
                    : null,

            context:
                $row['context'] !== null
                    ? (string)$row['context']
                    : null,

            coverPhotoLibraryId:
                $row['cover_photo_library_id'] !== null
                    ? (int)$row['cover_photo_library_id']
                    : null,

            endCtaLabel:
                $row['end_cta_label'] !== null
                    ? (string)$row['end_cta_label']
                    : null,

            endCtaUrl:
                $row['end_cta_url'] !== null
                    ? (string)$row['end_cta_url']
                    : null,

            endCtaEnabled:
                (bool)(
                    (int)$row['end_cta_enabled']
                ),

            isRetired:
                (bool)(
                    (int)$row['is_retired']
                ),

            retiredAt:
                $row['retired_at'] !== null
                    ? (string)$row['retired_at']
                    : null,

            createdAt:
                $row['created_at'] !== null
                    ? (string)$row['created_at']
                    : null,

            updatedAt:
                $row['updated_at'] !== null
                    ? (string)$row['updated_at']
                    : null,
        );
    }

    private function rowToItem(
        array $row
    ): PlaylistSetItem {
        return new PlaylistSetItem(
            id:
                (int)$row['id'],

            playlistSetId:
                (int)$row['playlist_set_id'],

            playlistId:
                $row['playlist_id'] !== null
                    ? (int)$row['playlist_id']
                    : null,

            targetSetId:
                $row['target_set_id'] !== null
                    ? (int)$row['target_set_id']
                    : null,

            itemType:
                (string)$row['item_type'],

            sortOrder:
                (int)$row['sort_order'],

            createdAt:
                $row['created_at'] !== null
                    ? (string)$row['created_at']
                    : null,

            updatedAt:
                $row['updated_at'] !== null
                    ? (string)$row['updated_at']
                    : null,
        );
    }

    private function nullableTrim(
        ?string $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value =
            trim($value);

        return $value !== ''
            ? $value
            : null;
    }
}
