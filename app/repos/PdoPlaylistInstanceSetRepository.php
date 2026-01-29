<?php
declare(strict_types=1);

namespace App\Repos;

use App\Entities\PlaylistInstanceSet;
use PDO;

final class PdoPlaylistInstanceSetRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    /**
     * @return PlaylistInstanceSet[]
     */
    public function listAll(): array
    {
        $sql = <<<SQL
            SELECT id, handle, title, subtitle, context
            FROM playlist_instance_sets
            ORDER BY id DESC
            SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $sets = [];
        foreach ($rows as $row) {
            $sets[] = new PlaylistInstanceSet(
                (int)$row['id'],
                (string)$row['handle'],
                (string)$row['title'],
                $row['subtitle'] !== null ? (string)$row['subtitle'] : null,
                $row['context'] !== null ? (string)$row['context'] : null
            );
        }
        return $sets;
    }

    public function getById(int $id): ?PlaylistInstanceSet
    {
        $sql = <<<SQL
            SELECT id, handle, title, subtitle, context
            FROM playlist_instance_sets
            WHERE id = :id
            LIMIT 1
            SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return new PlaylistInstanceSet(
            (int)$row['id'],
            (string)$row['handle'],
            (string)$row['title'],
            $row['subtitle'] !== null ? (string)$row['subtitle'] : null,
            $row['context'] !== null ? (string)$row['context'] : null
        );
    }

    public function getByHandle(string $handle): ?PlaylistInstanceSet
    {
        $sql = <<<SQL
            SELECT id, handle, title, subtitle, context
            FROM playlist_instance_sets
            WHERE handle = :handle
            LIMIT 1
            SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['handle' => $handle]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return new PlaylistInstanceSet(
            (int)$row['id'],
            (string)$row['handle'],
            (string)$row['title'],
            $row['subtitle'] !== null ? (string)$row['subtitle'] : null,
            $row['context'] !== null ? (string)$row['context'] : null
        );
    }

    public function save(PlaylistInstanceSet $set): PlaylistInstanceSet
    {
        if ($set->id === null) {
            $sql = <<<SQL
                INSERT INTO playlist_instance_sets (handle, title, subtitle, context)
                VALUES (:handle, :title, :subtitle, :context)
                SQL;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'handle' => $set->handle,
                'title' => $set->title,
                'subtitle' => $set->subtitle,
                'context' => $set->context,
            ]);
            $set->id = (int)$this->pdo->lastInsertId();
            return $set;
        }

        $sql = <<<SQL
            UPDATE playlist_instance_sets
            SET handle = :handle,
                title = :title,
                subtitle = :subtitle,
                context = :context
            WHERE id = :id
            SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $set->id,
            'handle' => $set->handle,
            'title' => $set->title,
            'subtitle' => $set->subtitle,
            'context' => $set->context,
        ]);
        return $set;
    }
}
