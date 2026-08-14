-- Enforce Photo Library as the single source of truth for canonical
-- Palette Viewer photos. When a palette_viewer_photos row is linked to
-- photo_library, its own rel_path is duplicate legacy data and must not be
-- used as the source of the image.

CREATE TABLE IF NOT EXISTS migration_20260813_palette_viewer_photo_rel_path_clears (
    palette_viewer_photo_id BIGINT UNSIGNED NOT NULL,
    photo_library_id INT UNSIGNED NOT NULL,
    previous_rel_path VARCHAR(1024) NOT NULL,
    clear_key VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (palette_viewer_photo_id, clear_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migration_20260813_palette_viewer_photo_rel_path_clears (
    palette_viewer_photo_id,
    photo_library_id,
    previous_rel_path,
    clear_key
)
SELECT
    pvp.palette_viewer_photo_id,
    pvp.photo_library_id,
    pvp.rel_path,
    'photo_library_source_of_truth'
FROM palette_viewer_photos pvp
WHERE pvp.photo_library_id IS NOT NULL
  AND pvp.photo_library_id > 0
  AND pvp.rel_path IS NOT NULL
  AND pvp.rel_path <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM migration_20260813_palette_viewer_photo_rel_path_clears existing
      WHERE existing.palette_viewer_photo_id = pvp.palette_viewer_photo_id
        AND existing.clear_key = 'photo_library_source_of_truth'
  );

UPDATE palette_viewer_photos pvp
INNER JOIN migration_20260813_palette_viewer_photo_rel_path_clears m
    ON m.palette_viewer_photo_id = pvp.palette_viewer_photo_id
SET pvp.rel_path = NULL,
    pvp.updated_at = NOW()
WHERE m.clear_key = 'photo_library_source_of_truth'
  AND pvp.photo_library_id = m.photo_library_id
  AND pvp.rel_path = CONVERT(m.previous_rel_path USING utf8mb4) COLLATE utf8mb4_general_ci;

-- Validation:
-- SELECT COUNT(*) AS rows_cleared
-- FROM migration_20260813_palette_viewer_photo_rel_path_clears
-- WHERE clear_key = 'photo_library_source_of_truth';
--
-- SELECT COUNT(*) AS remaining_duplicate_paths
-- FROM palette_viewer_photos
-- WHERE photo_library_id IS NOT NULL
--   AND photo_library_id > 0
--   AND rel_path IS NOT NULL
--   AND rel_path <> '';
