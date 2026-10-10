-- Migrate legacy saved-palette viewer presentations into the canonical Palette Viewer tables.
-- Legacy source tables are left unchanged.

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

-- Remove stale mapped photo rows if a previous test run was manually edited.
DELETE pvp
FROM palette_viewer_photos pvp
INNER JOIN migration_20260812_saved_palette_viewer_photo_map pm
    ON pm.palette_viewer_photo_id = pvp.palette_viewer_photo_id
LEFT JOIN migration_20260812_saved_palette_viewer_map vm
    ON vm.saved_palette_viewer_content_id = pm.saved_palette_viewer_content_id
LEFT JOIN palette_viewers pv
    ON pv.palette_viewer_id = pm.palette_viewer_id
LEFT JOIN saved_palette_viewer_content vc
    ON vc.saved_palette_viewer_content_id = pm.saved_palette_viewer_content_id
LEFT JOIN saved_palette_photos spp
    ON spp.id = pm.saved_palette_photo_id
WHERE vm.saved_palette_viewer_content_id IS NULL
   OR pv.palette_viewer_id IS NULL
   OR vc.saved_palette_viewer_content_id IS NULL
   OR spp.id IS NULL;

DELETE pm
FROM migration_20260812_saved_palette_viewer_photo_map pm
LEFT JOIN migration_20260812_saved_palette_viewer_map vm
    ON vm.saved_palette_viewer_content_id = pm.saved_palette_viewer_content_id
LEFT JOIN palette_viewers pv
    ON pv.palette_viewer_id = pm.palette_viewer_id
LEFT JOIN saved_palette_viewer_content vc
    ON vc.saved_palette_viewer_content_id = pm.saved_palette_viewer_content_id
LEFT JOIN saved_palette_photos spp
    ON spp.id = pm.saved_palette_photo_id
WHERE vm.saved_palette_viewer_content_id IS NULL
   OR pv.palette_viewer_id IS NULL
   OR vc.saved_palette_viewer_content_id IS NULL
   OR spp.id IS NULL;

DELETE pv
FROM palette_viewers pv
INNER JOIN migration_20260812_saved_palette_viewer_map vm
    ON vm.palette_viewer_id = pv.palette_viewer_id
LEFT JOIN saved_palette_viewer_content vc
    ON vc.saved_palette_viewer_content_id = vm.saved_palette_viewer_content_id
WHERE vc.saved_palette_viewer_content_id IS NULL;

DELETE vm
FROM migration_20260812_saved_palette_viewer_map vm
LEFT JOIN palette_viewers pv
    ON pv.palette_viewer_id = vm.palette_viewer_id
LEFT JOIN saved_palette_viewer_content vc
    ON vc.saved_palette_viewer_content_id = vm.saved_palette_viewer_content_id
WHERE pv.palette_viewer_id IS NULL
   OR vc.saved_palette_viewer_content_id IS NULL;

-- Assign deterministic target IDs for any legacy viewer-content rows not yet mapped.
SET @next_palette_viewer_id := (SELECT COALESCE(MAX(palette_viewer_id), 0) FROM palette_viewers);

INSERT INTO migration_20260812_saved_palette_viewer_map (
    saved_palette_viewer_content_id,
    palette_viewer_id
)
SELECT
    vc.saved_palette_viewer_content_id,
    (@next_palette_viewer_id := @next_palette_viewer_id + 1) AS palette_viewer_id
FROM saved_palette_viewer_content vc
INNER JOIN (
    SELECT
        saved_palette_id,
        MAX(saved_palette_viewer_content_id) AS chosen_content_id
    FROM saved_palette_viewer_content
    GROUP BY saved_palette_id
) chosen
    ON chosen.chosen_content_id = vc.saved_palette_viewer_content_id
LEFT JOIN migration_20260812_saved_palette_viewer_map vm
    ON vm.saved_palette_viewer_content_id = vc.saved_palette_viewer_content_id
WHERE vm.saved_palette_viewer_content_id IS NULL
ORDER BY vc.saved_palette_viewer_content_id;


