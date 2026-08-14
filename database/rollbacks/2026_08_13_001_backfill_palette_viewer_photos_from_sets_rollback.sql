-- Roll back only canonical Palette Viewer photos copied by
-- 2026_08_13_001_backfill_palette_viewer_photos_from_sets.sql.
-- Legacy saved-palette set data and canonical table structures are left intact.

CREATE TABLE IF NOT EXISTS migration_20260813_palette_viewer_set_photo_map (
    palette_viewer_id BIGINT UNSIGNED NOT NULL,
    saved_palette_set_photo_id BIGINT UNSIGNED NOT NULL,
    palette_viewer_photo_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (palette_viewer_id, saved_palette_set_photo_id),
    UNIQUE KEY uq_m20260813_palette_viewer_photo_id (palette_viewer_photo_id),
    KEY idx_m20260813_saved_palette_set_photo_id (saved_palette_set_photo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE pvp
FROM palette_viewer_photos pvp
INNER JOIN migration_20260813_palette_viewer_set_photo_map m
    ON m.palette_viewer_photo_id = pvp.palette_viewer_photo_id;

DROP TABLE IF EXISTS migration_20260813_palette_viewer_set_photo_map;

-- Validation:
-- SELECT 'mapping_table_removed' AS metric, COUNT(*) AS value
-- FROM information_schema.TABLES
-- WHERE TABLE_SCHEMA = DATABASE()
--   AND TABLE_NAME = 'migration_20260813_palette_viewer_set_photo_map';
