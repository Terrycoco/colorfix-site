-- Roll back the saved-palette viewer canonical data migration.
-- Legacy source tables and canonical table structures are left unchanged.

CREATE TABLE IF NOT EXISTS migration_20260812_saved_palette_viewer_map (
    saved_palette_viewer_content_id BIGINT UNSIGNED NOT NULL,
    palette_viewer_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (saved_palette_viewer_content_id),
    UNIQUE KEY uq_m20260812_palette_viewer_id (palette_viewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS migration_20260812_saved_palette_viewer_photo_map (
    saved_palette_viewer_content_id BIGINT UNSIGNED NOT NULL,
    saved_palette_photo_id BIGINT UNSIGNED NOT NULL,
    palette_viewer_id BIGINT UNSIGNED NOT NULL,
    palette_viewer_photo_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (saved_palette_viewer_content_id, saved_palette_photo_id),
    UNIQUE KEY uq_m20260812_palette_viewer_photo_id (palette_viewer_photo_id),
    KEY idx_m20260812_photo_viewer_id (palette_viewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE pvp
FROM palette_viewer_photos pvp
INNER JOIN migration_20260812_saved_palette_viewer_photo_map pm
    ON pm.palette_viewer_photo_id = pvp.palette_viewer_photo_id;

DELETE pv
FROM palette_viewers pv
INNER JOIN migration_20260812_saved_palette_viewer_map vm
    ON vm.palette_viewer_id = pv.palette_viewer_id;

DROP TABLE IF EXISTS migration_20260812_saved_palette_viewer_photo_map;
DROP TABLE IF EXISTS migration_20260812_saved_palette_viewer_map;

-- Validation/reporting queries for manual inspection before/after rollback:
-- SELECT 'canonical_viewer_photos_to_delete' AS metric, COUNT(*) AS value FROM migration_20260812_saved_palette_viewer_photo_map pm INNER JOIN palette_viewer_photos pvp ON pvp.palette_viewer_photo_id = pm.palette_viewer_photo_id;
-- SELECT 'canonical_viewers_to_delete' AS metric, COUNT(*) AS value FROM migration_20260812_saved_palette_viewer_map vm INNER JOIN palette_viewers pv ON pv.palette_viewer_id = vm.palette_viewer_id;
-- SELECT 'legacy_viewer_content_rows_remaining' AS metric, COUNT(*) AS value FROM saved_palette_viewer_content;
-- SELECT 'legacy_saved_palette_photos_rows_remaining' AS metric, COUNT(*) AS value FROM saved_palette_photos;
