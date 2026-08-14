-- Restore rel_path values cleared by
-- 2026_08_13_007_clear_palette_viewer_photo_paths_with_library_id.sql.

CREATE TABLE IF NOT EXISTS migration_20260813_palette_viewer_photo_rel_path_clears (
    palette_viewer_photo_id BIGINT UNSIGNED NOT NULL,
    photo_library_id INT UNSIGNED NOT NULL,
    previous_rel_path VARCHAR(1024) NOT NULL,
    clear_key VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (palette_viewer_photo_id, clear_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE palette_viewer_photos pvp
INNER JOIN migration_20260813_palette_viewer_photo_rel_path_clears m
    ON m.palette_viewer_photo_id = pvp.palette_viewer_photo_id
SET pvp.rel_path = m.previous_rel_path,
    pvp.updated_at = NOW()
WHERE m.clear_key = 'photo_library_source_of_truth'
  AND pvp.photo_library_id = m.photo_library_id
  AND (pvp.rel_path IS NULL OR pvp.rel_path = '');

DELETE FROM migration_20260813_palette_viewer_photo_rel_path_clears
WHERE clear_key = 'photo_library_source_of_truth';
