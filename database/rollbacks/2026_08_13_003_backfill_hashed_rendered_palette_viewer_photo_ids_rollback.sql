-- Roll back only photo_library_id values filled by
-- 2026_08_13_003_backfill_hashed_rendered_palette_viewer_photo_ids.sql.

CREATE TABLE IF NOT EXISTS migration_20260813_palette_viewer_photo_library_map (
    palette_viewer_photo_id BIGINT UNSIGNED NOT NULL,
    previous_photo_library_id INT UNSIGNED NULL,
    new_photo_library_id INT UNSIGNED NOT NULL,
    match_method VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (palette_viewer_photo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE palette_viewer_photos pvp
INNER JOIN migration_20260813_palette_viewer_photo_library_map m
    ON m.palette_viewer_photo_id = pvp.palette_viewer_photo_id
SET pvp.photo_library_id = m.previous_photo_library_id
WHERE pvp.photo_library_id = m.new_photo_library_id
  AND m.match_method = 'rendered_ap_hashed_source';

DELETE FROM migration_20260813_palette_viewer_photo_library_map
WHERE match_method = 'rendered_ap_hashed_source';
