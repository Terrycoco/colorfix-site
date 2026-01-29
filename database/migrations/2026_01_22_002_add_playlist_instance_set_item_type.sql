ALTER TABLE playlist_instance_set_items
  DROP FOREIGN KEY fk_playlist_instance_set_items_instance,
  MODIFY COLUMN playlist_instance_id INT NULL,
  ADD COLUMN item_type VARCHAR(20) NOT NULL DEFAULT 'instance' AFTER playlist_instance_id,
  ADD COLUMN target_set_id INT NULL AFTER item_type,
  ADD CONSTRAINT fk_playlist_instance_set_items_target_set
    FOREIGN KEY (target_set_id) REFERENCES playlist_instance_sets(id)
    ON DELETE CASCADE,
  ADD CONSTRAINT fk_playlist_instance_set_items_instance
    FOREIGN KEY (playlist_instance_id) REFERENCES playlist_instances(playlist_instance_id)
    ON DELETE CASCADE;

-- Down
ALTER TABLE playlist_instance_set_items
  DROP FOREIGN KEY fk_playlist_instance_set_items_target_set,
  DROP FOREIGN KEY fk_playlist_instance_set_items_instance,
  DROP COLUMN target_set_id,
  DROP COLUMN item_type,
  MODIFY COLUMN playlist_instance_id INT NOT NULL,
  ADD CONSTRAINT fk_playlist_instance_set_items_instance
    FOREIGN KEY (playlist_instance_id) REFERENCES playlist_instances(playlist_instance_id)
    ON DELETE CASCADE;
