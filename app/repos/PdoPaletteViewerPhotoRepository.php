<?php
declare(strict_types=1);

namespace App\Repos;

use App\Entities\PaletteViewerPhoto;
use InvalidArgumentException;
use PDO;

final class PdoPaletteViewerPhotoRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * @return PaletteViewerPhoto[]
     */
    public function findByViewerId(int $paletteViewerId): array
    {
        if ($paletteViewerId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM palette_viewer_photos
              WHERE palette_viewer_id = :palette_viewer_id
              ORDER BY order_index ASC, palette_viewer_photo_id ASC'
        );
        $stmt->execute([':palette_viewer_id' => $paletteViewerId]);

        return array_map(
            fn(array $row): PaletteViewerPhoto => $this->rowToPaletteViewerPhoto($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $paletteViewerPhotoId): ?PaletteViewerPhoto
    {
        if ($paletteViewerPhotoId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM palette_viewer_photos
              WHERE palette_viewer_photo_id = :palette_viewer_photo_id
              LIMIT 1'
        );
        $stmt->execute([':palette_viewer_photo_id' => $paletteViewerPhotoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->rowToPaletteViewerPhoto($row) : null;
    }

    public function create(array $data): PaletteViewerPhoto
    {
        $paletteViewerId = (int)($data['palette_viewer_id'] ?? 0);
        $photoType = $this->normalizeKey((string)($data['photo_type'] ?? ''));
        $triggerMode = $this->normalizeKey((string)($data['trigger_mode'] ?? 'any'));

        if ($paletteViewerId <= 0) {
            throw new InvalidArgumentException('palette_viewer_id required');
        }
        if ($photoType === '') {
            throw new InvalidArgumentException('photo_type required');
        }
        if ($triggerMode === '') {
            throw new InvalidArgumentException('trigger_mode required');
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO palette_viewer_photos (
                palette_viewer_id,
                photo_library_id,
                rel_path,
                photo_type,
                trigger_mode,
                trigger_color_id,
                caption,
                alt_text,
                order_index,
                created_at,
                updated_at
             ) VALUES (
                :palette_viewer_id,
                :photo_library_id,
                :rel_path,
                :photo_type,
                :trigger_mode,
                :trigger_color_id,
                :caption,
                :alt_text,
                :order_index,
                NOW(),
                NOW()
             )"
        );
        $stmt->execute([
            ':palette_viewer_id' => $paletteViewerId,
            ':photo_library_id' => $this->optionalPositiveInt($data['photo_library_id'] ?? null),
            ':rel_path' => $this->nullableText($data['rel_path'] ?? null),
            ':photo_type' => $photoType,
            ':trigger_mode' => $triggerMode,
            ':trigger_color_id' => $this->optionalPositiveInt($data['trigger_color_id'] ?? null),
            ':caption' => $this->nullableText($data['caption'] ?? null),
            ':alt_text' => $this->nullableText($data['alt_text'] ?? null),
            ':order_index' => max(0, (int)($data['order_index'] ?? 0)),
        ]);

        return $this->requireById((int)$this->pdo->lastInsertId());
    }

    public function update(int $paletteViewerPhotoId, array $data): PaletteViewerPhoto
    {
        if ($paletteViewerPhotoId <= 0) {
            throw new InvalidArgumentException('palette_viewer_photo_id required');
        }

        $paletteViewerId = (int)($data['palette_viewer_id'] ?? 0);
        $photoType = $this->normalizeKey((string)($data['photo_type'] ?? ''));
        $triggerMode = $this->normalizeKey((string)($data['trigger_mode'] ?? 'any'));

        if ($paletteViewerId <= 0) {
            throw new InvalidArgumentException('palette_viewer_id required');
        }
        if ($photoType === '') {
            throw new InvalidArgumentException('photo_type required');
        }
        if ($triggerMode === '') {
            throw new InvalidArgumentException('trigger_mode required');
        }

        $stmt = $this->pdo->prepare(
            "UPDATE palette_viewer_photos
                SET palette_viewer_id = :palette_viewer_id,
                    photo_library_id = :photo_library_id,
                    rel_path = :rel_path,
                    photo_type = :photo_type,
                    trigger_mode = :trigger_mode,
                    trigger_color_id = :trigger_color_id,
                    caption = :caption,
                    alt_text = :alt_text,
                    order_index = :order_index,
                    updated_at = NOW()
              WHERE palette_viewer_photo_id = :palette_viewer_photo_id"
        );
        $stmt->execute([
            ':palette_viewer_photo_id' => $paletteViewerPhotoId,
            ':palette_viewer_id' => $paletteViewerId,
            ':photo_library_id' => $this->optionalPositiveInt($data['photo_library_id'] ?? null),
            ':rel_path' => $this->nullableText($data['rel_path'] ?? null),
            ':photo_type' => $photoType,
            ':trigger_mode' => $triggerMode,
            ':trigger_color_id' => $this->optionalPositiveInt($data['trigger_color_id'] ?? null),
            ':caption' => $this->nullableText($data['caption'] ?? null),
            ':alt_text' => $this->nullableText($data['alt_text'] ?? null),
            ':order_index' => max(0, (int)($data['order_index'] ?? 0)),
        ]);

        return $this->requireById($paletteViewerPhotoId);
    }

    public function delete(int $paletteViewerPhotoId): bool
    {
        if ($paletteViewerPhotoId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM palette_viewer_photos
              WHERE palette_viewer_photo_id = :palette_viewer_photo_id'
        );
        $stmt->execute([':palette_viewer_photo_id' => $paletteViewerPhotoId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return PaletteViewerPhoto[]
     */
    public function replaceForViewer(int $paletteViewerId, array $rows): array
    {
        if ($paletteViewerId <= 0) {
            throw new InvalidArgumentException('palette_viewer_id required');
        }

        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM palette_viewer_photos WHERE palette_viewer_id = :palette_viewer_id'
            );
            $stmt->execute([':palette_viewer_id' => $paletteViewerId]);

            $created = [];
            foreach (array_values($rows) as $index => $row) {
                $row['palette_viewer_id'] = $paletteViewerId;
                $row['order_index'] = $row['order_index'] ?? $index;
                $created[] = $this->create($row);
            }

            if ($startedTransaction) {
                $this->pdo->commit();
            }

            return $created;
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function requireById(int $paletteViewerPhotoId): PaletteViewerPhoto
    {
        $photo = $this->findById($paletteViewerPhotoId);
        if (!$photo) {
            throw new InvalidArgumentException('Palette Viewer Photo was not found.');
        }

        return $photo;
    }

    private function rowToPaletteViewerPhoto(array $row): PaletteViewerPhoto
    {
        return new PaletteViewerPhoto(
            paletteViewerPhotoId: (int)$row['palette_viewer_photo_id'],
            paletteViewerId: (int)$row['palette_viewer_id'],
            photoLibraryId: isset($row['photo_library_id']) && $row['photo_library_id'] !== null
                ? (int)$row['photo_library_id']
                : null,
            relPath: $this->nullableText($row['rel_path'] ?? null),
            photoType: (string)$row['photo_type'],
            triggerMode: (string)$row['trigger_mode'],
            triggerColorId: isset($row['trigger_color_id']) && $row['trigger_color_id'] !== null
                ? (int)$row['trigger_color_id']
                : null,
            caption: $this->nullableText($row['caption'] ?? null),
            altText: $this->nullableText($row['alt_text'] ?? null),
            orderIndex: (int)$row['order_index'],
            createdAt: (string)$row['created_at'],
            updatedAt: $this->nullableText($row['updated_at'] ?? null)
        );
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? '';
        return trim($value, '_-');
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int)$value;
        return $int > 0 ? $int : null;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text === '' ? null : $text;
    }
}
