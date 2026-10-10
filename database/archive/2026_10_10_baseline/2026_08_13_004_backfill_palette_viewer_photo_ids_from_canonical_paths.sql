-- Backfill canonical Palette Viewer photo_library_id values by parsing the
-- canonical palette_viewer_photos.rel_path itself.
--
-- This catches migrated rows where the canonical row has an old rendered
-- applied-palette path such as /photos/rendered/ap_38_c7605d015b7d.jpg, but
-- the legacy source row is not usable for matching.

CREATE TABLE IF NOT EXISTS migration_20260813_palette_viewer_photo_library_map (
    palette_viewer_photo_id BIGINT UNSIGNED NOT NULL,
    previous_photo_library_id INT UNSIGNED NULL,
    new_photo_library_id INT UNSIGNED NOT NULL,
    match_method VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (palette_viewer_photo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migration_20260813_palette_viewer_photo_library_map (
    palette_viewer_photo_id,
    previous_photo_library_id,
    new_photo_library_id,
    match_method
)
SELECT
    pvp.palette_viewer_photo_id,
    pvp.photo_library_id AS previous_photo_library_id,
    pl.photo_library_id AS new_photo_library_id,
    'canonical_ap_path_source' AS match_method
FROM palette_viewer_photos pvp
INNER JOIN photo_library pl
    ON pl.source_type = 'applied_palette'
   AND pl.source_id = CAST(
        SUBSTRING_INDEX(
            SUBSTRING_INDEX(
                SUBSTRING_INDEX(
                    SUBSTRING_INDEX(pvp.rel_path, '/', -1),
                    '.',
                    1
                ),
                '_',
                2
            ),
            '_',
            -1
        ) AS UNSIGNED
   )
LEFT JOIN migration_20260813_palette_viewer_photo_library_map existing
    ON existing.palette_viewer_photo_id = pvp.palette_viewer_photo_id
WHERE pvp.photo_library_id IS NULL
  AND existing.palette_viewer_photo_id IS NULL
  AND pvp.rel_path REGEXP '/ap_[0-9]+(_[^/\\.]+)?\\.jpg$';

UPDATE palette_viewer_photos pvp
INNER JOIN migration_20260813_palette_viewer_photo_library_map m
    ON m.palette_viewer_photo_id = pvp.palette_viewer_photo_id
SET pvp.photo_library_id = m.new_photo_library_id
WHERE pvp.photo_library_id IS NULL
  AND m.match_method = 'canonical_ap_path_source';

-- Validation:
-- SELECT match_method, COUNT(*) AS backfilled_rows FROM migration_20260813_palette_viewer_photo_library_map GROUP BY match_method;
-- SELECT palette_viewer_photo_id, palette_viewer_id, rel_path
-- FROM palette_viewer_photos
-- WHERE photo_library_id IS NULL
--   AND rel_path REGEXP '/ap_[0-9]+(_[^/\\.]+)?\\.jpg$';