INSERT INTO palette_viewers (
    palette_viewer_id,
    saved_palette_id,
    format,
    template_key,
    kicker_text,
    title,
    intro,
    notes,
    cta_label,
    is_active,
    created_at,
    updated_at
)
SELECT
    vm.palette_viewer_id,
    vc.saved_palette_id,
    'public' AS format,
    NULLIF(TRIM(vc.template_key), '') AS template_key,
    NULLIF(TRIM(vc.kicker_text), '') AS kicker_text,
    NULLIF(TRIM(vc.title), '') AS title,
    NULLIF(vc.intro, '') AS intro,
    NULLIF(vc.notes, '') AS notes,
    NULLIF(TRIM(vc.cta_label), '') AS cta_label,
    vc.is_active,
    vc.created_at,
    vc.updated_at
FROM migration_20260812_saved_palette_viewer_map vm
INNER JOIN saved_palette_viewer_content vc
    ON vc.saved_palette_viewer_content_id = vm.saved_palette_viewer_content_id
LEFT JOIN palette_viewers pv
    ON pv.palette_viewer_id = vm.palette_viewer_id
WHERE pv.palette_viewer_id IS NULL;

-- Keep mapped canonical rows aligned if this migration is rerun after source edits.
UPDATE palette_viewers pv
INNER JOIN migration_20260812_saved_palette_viewer_map vm
    ON vm.palette_viewer_id = pv.palette_viewer_id
INNER JOIN saved_palette_viewer_content vc
    ON vc.saved_palette_viewer_content_id = vm.saved_palette_viewer_content_id
SET
    pv.saved_palette_id = vc.saved_palette_id,
    pv.format = 'public',
    pv.template_key = NULLIF(TRIM(vc.template_key), ''),
    pv.kicker_text = NULLIF(TRIM(vc.kicker_text), ''),
    pv.title = NULLIF(TRIM(vc.title), ''),
    pv.intro = NULLIF(vc.intro, ''),
    pv.notes = NULLIF(vc.notes, ''),
    pv.cta_label = NULLIF(TRIM(vc.cta_label), ''),
    pv.is_active = vc.is_active,
    pv.created_at = vc.created_at,
    pv.updated_at = vc.updated_at;

-- Assign deterministic photo target IDs for each legacy presentation/photo copy.
SET @next_palette_viewer_photo_id := (SELECT COALESCE(MAX(palette_viewer_photo_id), 0) FROM palette_viewer_photos);

INSERT INTO migration_20260812_saved_palette_viewer_photo_map (
    saved_palette_viewer_content_id,
    saved_palette_photo_id,
    palette_viewer_id,
    palette_viewer_photo_id
)
SELECT
    vm.saved_palette_viewer_content_id,
    spp.id AS saved_palette_photo_id,
    vm.palette_viewer_id,
    (@next_palette_viewer_photo_id := @next_palette_viewer_photo_id + 1) AS palette_viewer_photo_id
FROM migration_20260812_saved_palette_viewer_map vm
INNER JOIN saved_palette_viewer_content vc
    ON vc.saved_palette_viewer_content_id = vm.saved_palette_viewer_content_id
INNER JOIN saved_palette_photos spp
    ON spp.saved_palette_id = vc.saved_palette_id
LEFT JOIN migration_20260812_saved_palette_viewer_photo_map pm
    ON pm.saved_palette_viewer_content_id = vm.saved_palette_viewer_content_id
   AND pm.saved_palette_photo_id = spp.id
WHERE pm.saved_palette_photo_id IS NULL
ORDER BY vm.saved_palette_viewer_content_id, spp.order_index, spp.id;

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
    pm.palette_viewer_photo_id,
    pm.palette_viewer_id,
    spp.photo_library_id,
    NULLIF(TRIM(spp.rel_path), '') AS rel_path,
    COALESCE(NULLIF(TRIM(spp.photo_type), ''), 'full') AS photo_type,
    COALESCE(NULLIF(TRIM(spp.trigger_mode), ''), 'any') AS trigger_mode,
    spp.trigger_color_id,
    NULLIF(TRIM(spp.caption), '') AS caption,
    NULLIF(spp.alt_text, '') AS alt_text,
    spp.order_index,
    spp.created_at,
    NULL AS updated_at
FROM migration_20260812_saved_palette_viewer_photo_map pm
INNER JOIN saved_palette_photos spp
    ON spp.id = pm.saved_palette_photo_id
LEFT JOIN palette_viewer_photos pvp
    ON pvp.palette_viewer_photo_id = pm.palette_viewer_photo_id
