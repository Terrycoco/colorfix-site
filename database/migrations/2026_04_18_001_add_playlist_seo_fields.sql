SET @schema := DATABASE();

SET @has_slug := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'playlists'
    AND COLUMN_NAME = 'slug'
);
SET @sql := IF(
  @has_slug = 0,
  'ALTER TABLE playlists ADD COLUMN slug VARCHAR(255) NULL AFTER title',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_headline := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'playlists'
    AND COLUMN_NAME = 'headline'
);
SET @sql := IF(
  @has_headline = 0,
  'ALTER TABLE playlists ADD COLUMN headline VARCHAR(255) NULL AFTER slug',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_page_title := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'playlists'
    AND COLUMN_NAME = 'page_title'
);
SET @sql := IF(
  @has_page_title = 0,
  'ALTER TABLE playlists ADD COLUMN page_title VARCHAR(255) NULL AFTER headline',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_meta_description := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'playlists'
    AND COLUMN_NAME = 'meta_description'
);
SET @sql := IF(
  @has_meta_description = 0,
  'ALTER TABLE playlists ADD COLUMN meta_description TEXT NULL AFTER page_title',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_dek := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'playlists'
    AND COLUMN_NAME = 'dek'
);
SET @sql := IF(
  @has_dek = 0,
  'ALTER TABLE playlists ADD COLUMN dek TEXT NULL AFTER meta_description',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_intro_html := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'playlists'
    AND COLUMN_NAME = 'intro_html'
);
SET @sql := IF(
  @has_intro_html = 0,
  'ALTER TABLE playlists ADD COLUMN intro_html MEDIUMTEXT NULL AFTER dek',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_body_html := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'playlists'
    AND COLUMN_NAME = 'body_html'
);
SET @sql := IF(
  @has_body_html = 0,
  'ALTER TABLE playlists ADD COLUMN body_html MEDIUMTEXT NULL AFTER intro_html',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_hero_image_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'playlists'
    AND COLUMN_NAME = 'hero_image_id'
);
SET @sql := IF(
  @has_hero_image_id = 0,
  'ALTER TABLE playlists ADD COLUMN hero_image_id BIGINT NULL AFTER body_html',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_hero_image_url := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'playlists'
    AND COLUMN_NAME = 'hero_image_url'
);
SET @sql := IF(
  @has_hero_image_url = 0,
  'ALTER TABLE playlists ADD COLUMN hero_image_url VARCHAR(500) NULL AFTER hero_image_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_hero_alt := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'playlists'
    AND COLUMN_NAME = 'hero_alt'
);
SET @sql := IF(
  @has_hero_alt = 0,
  'ALTER TABLE playlists ADD COLUMN hero_alt VARCHAR(255) NULL AFTER hero_image_url',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_indexable := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'playlists'
    AND COLUMN_NAME = 'indexable'
);
SET @sql := IF(
  @has_indexable = 0,
  'ALTER TABLE playlists ADD COLUMN indexable TINYINT(1) NOT NULL DEFAULT 1 AFTER hero_alt',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_published_at := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'playlists'
    AND COLUMN_NAME = 'published_at'
);
SET @sql := IF(
  @has_published_at = 0,
  'ALTER TABLE playlists ADD COLUMN published_at DATETIME NULL AFTER indexable',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_updated_at := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'playlists'
    AND COLUMN_NAME = 'updated_at'
);
SET @sql := IF(
  @has_updated_at = 0,
  'ALTER TABLE playlists ADD COLUMN updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER published_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_slug_unique := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'playlists'
    AND INDEX_NAME = 'uniq_playlists_slug'
);
SET @sql := IF(
  @has_slug_unique = 0,
  'ALTER TABLE playlists ADD UNIQUE INDEX uniq_playlists_slug (slug)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_playlist_indexable_idx := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @schema
    AND TABLE_NAME = 'playlists'
    AND INDEX_NAME = 'idx_playlists_indexable_published'
);
SET @sql := IF(
  @has_playlist_indexable_idx = 0,
  'ALTER TABLE playlists ADD INDEX idx_playlists_indexable_published (indexable, published_at, updated_at)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
