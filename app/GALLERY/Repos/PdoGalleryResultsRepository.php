<?php
declare(strict_types=1);

namespace App\GALLERY\Repos;

use PDO;

final class PdoGalleryResultsRepository
{
    public function __construct(private PDO $pdo) {}

    /** @param list<int> $colorIds
     *  @return array<int,list<array<string,mixed>>>
     */
    public function findByColorIds(array $colorIds): array
    {
        if ($colorIds === []) return [];

        $placeholders = implode(',', array_fill(0, count($colorIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT v.color_id,
                   v.photo_id AS photo_library_id,
                   v.rel_path,
                   v.palette_id AS saved_palette_id,
                   sp.palette_hash,
                   sp.nickname AS palette_name,
                   sp.brand AS palette_brand
              FROM vw_photo_palette_colors v
              JOIN photo_library p ON p.photo_library_id = v.photo_id
              JOIN saved_palettes sp ON sp.id = v.palette_id
             WHERE v.color_id IN ($placeholders)
               AND p.show_in_gallery = 1
               AND COALESCE(p.is_inactive, 0) = 0
               AND COALESCE(p.is_retired, 0) = 0
               AND v.rel_path IS NOT NULL
               AND v.rel_path <> ''
             ORDER BY v.color_id ASC, v.photo_id DESC
        " );
        $stmt->execute($colorIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byColor = [];
        foreach ($rows as $row) {
            $id = (int)$row['color_id'];
            $byColor[$id][] = $row;
        }
        return $byColor;
    }
}
