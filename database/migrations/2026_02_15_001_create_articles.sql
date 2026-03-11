START TRANSACTION;

CREATE TABLE IF NOT EXISTS articles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type VARCHAR(32) NOT NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  meta_description VARCHAR(320) NULL,
  hero_asset_id INT UNSIGNED NULL,
  cta_overrides MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  published_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_articles_slug (slug),
  KEY idx_articles_type_status_published (type, status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS article_sections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  kind ENUM('text','image','palette_link','cta','embed','list') NOT NULL DEFAULT 'text',
  heading VARCHAR(255) NULL,
  body MEDIUMTEXT NULL,
  asset_id INT UNSIGNED NULL,
  palette_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_article_sections_sort (article_id, sort_order),
  KEY idx_article_sections_article_sort (article_id, sort_order),
  KEY idx_article_sections_kind (kind),
  CONSTRAINT fk_article_sections_article
    FOREIGN KEY (article_id) REFERENCES articles(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS article_tags (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(64) NOT NULL,
  name VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_article_tags_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS article_tag_map (
  article_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (article_id, tag_id),
  KEY idx_article_tag_map_tag (tag_id),
  CONSTRAINT fk_article_tag_map_article
    FOREIGN KEY (article_id) REFERENCES articles(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_article_tag_map_tag
    FOREIGN KEY (tag_id) REFERENCES article_tags(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO article_tags (slug, name) VALUES
('door','Door'),
('exterior','Exterior'),
('interior','Interior');

COMMIT;
