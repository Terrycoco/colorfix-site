<?php
declare(strict_types=1);

namespace App\PHOTOS\Repos;

use PDO;

/** Persistence for explicit user-defined crops. Absence of a row means auto-center. */
final class PdoPhotoCropRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $photoLibraryId, string $type): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT photo_crop_id, photo_library_id, crop_type, crop_x, crop_y, '
            . 'crop_width, crop_height, created_at, updated_at '
            . 'FROM photo_crops WHERE photo_library_id = :photo_id AND crop_type = :type LIMIT 1'
        );
        $stmt->execute(['photo_id' => $photoLibraryId, 'type' => $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** All explicit crops for a photo; no synthetic defaults. */
    public function findAllForPhoto(int $photoLibraryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT photo_crop_id, photo_library_id, crop_type, crop_x, crop_y, '
            . 'crop_width, crop_height, created_at, updated_at '
            . 'FROM photo_crops WHERE photo_library_id = :photo_id ORDER BY crop_type'
        );
        $stmt->execute(['photo_id' => $photoLibraryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Insert or replace one explicit crop. Uses the caller's PDO transaction, if any.
     * Caller must validate geometry and ownership before writing.
     */
    public function upsert(
        int $photoLibraryId,
        string $type,
        float $x,
        float $y,
        float $width,
        float $height
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO photo_crops (photo_library_id, crop_type, crop_x, crop_y, crop_width, crop_height) '
            . 'VALUES (:photo_id, :type, :x, :y, :width, :height) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'crop_x = VALUES(crop_x), crop_y = VALUES(crop_y), '
            . 'crop_width = VALUES(crop_width), crop_height = VALUES(crop_height)'
        );
        $stmt->execute([
            'photo_id' => $photoLibraryId,
            'type' => $type,
            'x' => number_format($x, 6, '.', ''),
            'y' => number_format($y, 6, '.', ''),
            'width' => number_format($width, 6, '.', ''),
            'height' => number_format($height, 6, '.', ''),
        ]);
    }

    public function delete(int $photoLibraryId, string $type): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM photo_crops WHERE photo_library_id = :photo_id AND crop_type = :type'
        );
        $stmt->execute(['photo_id' => $photoLibraryId, 'type' => $type]);
    }
}
