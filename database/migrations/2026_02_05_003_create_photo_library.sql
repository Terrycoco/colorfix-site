CREATE TABLE IF NOT EXISTS photo_library (
  photo_library_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_type VARCHAR(40) NOT NULL,
  source_id INT UNSIGNED NULL,
  rel_path VARCHAR(255) NOT NULL,
  title VARCHAR(255) NULL,
  tags TEXT NULL,
  alt_text TEXT NULL,
  show_in_gallery TINYINT(1) NOT NULL DEFAULT 0,
  has_palette TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (photo_library_id),
  KEY idx_photo_library_source (source_type, source_id),
  KEY idx_photo_library_gallery (show_in_gallery)
);
