START TRANSACTION;

ALTER TABLE articles
  ADD COLUMN is_retired TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN retired_at DATETIME NULL AFTER is_retired,
  ADD KEY idx_articles_is_retired (is_retired);

ALTER TABLE playlists
  ADD COLUMN is_retired TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
  ADD COLUMN retired_at DATETIME NULL AFTER is_retired,
  ADD KEY idx_playlists_is_retired (is_retired);

ALTER TABLE playlist_instances
  ADD COLUMN is_retired TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
  ADD COLUMN retired_at DATETIME NULL AFTER is_retired,
  ADD KEY idx_playlist_instances_is_retired (is_retired);

ALTER TABLE playlist_instance_sets
  ADD COLUMN is_retired TINYINT(1) NOT NULL DEFAULT 0 AFTER context,
  ADD COLUMN retired_at DATETIME NULL AFTER is_retired,
  ADD KEY idx_playlist_instance_sets_is_retired (is_retired);

ALTER TABLE photo_library
  ADD COLUMN is_retired TINYINT(1) NOT NULL DEFAULT 0 AFTER is_inactive,
  ADD COLUMN retired_at DATETIME NULL AFTER is_retired,
  ADD KEY idx_photo_library_is_retired (is_retired);

COMMIT;

-- DOWN
-- ALTER TABLE photo_library
--   DROP KEY idx_photo_library_is_retired,
--   DROP COLUMN retired_at,
--   DROP COLUMN is_retired;
--
-- ALTER TABLE playlist_instance_sets
--   DROP KEY idx_playlist_instance_sets_is_retired,
--   DROP COLUMN retired_at,
--   DROP COLUMN is_retired;
--
-- ALTER TABLE playlist_instances
--   DROP KEY idx_playlist_instances_is_retired,
--   DROP COLUMN retired_at,
--   DROP COLUMN is_retired;
--
-- ALTER TABLE playlists
--   DROP KEY idx_playlists_is_retired,
--   DROP COLUMN retired_at,
--   DROP COLUMN is_retired;
--
-- ALTER TABLE articles
--   DROP KEY idx_articles_is_retired,
--   DROP COLUMN retired_at,
--   DROP COLUMN is_retired;
