<?php
declare(strict_types=1);

namespace App\Repos;

use App\PHOTOS\Repos\PdoPhotoLibraryRepository;

use PDO;

final class PdoColorTriggerPhotoRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Return gallery-enabled palette photos associated with a color.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findGalleryTriggerPhotosForColor(int $colorId): array
    {
        if ($colorId <= 0) { return []; }

            $stmt = $this->pdo->prepare("SELECT pl.photo_library_id, pl.source_type, pl.inspiration_photo_id, pl.rel_path AS photo_url,
                'zoom' AS photo_type, NULL AS trigger_color_id, pl.photo_library_id AS order_index,
                sp.id AS palette_id, sp.palette_hash, sp.nickname AS palette_name,
                sp.brand AS palette_brand, NULL AS saved_palette_set_id
                FROM photo_library pl LEFT JOIN photo_library inspiration ON inspiration.photo_library_id = pl.inspiration_photo_id AND inspiration.source_type = 'art'
                    JOIN saved_palettes sp ON sp.id = CASE WHEN pl.source_type = 'art_inspired' AND inspiration.photo_library_id IS NOT NULL THEN inspiration.palette_id ELSE pl.palette_id END
                JOIN saved_palette_members m ON m.saved_palette_id = sp.id
                WHERE m.color_id = ? AND pl.show_in_gallery = 1
                  AND COALESCE(pl.is_inactive, 0) = 0 AND COALESCE(pl.is_retired, 0) = 0
                  AND pl.rel_path IS NOT NULL AND pl.rel_path <> ''
                ORDER BY pl.photo_library_id");
            $stmt->execute([$colorId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

}
