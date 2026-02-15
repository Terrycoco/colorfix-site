<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

class PdoPhotoLibraryRepository
{
    public function __construct(private PDO $pdo) {}

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

    public function insert(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO photo_library
                (source_type, source_id, rel_path, title, tags, alt_text, show_in_gallery, has_palette, created_at)
             VALUES
                (:source_type, :source_id, :rel_path, :title, :tags, :alt_text, :show_in_gallery, :has_palette, NOW())"
        );
        $stmt->execute([
            ':source_type' => $data['source_type'],
            ':source_id' => $data['source_id'] ?? null,
            ':rel_path' => $data['rel_path'],
            ':title' => $data['title'] ?? null,
            ':tags' => $data['tags'] ?? null,
            ':alt_text' => $data['alt_text'] ?? null,
            ':show_in_gallery' => !empty($data['show_in_gallery']) ? 1 : 0,
            ':has_palette' => !empty($data['has_palette']) ? 1 : 0,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $allowed = ['rel_path', 'title', 'tags', 'alt_text', 'show_in_gallery', 'has_palette'];
        $setParts = [];
        $params = [':id' => $id];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $paramKey = ':' . $key;
            if (in_array($key, ['show_in_gallery', 'has_palette'], true)) {
                $params[$paramKey] = !empty($data[$key]) ? 1 : 0;
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