WHERE pvp.palette_viewer_photo_id IS NULL;

-- Keep mapped canonical photo rows aligned if this migration is rerun after source edits.
UPDATE palette_viewer_photos pvp
INNER JOIN migration_20260812_saved_palette_viewer_photo_map pm
    ON pm.palette_viewer_photo_id = pvp.palette_viewer_photo_id
INNER JOIN saved_palette_photos spp
    ON spp.id = pm.saved_palette_photo_id
SET
    pvp.palette_viewer_id = pm.palette_viewer_id,
    pvp.photo_library_id = spp.photo_library_id,
    pvp.rel_path = NULLIF(TRIM(spp.rel_path), ''),
    pvp.photo_type = COALESCE(NULLIF(TRIM(spp.photo_type), ''), 'full'),
    pvp.trigger_mode = COALESCE(NULLIF(TRIM(spp.trigger_mode), ''), 'any'),
    pvp.trigger_color_id = spp.trigger_color_id,
    pvp.caption = NULLIF(TRIM(spp.caption), ''),
    pvp.alt_text = NULLIF(spp.alt_text, ''),
    pvp.order_index = spp.order_index,
    pvp.created_at = spp.created_at,
    pvp.updated_at = NULL;

-- Validation/reporting queries for manual inspection after running this migration:
-- SELECT 'legacy_viewer_content_rows' AS metric, COUNT(*) AS value FROM saved_palette_viewer_content;
-- SELECT 'canonical_viewers_created_by_migration' AS metric, COUNT(*) AS value FROM migration_20260812_saved_palette_viewer_map vm INNER JOIN palette_viewers pv ON pv.palette_viewer_id = vm.palette_viewer_id;
-- SELECT 'legacy_saved_palette_photos_rows_eligible_distinct' AS metric, COUNT(DISTINCT spp.id) AS value FROM saved_palette_photos spp WHERE EXISTS (SELECT 1 FROM saved_palette_viewer_content vc WHERE vc.saved_palette_id = spp.saved_palette_id);
-- SELECT 'expected_canonical_viewer_photo_copies' AS metric, COUNT(*) AS value FROM saved_palette_viewer_content vc INNER JOIN saved_palette_photos spp ON spp.saved_palette_id = vc.saved_palette_id;
-- SELECT 'canonical_viewer_photos_created_by_migration' AS metric, COUNT(*) AS value FROM migration_20260812_saved_palette_viewer_photo_map pm INNER JOIN palette_viewer_photos pvp ON pvp.palette_viewer_photo_id = pm.palette_viewer_photo_id;
-- SELECT vc.saved_palette_id, COUNT(DISTINCT vc.saved_palette_viewer_content_id) AS viewer_content_rows FROM saved_palette_viewer_content vc LEFT JOIN saved_palette_photos spp ON spp.saved_palette_id = vc.saved_palette_id WHERE spp.id IS NULL GROUP BY vc.saved_palette_id ORDER BY vc.saved_palette_id;
-- SELECT spp.id AS saved_palette_photo_id, spp.saved_palette_id FROM saved_palette_photos spp LEFT JOIN saved_palette_viewer_content vc ON vc.saved_palette_id = spp.saved_palette_id LEFT JOIN migration_20260812_saved_palette_viewer_map vm ON vm.saved_palette_viewer_content_id = vc.saved_palette_viewer_content_id WHERE vm.saved_palette_viewer_content_id IS NULL ORDER BY spp.saved_palette_id, spp.order_index, spp.id;
-- SELECT saved_palette_viewer_content_id, COUNT(*) AS mapping_count FROM migration_20260812_saved_palette_viewer_map GROUP BY saved_palette_viewer_content_id HAVING COUNT(*) > 1;
-- SELECT palette_viewer_id, COUNT(*) AS mapping_count FROM migration_20260812_saved_palette_viewer_map GROUP BY palette_viewer_id HAVING COUNT(*) > 1;
-- SELECT 'orphaned_palette_viewer_photos' AS metric, COUNT(*) AS value FROM palette_viewer_photos pvp LEFT JOIN palette_viewers pv ON pv.palette_viewer_id = pvp.palette_viewer_id WHERE pv.palette_viewer_id IS NULL;
