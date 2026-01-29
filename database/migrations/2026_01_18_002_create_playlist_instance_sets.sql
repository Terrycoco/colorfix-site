CREATE TABLE playlist_instance_sets (
  id INT NOT NULL AUTO_INCREMENT,
  handle VARCHAR(255) NOT NULL,
  title VARCHAR(255) NOT NULL,
  context VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_playlist_instance_sets_handle (handle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE playlist_instance_set_items (
  id INT NOT NULL AUTO_INCREMENT,
  playlist_instance_set_id INT NOT NULL,
  playlist_instance_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  photo_url VARCHAR(512) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_playlist_instance_set_items_set_sort (playlist_instance_set_id, sort_order),
  CONSTRAINT fk_playlist_instance_set_items_set
    FOREIGN KEY (playlist_instance_set_id) REFERENCES playlist_instance_sets(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_playlist_instance_set_items_instance
    FOREIGN KEY (playlist_instance_id) REFERENCES playlist_instances(playlist_instance_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- DOWN
-- DROP TABLE IF EXISTS playlist_instance_set_items;
-- DROP TABLE IF EXISTS playlist_instance_sets;
