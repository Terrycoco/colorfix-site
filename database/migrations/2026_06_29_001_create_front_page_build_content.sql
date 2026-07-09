CREATE TABLE IF NOT EXISTS front_page_build_content (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  build_key VARCHAR(120) NOT NULL,
  content_key VARCHAR(120) NOT NULL,
  content_value TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_front_page_build_content_key (build_key, content_key),
  KEY idx_front_page_build_content_active (build_key, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO front_page_build_content (build_key, content_key, content_value, is_active)
VALUES
  ('public_home', 'title', 'ColorFix by Terry | Home Color Transformations & Paint Palettes', 1),
  ('public_home', 'meta_description', 'ColorFix by Terry is Terry Marr’s home color transformation site, featuring before-and-ColorFixed makeovers, exterior paint palettes, and practical color ideas for homeowners.', 1),
  ('public_home', 'robots', 'index,follow', 1),
  ('public_home', 'canonical_url', 'https://colorfix.terrymarr.com/', 1),
  ('public_home', 'footer_brand_text', '© 2026 ColorFix by Terry — home color transformations and paint palettes by Terry Marr.', 1),
  ('public_home', 'footer_url', 'https://colorfix.terrymarr.com/', 1),
  ('public_home', 'footer_url_text', 'colorfix.terrymarr.com', 1),
  ('admin_home', 'title', 'ColorFix Admin', 1),
  ('admin_home', 'robots', 'noindex,nofollow', 1)
ON DUPLICATE KEY UPDATE
  content_value = VALUES(content_value),
  is_active = VALUES(is_active);
