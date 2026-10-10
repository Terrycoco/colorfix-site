-- Backfill canonical Palette Viewer photo_library_id values for migrated
-- rendered palette photos whose current Photo Library row is stored as
-- source_type=saved_palette_photo.
--
-- This catches old canonical rel_path values such as:
--   /photos/rendered/ap_38_c7605d015b7d.jpg
-- when Photo Library owns the current path with the same deterministic
-- applied-palette prefix:
--   /photos/rendered/ap_38_<current-hash>.jpg

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
    parsed.palette_viewer_photo_id,
    parsed.previous_photo_library_id,
    pl.photo_library_id AS new_photo_library_id,
    'saved_palette_ap_prefix' AS match_method
FROM (
    SELECT
        pvp.palette_viewer_photo_id,
        pvp.photo_library_id AS previous_photo_library_id,
        CAST(
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
        ) AS applied_palette_id
    FROM palette_viewer_photos pvp
    WHERE pvp.photo_library_id IS NULL
      AND pvp.rel_path REGEXP '/ap_[0-9]+(_[^/\\.]+)?\\.jpg$'
) parsed
INNER JOIN photo_library pl
    ON pl.photo_library_id = (
        SELECT pl2.photo_library_id
        FROM photo_library pl2
        WHERE pl2.source_type = 'saved_palette_photo'
          AND pl2.rel_path LIKE CONCAT('/photos/rendered/ap_', parsed.applied_palette_id, '_%')
        ORDER BY pl2.updated_at DESC, pl2.created_at DESC, pl2.photo_library_id DESC
        LIMIT 1
    )
LEFT JOIN migration_20260813_palette_viewer_photo_library_map existing
    ON existing.palette_viewer_photo_id = parsed.palette_viewer_photo_id
WHERE existing.palette_viewer_photo_id IS NULL;

UPDATE palette_viewer_photos pvp
INNER JOIN migration_20260813_palette_viewer_photo_library_map m
    ON m.palette_viewer_photo_id = pvp.palette_viewer_photo_id
SET pvp.photo_library_id = m.new_photo_library_id
WHERE pvp.photo_library_id IS NULL
  AND m.match_method = 'saved_palette_ap_prefix';

-- Validation:
-- SELECT match_method, COUNT(*) AS backfilled_rows FROM migration_20260813_palette_viewer_photo_library_map GROUP BY match_method;
-- SELECT palette_viewer_photo_id, palette_viewer_id, rel_path
-- FROM palette_viewer_photos
-- WHERE photo_library_id IS NULL
--   AND rel_path REGEXP '/ap_[0-9]+(_[^/\\.]+)?\\.jpg$';
