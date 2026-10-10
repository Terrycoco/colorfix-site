ALTER TABLE playlist_instances
  ADD COLUMN kicker_id INT UNSIGNED NULL,
  ADD INDEX idx_playlist_instances_kicker_id (kicker_id),
  ADD CONSTRAINT fk_playlist_instances_kicker
    FOREIGN KEY (kicker_id) REFERENCES kickers(kicker_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;
