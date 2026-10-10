-- Backfill canonical Palette Viewer photos from legacy saved-palette sets.
--
-- The original canonical migration intentionally copied saved_palette_photos only.
-- Production saved palettes created through the newer setup often store their
-- viewer photos in saved_palette_set_photos instead, so migrated Palette Viewers
-- can exist with zero canonical palette_viewer_photos even though legacy set
-- photos exist.
--
-- This migration copies set photos only for canonical Palette Viewers that
-- currently have no canonical photos. Legacy data is left unchanged.

CREATE TABLE IF NOT EXISTS migration_20260813_palette_viewer_set_photo_map (
    palette_viewer_id BIGINT UNSIGNED NOT NULL,
    saved_palette_set_photo_id BIGINT UNSIGNED NOT NULL,
    palette_viewer_photo_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (palette_viewer_id, saved_palette_set_photo_id),
    UNIQUE KEY uq_m20260813_palette_viewer_photo_id (palette_viewer_photo_id),
    KEY idx_m20260813_saved_palette_set_photo_id (saved_palette_set_photo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remove stale mappings whose target/source rows no longer exist.
DELETE pvp
FROM palette_viewer_photos pvp
INNER JOIN migration_20260813_palette_viewer_set_photo_map m
    ON m.palette_viewer_photo_id = pvp.palette_viewer_photo_id
LEFT JOIN palette_viewers pv
    ON pv.palette_viewer_id = m.palette_viewer_id
LEFT JOIN saved_palette_set_photos spsp
    ON spsp.id = m.saved_palette_set_photo_id
WHERE pv.palette_viewer_id IS NULL
   OR spsp.id IS NULL;

DELETE m
FROM migration_20260813_palette_viewer_set_photo_map m
LEFT JOIN palette_viewer_photos pvp
    ON pvp.palette_viewer_photo_id = m.palette_viewer_photo_id
LEFT JOIN palette_viewers pv
    ON pv.palette_viewer_id = m.palette_viewer_id
LEFT JOIN saved_palette_set_photos spsp
    ON spsp.id = m.saved_palette_set_photo_id
WHERE pvp.palette_viewer_photo_id IS NULL
   OR pv.palette_viewer_id IS NULL
   OR spsp.id IS NULL;

SET @next_palette_viewer_photo_id := (SELECT COALESCE(MAX(palette_viewer_photo_id), 0) FROM palette_viewer_photos);

INSERT INTO migration_20260813_palette_viewer_set_photo_map (
    palette_viewer_id,
    saved_palette_set_photo_id,
    palette_viewer_photo_id
)
SELECT
    pv.palette_viewer_id,
    spsp.id AS saved_palette_set_photo_id,
    (@next_palette_viewer_photo_id := @next_palette_viewer_photo_id + 1) AS palette_viewer_photo_id
FROM palette_viewers pv
INNER JOIN saved_palette_sets sps
    ON sps.saved_palette_id = pv.saved_palette_id
INNER JOIN saved_palette_set_photos spsp
    ON spsp.saved_palette_set_id = sps.id
LEFT JOIN palette_viewer_photos existing
    ON existing.palette_viewer_id = pv.palette_viewer_id
LEFT JOIN migration_20260813_palette_viewer_set_photo_map m
    ON m.palette_viewer_id = pv.palette_viewer_id
   AND m.saved_palette_set_photo_id = spsp.id
WHERE existing.palette_viewer_photo_id IS NULL
  AND m.saved_palette_set_photo_id IS NULL
ORDER BY pv.palette_viewer_id, sps.order_index, sps.id, spsp.order_index, spsp.id;

INSERT INTO palette_viewer_photos (
    palette_viewer_photo_id,
    palette_viewer_id,
    photo_library_id,
    rel_path,
    photo_type,
    trigger_mode,
    trigger_color_id,
    caption,
    alt_text,
    order_index,
    created_at,
    updated_at
)
SELECT
    m.palette_viewer_photo_id,
    m.palette_viewer_id,
    spsp.photo_library_id,
    NULLIF(TRIM(spsp.rel_path), '') AS rel_path,
    COALESCE(NULLIF(TRIM(spsp.photo_type), ''), 'full') AS photo_type,
    COALESCE(NULLIF(TRIM(spsp.trigger_mode), ''), 'any') AS trigger_mode,
    spsp.trigger_color_id,
    NULLIF(TRIM(spsp.caption), '') AS caption,
    NULLIF(spsp.alt_text, '') AS alt_text,
    spsp.order_index,
    spsp.created_at,
    spsp.updated_at
FROM migration_20260813_palette_viewer_set_photo_map m
INNER JOIN saved_palette_set_photos spsp
    ON spsp.id = m.saved_palette_set_photo_id
LEFT JOIN palette_viewer_photos pvp
    ON pvp.palette_viewer_photo_id = m.palette_viewer_photo_id
WHERE pvp.palette_viewer_photo_id IS NULL;

-- Validation:
-- SELECT 'set_photo_backfill_mappings' AS metric, COUNT(*) AS value FROM migration_20260813_palette_viewer_set_photo_map;
-- SELECT 'set_photo_backfilled_rows' AS metric, COUNT(*) AS value FROM palette_viewer_photos pvp INNER JOIN migration_20260813_palette_viewer_set_photo_map m ON m.palette_viewer_photo_id = pvp.palette_viewer_photo_id;
-- SELECT pv.palette_viewer_id, pv.saved_palette_id, sp.nickname, COUNT(spsp.id) AS legacy_set_photos, COUNT(pvp.palette_viewer_photo_id) AS canonical_photos
-- FROM palette_viewers pv
-- LEFT JOIN saved_palettes sp ON sp.id = pv.saved_palette_id
-- LEFT JOIN saved_palette_sets sps ON sps.saved_palette_id = pv.saved_palette_id
-- LEFT JOIN saved_palette_set_photos spsp ON spsp.saved_palette_set_id = sps.id
-- LEFT JOIN palette_viewer_photos pvp ON pvp.palette_viewer_id = pv.palette_viewer_id
-- GROUP BY pv.palette_viewer_id, pv.saved_palette_id, sp.nickname
-- HAVING legacy_set_photos > 0 AND canonical_photos = 0;
