<?php
declare(strict_types=1);

namespace App\Repos;

use App\Entities\PlaylistInstanceSet;
use PDO;

final class PdoPlaylistInstanceSetRepository
{
    private ?bool $hasEndCtaColumns = null;

    public function __construct(
        private PDO $pdo
    ) {}

    /**
     * @return PlaylistInstanceSet[]
     */
    public function listAll(): array
    {
        $endCtaSelect = $this->hasEndCtaColumns()
            ? 'end_cta_label, end_cta_url, end_cta_enabled'
            : 'NULL AS end_cta_label, NULL AS end_cta_url, 1 AS end_cta_enabled';
        $sql = <<<SQL
            SELECT id, handle, title, subtitle, context, updated_at, {$endCtaSelect}
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
                $row['context'] !== null ? (string)$row['context'] : null,
                $row['end_cta_label'] !== null ? (string)$row['end_cta_label'] : null,
                $row['end_cta_url'] !== null ? (string)$row['end_cta_url'] : null,
                (bool)((int)($row['end_cta_enabled'] ?? 1)),
                $row['updated_at'] !== null ? (string)$row['updated_at'] : null
            );
        }
        return $sets;
    }

    public function getById(int $id): ?PlaylistInstanceSet
    {
        $endCtaSelect = $this->hasEndCtaColumns()
            ? 'end_cta_label, end_cta_url, end_cta_enabled'
            : 'NULL AS end_cta_label, NULL AS end_cta_url, 1 AS end_cta_enabled';
        $sql = <<<SQL
            SELECT id, handle, title, subtitle, context, updated_at, {$endCtaSelect}
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
            $row['context'] !== null ? (string)$row['context'] : null,
            $row['end_cta_label'] !== null ? (string)$row['end_cta_label'] : null,
            $row['end_cta_url'] !== null ? (string)$row['end_cta_url'] : null,
            (bool)((int)($row['end_cta_enabled'] ?? 1)),
            $row['updated_at'] !== null ? (string)$row['updated_at'] : null
        );
    }

    public function getByHandle(string $handle): ?PlaylistInstanceSet
    {
        $endCtaSelect = $this->hasEndCtaColumns()
            ? 'end_cta_label, end_cta_url, end_cta_enabled'
            : 'NULL AS end_cta_label, NULL AS end_cta_url, 1 AS end_cta_enabled';
        $sql = <<<SQL
            SELECT id, handle, title, subtitle, context, updated_at, {$endCtaSelect}
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
            $row['context'] !== null ? (string)$row['context'] : null,
            $row['end_cta_label'] !== null ? (string)$row['end_cta_label'] : null,
            $row['end_cta_url'] !== null ? (string)$row['end_cta_url'] : null,
            (bool)((int)($row['end_cta_enabled'] ?? 1)),
            $row['updated_at'] !== null ? (string)$row['updated_at'] : null
        );
    }

    public function save(PlaylistInstanceSet $set): PlaylistInstanceSet
    {
        if (!$this->hasEndCtaColumns()) {
            return $this->saveLegacy($set);
        }

        if ($set->id === null) {
            $sql = <<<SQL
                INSERT INTO playlist_instance_sets (
                    handle,
                    title,
                    subtitle,
                    context,
                    end_cta_label,
                    end_cta_url,
                    end_cta_enabled
                )
                VALUES (
                    :handle,
                    :title,
                    :subtitle,
                    :context,
                    :end_cta_label,
                    :end_cta_url,
                    :end_cta_enabled
                )
                SQL;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'handle' => $set->handle,
                'title' => $set->title,
                'subtitle' => $set->subtitle,
                'context' => $set->context,
                'end_cta_label' => $set->endCtaLabel,
                'end_cta_url' => $set->endCtaUrl,
                'end_cta_enabled' => $set->endCtaEnabled ? 1 : 0,
            ]);
            $set->id = (int)$this->pdo->lastInsertId();
            return $set;
        }

        $sql = <<<SQL
            UPDATE playlist_instance_sets
            SET handle = :handle,
                title = :title,
                subtitle = :subtitle,
                context = :context,
                end_cta_label = :end_cta_label,
                end_cta_url = :end_cta_url,
                end_cta_enabled = :end_cta_enabled,
                updated_at = NOW()
            WHERE id = :id
            SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $set->id,
            'handle' => $set->handle,
            'title' => $set->title,
            'subtitle' => $set->subtitle,
            'context' => $set->context,
            'end_cta_label' => $set->endCtaLabel,
            'end_cta_url' => $set->endCtaUrl,
            'end_cta_enabled' => $set->endCtaEnabled ? 1 : 0,
        ]);
        return $set;
    }

    private function saveLegacy(PlaylistInstanceSet $set): PlaylistInstanceSet
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
                context = :context,
                updated_at = NOW()
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

    private function hasEndCtaColumns(): bool
    {
        if ($this->hasEndCtaColumns !== null) {
            return $this->hasEndCtaColumns;
        }
        $stmt = $this->pdo->query("SHOW COLUMNS FROM playlist_instance_sets LIKE 'end_cta_label'");
        $this->hasEndCtaColumns = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        return $this->hasEndCtaColumns;
    }
}
