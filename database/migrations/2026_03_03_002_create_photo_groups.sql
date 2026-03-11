CREATE TABLE IF NOT EXISTS photo_groups (
  group_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id),
  UNIQUE KEY uniq_photo_groups_title (title)
);

CREATE TABLE IF NOT EXISTS photo_group_items (
  group_id INT UNSIGNED NOT NULL,
  photo_library_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id, photo_library_id),
  KEY idx_photo_group_items_photo (photo_library_id),
  CONSTRAINT fk_photo_group_items_group
    FOREIGN KEY (group_id) REFERENCES photo_groups(group_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_photo_group_items_photo
    FOREIGN KEY (photo_library_id) REFERENCES photo_library(photo_library_id)
    ON DELETE CASCADE
);
