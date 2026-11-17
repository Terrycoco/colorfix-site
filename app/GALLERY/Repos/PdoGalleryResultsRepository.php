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
            SELECT m.color_id, p.source_type, p.inspiration_photo_id,
                   p.photo_library_id,
                   p.rel_path,
                   sp.id AS saved_palette_id,
                   sp.palette_hash,
                   sp.nickname AS palette_name,
                   sp.brand AS palette_brand
              FROM photo_library p
              LEFT JOIN photo_library inspiration ON inspiration.photo_library_id = p.inspiration_photo_id AND inspiration.source_type = 'art'
              JOIN saved_palettes sp ON sp.id = CASE WHEN p.source_type = 'art_inspired' AND inspiration.photo_library_id IS NOT NULL THEN inspiration.palette_id ELSE p.palette_id END
              JOIN saved_palette_members m ON m.saved_palette_id = sp.id
             WHERE m.color_id IN ($placeholders)
               AND p.show_in_gallery = 1
               AND COALESCE(p.is_inactive, 0) = 0
               AND COALESCE(p.is_retired, 0) = 0
               AND p.rel_path IS NOT NULL
               AND p.rel_path <> ''
             ORDER BY m.color_id ASC, p.photo_library_id DESC
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
