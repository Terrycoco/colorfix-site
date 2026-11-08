-- PHOTOS PHASE 2: REVIEW ONLY. No destructive statements in this file are active.
-- Do not run DDL until deployment verification, backups, and explicit owner approval.
-- This proposal does NOT delete Palette 133, change Adams Makeover, or touch photographs.

-- A. Repeat these READ-ONLY checks immediately before an approved retirement.
SELECT DATABASE() AS database_name, NOW() AS database_time,
       @@session.time_zone AS session_timezone, @@global.time_zone AS global_timezone;

SELECT id, nickname, is_public FROM saved_palettes WHERE id IN (133, 135, 154);
SELECT playlist_id, title, is_active, is_public, indexable, is_retired
FROM playlists WHERE playlist_id = 43;
SELECT playlist_item_id, playlist_id, title, palette_hash, saved_palette_id,
       saved_palette_set_id FROM playlist_items WHERE playlist_item_id = 541;
SELECT pi.playlist_item_id, pi.playlist_id, pi.title, pi.item_type
FROM playlist_items pi JOIN saved_palettes sp ON sp.palette_hash = pi.palette_hash
WHERE sp.id = 133;
SELECT playlist_item_id, playlist_id FROM playlist_items WHERE saved_palette_id = 133;

-- All incoming FKs to proposed retired tables must remain inside this set.
-- Never disable FOREIGN_KEY_CHECKS to force this through.
SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME,
       k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
FROM information_schema.KEY_COLUMN_USAGE k
JOIN information_schema.REFERENTIAL_CONSTRAINTS r
  ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
 AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME
WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IN
 ('render_project_masks','render_project_roles','render_projects','photos_tags',
  'photos_variants','photos_mask_stats','mask_blend_settings','master_role_masks',
  'saved_palette_viewer_content','saved_palette_set_photos','saved_palette_sets',
  'saved_palette_photos','tester_colors','photos')
ORDER BY k.REFERENCED_TABLE_NAME, k.TABLE_NAME;

SELECT TABLE_NAME, VIEW_DEFINITION FROM information_schema.VIEWS
WHERE TABLE_SCHEMA=DATABASE();
SHOW EVENTS;

-- Review the five old set rows without PL IDs. Do not recreate their retired assets.
SELECT s.* FROM saved_palette_set_photos s
LEFT JOIN photo_library p ON p.photo_library_id=s.photo_library_id
WHERE p.photo_library_id IS NULL;

-- Current direct Main associations are authoritative; do not overwrite these seven.
SELECT s.saved_palette_set_id, s.photo_library_id, p.palette_id AS current_palette_id,
       ps.saved_palette_id AS legacy_palette_id
FROM saved_palette_set_photos s
JOIN saved_palette_sets ps ON ps.id=s.saved_palette_set_id
JOIN photo_library p ON p.photo_library_id=s.photo_library_id
WHERE s.photo_type IN ('full','after')
  AND COALESCE(p.palette_id,0)<>ps.saved_palette_id;

-- The only unmatched active old content rows at audit time were 62 and 131.
-- Owner confirmed these obsolete #133/#154 presentations need no migration.
-- If NEW rows appear here, stop for review instead of automatically migrating them.
SELECT v.saved_palette_viewer_content_id, v.saved_palette_id, v.template_key
FROM saved_palette_viewer_content v
WHERE v.is_active=1 AND NOT EXISTS (
 SELECT 1 FROM palette_viewers pv
 WHERE pv.saved_palette_id=v.saved_palette_id AND pv.is_active=1
 AND pv.format=CASE WHEN v.template_key='full_palette' THEN 'public' ELSE v.template_key END
);

-- B. EXACT DDL PROPOSAL, DISABLED PENDING SEPARATE APPROVAL.
-- MySQL/MariaDB DDL implicitly commits: START TRANSACTION is NOT a rollback plan.
-- Take tested schema/data/view backups first. Drop children before parents.
-- DROP VIEW IF EXISTS vw_master_mask_blend;
-- DROP VIEW IF EXISTS vw_scheme_mask_blend_settings;
-- DROP TABLE IF EXISTS render_project_masks;
-- DROP TABLE IF EXISTS render_project_roles;
-- DROP TABLE IF EXISTS render_projects;
-- DROP TABLE IF EXISTS photos_tags;
-- DROP TABLE IF EXISTS photos_variants;
-- DROP TABLE IF EXISTS photos_mask_stats;
-- DROP TABLE IF EXISTS mask_blend_settings;
-- DROP TABLE IF EXISTS master_role_masks;
-- DROP TABLE IF EXISTS tester_colors;
-- DROP TABLE IF EXISTS saved_palette_viewer_content;
-- DROP TABLE IF EXISTS saved_palette_set_photos;
-- DROP TABLE IF EXISTS saved_palette_sets;
-- DROP TABLE IF EXISTS saved_palette_photos;
-- DROP TABLE IF EXISTS photos;

-- C. OPTIONAL LATER COLUMN RETIREMENT, AFTER BACKUP AND ALL CONSUMER CHECKS.
-- Keep saved_palette_id: current PV/playlist operations use it.
-- Keep NULL/empty compatibility fields in response DTOs where contracts require them.
-- ALTER TABLE playlist_items DROP COLUMN ap_id;
-- ALTER TABLE playlist_items DROP COLUMN saved_palette_set_id;

-- NEVER retire photo_library, photos_artwork, saved_palettes, saved_palette_members,
-- palette_viewers, palette_viewer_photos, palette_viewer_project_palettes,
-- projects/project_palettes/project_photos, current Playlist Sets/Instance Sets,
-- PUB orders/jobs/assets/render recipes, master_roles, colors, materialized swatch views,
-- vw_photo_palette_colors, or vw_gallery as part of this proposal.
-- Applied Palette tables/HOA mask maps already absent are not recreated or guessed at.
