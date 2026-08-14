-- Backfill canonical Palette Viewer photo_library_id values for migrated
-- rendered applied-palette photos stored with old hashed file paths.
--
-- Older canonical rows may contain paths such as:
--   /photos/rendered/ap_37_8c71f6f0e7eb.jpg
-- Photo Library owns the current rendered path for source_type=applied_palette
-- and source_id=37. This fills only still-missing photo_library_id values.

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
    'rendered_ap_hashed_source' AS match_method
FROM palette_viewer_photos pvp
INNER JOIN migration_20260812_saved_palette_viewer_photo_map pm
    ON pm.palette_viewer_photo_id = pvp.palette_viewer_photo_id
INNER JOIN saved_palette_photos spp
    ON spp.id = pm.saved_palette_photo_id
INNER JOIN photo_library pl
    ON pl.source_type = 'applied_palette'
   AND pl.source_id = CAST(
        SUBSTRING_INDEX(
            SUBSTRING_INDEX(
                SUBSTRING_INDEX(
                    SUBSTRING_INDEX(COALESCE(NULLIF(spp.rel_path, ''), pvp.rel_path), '/', -1),
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
  AND COALESCE(NULLIF(spp.rel_path, ''), pvp.rel_path) REGEXP '/ap_[0-9]+_[^/\\.]+\\.jpg$';

UPDATE palette_viewer_photos pvp
INNER JOIN migration_20260813_palette_viewer_photo_library_map m
    ON m.palette_viewer_photo_id = pvp.palette_viewer_photo_id
SET pvp.photo_library_id = m.new_photo_library_id
WHERE pvp.photo_library_id IS NULL
  AND m.match_method = 'rendered_ap_hashed_source';

-- Validation:
-- SELECT match_method, COUNT(*) AS backfilled_rows FROM migration_20260813_palette_viewer_photo_library_map GROUP BY match_method;
-- SELECT pvp.palette_viewer_photo_id, pvp.palette_viewer_id, pvp.rel_path
-- FROM palette_viewer_photos pvp
-- INNER JOIN migration_20260812_saved_palette_viewer_photo_map pm
--     ON pm.palette_viewer_photo_id = pvp.palette_viewer_photo_id
-- WHERE pvp.photo_library_id IS NULL
--   AND pvp.rel_path REGEXP '/ap_[0-9]+_[^/\\.]+\\.jpg$';
