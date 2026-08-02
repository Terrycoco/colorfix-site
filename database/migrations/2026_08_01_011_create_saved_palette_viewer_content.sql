CREATE TABLE saved_palette_viewer_content (
  saved_palette_viewer_content_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  saved_palette_id BIGINT UNSIGNED NOT NULL,
  saved_palette_set_id BIGINT UNSIGNED NOT NULL,
  template_key VARCHAR(50) NOT NULL,
  kicker_text VARCHAR(255) NULL,
  title VARCHAR(255) NULL,
  intro TEXT NULL,
  notes TEXT NULL,
  cta_label VARCHAR(120) NULL,
  playlist_url VARCHAR(500) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (saved_palette_viewer_content_id),
  UNIQUE KEY uq_spvc_set_template (saved_palette_set_id, template_key),
  KEY idx_spvc_palette_template (saved_palette_id, template_key),
  CONSTRAINT fk_spvc_saved_palette
    FOREIGN KEY (saved_palette_id) REFERENCES saved_palettes(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_spvc_saved_palette_set
    FOREIGN KEY (saved_palette_set_id) REFERENCES saved_palette_sets(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO saved_palette_viewer_content (
  saved_palette_id,
  saved_palette_set_id,
  template_key,
  kicker_text,
  title,
  intro,
  notes,
  cta_label,
  playlist_url,
  is_active,
  created_at,
  updated_at
)
SELECT
  sp.id,
  sps.id,
  'full_palette',
  k.display_text,
  sp.display_title,
  sp.notes,
  NULL,
  NULL,
  NULL,
  1,
  NOW(),
  NOW()
FROM saved_palettes sp
JOIN saved_palette_sets sps
  ON sps.saved_palette_id = sp.id
LEFT JOIN kickers k
  ON k.kicker_id = sp.kicker_id
LEFT JOIN saved_palette_viewer_content existing
  ON existing.saved_palette_set_id = sps.id
 AND existing.template_key = 'full_palette'
WHERE existing.saved_palette_viewer_content_id IS NULL;
