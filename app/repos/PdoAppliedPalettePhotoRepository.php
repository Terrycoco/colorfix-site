<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

class PdoAppliedPalettePhotoRepository
{
    public function __construct(private PDO $pdo) {}

    public function getPhotosForPalette(int $paletteId): array
    {
        $sql = "
            SELECT id,
                   applied_palette_id,
                   rel_path,
                   photo_type,
                   trigger_mode,
                   trigger_color_id,
                   caption,
                   alt_text,
                   order_index,
                   created_at
              FROM applied_palette_photos
             WHERE applied_palette_id = :id
          ORDER BY order_index ASC, id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $paletteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getPhotoById(int $photoId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM applied_palette_photos WHERE id = :id");
        $stmt->execute([':id' => $photoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function getMaxPhotoOrder(int $paletteId): int
    {
        $stmt = $this->pdo->prepare("SELECT MAX(order_index) AS max_order FROM applied_palette_photos WHERE applied_palette_id = :id");
        $stmt->execute([':id' => $paletteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['max_order'] ?? 0);
    }

    public function addPhoto(int $paletteId, string $relPath, ?string $caption, ?string $altText, int $orderIndex): int
    {
        $sql = "
            INSERT INTO applied_palette_photos
                (applied_palette_id, rel_path, photo_type, trigger_mode, trigger_color_id, caption, alt_text, order_index, created_at)
            VALUES
                (:applied_palette_id, :rel_path, :photo_type, :trigger_mode, :trigger_color_id, :caption, :alt_text, :order_index, NOW())
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':applied_palette_id' => $paletteId,
            ':rel_path' => $relPath,
            ':photo_type' => 'full',
            ':trigger_mode' => 'any',
            ':trigger_color_id' => null,
            ':caption' => $caption,
            ':alt_text' => $altText,
            ':order_index' => $orderIndex,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function deletePhoto(int $photoId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM applied_palette_photos WHERE id = :id");
        $stmt->execute([':id' => $photoId]);
    }

    public function deletePhotosForPalette(int $paletteId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM applied_palette_photos WHERE applied_palette_id = :id");
        $stmt->execute([':id' => $paletteId]);
    }

    public function updatePhoto(int $photoId, int $paletteId, array $fields): void
    {
        if (empty($fields)) {
            return;
        }

        $allowed = [
            'photo_type',
            'trigger_mode',
            'trigger_color_id',
            'caption',
            'alt_text',
            'order_index',
        ];

        $setParts = [];
        $params = [
            ':id' => $photoId,
            ':applied_palette_id' => $paletteId,
        ];

        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }

            $paramKey = ':' . $column;
            $setParts[] = "{$column} = {$paramKey}";
            $params[$paramKey] = $value;
        }

        if (!$setParts) {
            return;
        }

        $sql = "
            UPDATE applied_palette_photos
               SET " . implode(', ', $setParts) . "
             WHERE id = :id
               AND applied_palette_id = :applied_palette_id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}
