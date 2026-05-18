ALTER TABLE playlist_instance_set_items
  ADD COLUMN playlist_id INT NULL AFTER playlist_instance_id,
  ADD KEY idx_playlist_instance_set_items_playlist (playlist_id),
  ADD CONSTRAINT fk_playlist_instance_set_items_playlist
    FOREIGN KEY (playlist_id) REFERENCES playlists(playlist_id)
    ON DELETE CASCADE;

UPDATE playlist_instance_set_items psi
JOIN playlist_instances pi
  ON pi.playlist_instance_id = psi.playlist_instance_id
SET psi.playlist_id = pi.playlist_id
WHERE psi.playlist_id IS NULL
  AND psi.playlist_instance_id IS NOT NULL;

UPDATE playlist_instance_set_items
SET item_type = 'playlist'
WHERE item_type = 'instance'
  AND playlist_id IS NOT NULL;

-- DOWN
-- ALTER TABLE playlist_instance_set_items
--   DROP FOREIGN KEY fk_playlist_instance_set_items_playlist,
--   DROP KEY idx_playlist_instance_set_items_playlist,
--   DROP COLUMN playlist_id;
