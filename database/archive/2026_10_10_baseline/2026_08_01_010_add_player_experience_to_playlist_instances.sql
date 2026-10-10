ALTER TABLE playlist_instances
  ADD COLUMN player_experience_id INT UNSIGNED NULL AFTER audience,
  ADD KEY idx_playlist_instances_player_experience_id (player_experience_id),
  ADD CONSTRAINT fk_playlist_instances_player_experience
    FOREIGN KEY (player_experience_id)
    REFERENCES player_experiences (player_experience_id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT;
