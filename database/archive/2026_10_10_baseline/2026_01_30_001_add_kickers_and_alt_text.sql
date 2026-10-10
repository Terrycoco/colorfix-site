CREATE TABLE kickers (
  kicker_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL UNIQUE,
  display_text VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
);

ALTER TABLE saved_palettes
  ADD COLUMN kicker_id INT UNSIGNED NULL,
  ADD COLUMN alt_text TEXT NULL,
  ADD INDEX idx_saved_palettes_kicker_id (kicker_id),
  ADD CONSTRAINT fk_saved_palettes_kicker
    FOREIGN KEY (kicker_id) REFERENCES kickers(kicker_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

ALTER TABLE applied_palettes
  ADD COLUMN kicker_id INT UNSIGNED NULL,
  ADD COLUMN alt_text TEXT NULL,
  ADD INDEX idx_applied_palettes_kicker_id (kicker_id),
  ADD CONSTRAINT fk_applied_palettes_kicker
    FOREIGN KEY (kicker_id) REFERENCES kickers(kicker_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

INSERT INTO kickers (slug, display_text, is_active, sort_order)
VALUES
  ('purple-bedroom-ideas', 'Purple Bedroom Ideas', 1, 0),
  ('adobe-house-palettes', 'Adobe House Palettes', 1, 0),
  ('hoa-approved-exterior-colors', 'HOA Approved Exterior Colors', 1, 0);
