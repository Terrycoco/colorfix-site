<?php
declare(strict_types=1);

namespace App\Repos;

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
        if ($colorId <= 0) {
            return [];
        }

        $sql = "
            SELECT merged.*
              FROM (
                SELECT COALESCE(spsp.photo_library_id, 0) AS photo_library_id,
                       COALESCE(NULLIF(pl.rel_path, ''), NULLIF(spsp.rel_path, '')) AS photo_url,
                       spsp.photo_type,
                       spsp.trigger_color_id,
                       spsp.order_index,
                       sp.id AS palette_id,
                       sp.palette_hash,
                       sp.nickname AS palette_name,
                       sp.brand AS palette_brand,
                       sps.id AS saved_palette_set_id
                  FROM saved_palette_members m
                  JOIN saved_palette_sets sps
                    ON sps.saved_palette_id = m.saved_palette_id
                  JOIN saved_palette_set_photos spsp
                    ON spsp.saved_palette_set_id = sps.id
                  JOIN saved_palettes sp
                    ON sp.id = m.saved_palette_id
             LEFT JOIN photo_library pl
                    ON pl.photo_library_id = spsp.photo_library_id
                 WHERE m.color_id = :color_id
                   AND (
                     (spsp.photo_library_id IS NOT NULL
                      AND COALESCE(pl.show_in_gallery, 0) = 1
                      AND COALESCE(pl.has_palette, 0) = 1
                      AND COALESCE(pl.is_inactive, 0) = 0
                      AND COALESCE(pl.is_retired, 0) = 0)
                     OR (spsp.photo_library_id IS NULL AND spsp.show_in_gallery = 1)
                   )
                   AND spsp.trigger_mode <> 'none'

                UNION ALL

                SELECT COALESCE(pl.photo_library_id, 0) AS photo_library_id,
                       pl.rel_path AS photo_url,
                       spp.photo_type,
                       spp.trigger_color_id,
                       spp.order_index,
                       sp.id AS palette_id,
                       sp.palette_hash,
                       sp.nickname AS palette_name,
                       sp.brand AS palette_brand,
                       NULL AS saved_palette_set_id
                  FROM saved_palette_members m
                  JOIN saved_palette_photos spp
                    ON spp.saved_palette_id = m.saved_palette_id
                  JOIN saved_palettes sp
                    ON sp.id = m.saved_palette_id
                  JOIN photo_library pl
                    ON pl.source_type = 'saved_palette_photo'
                   AND pl.source_id = spp.id
                 WHERE m.color_id = :legacy_color_id
                   AND pl.show_in_gallery = 1
                   AND pl.has_palette = 1
                   AND COALESCE(pl.is_inactive, 0) = 0
                   AND COALESCE(pl.is_retired, 0) = 0
                   AND NOT EXISTS (
                     SELECT 1
                       FROM saved_palette_sets modern_set
                       JOIN saved_palette_set_photos modern
                         ON modern.saved_palette_set_id = modern_set.id
                      WHERE modern_set.saved_palette_id = spp.saved_palette_id
                   )
              ) AS merged
             WHERE merged.photo_url IS NOT NULL
               AND merged.photo_url <> ''
             ORDER BY merged.photo_type = 'zoom' DESC,
                      merged.order_index ASC,
                      merged.palette_id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':color_id' => $colorId,
            ':legacy_color_id' => $colorId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}