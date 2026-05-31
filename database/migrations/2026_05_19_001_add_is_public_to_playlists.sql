ALTER TABLE playlists
  ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
  ADD KEY idx_playlists_is_public (is_public);

UPDATE playlists
SET is_public = 1
WHERE is_active = 1
  AND type IN ('normal', 'palettes', 'teaching')
  AND title NOT LIKE 'HOA%';
