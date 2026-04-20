CREATE TABLE IF NOT EXISTS audience_types (
  audience_type_id INT NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(50) NOT NULL,
  label VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (audience_type_id),
  UNIQUE KEY uniq_audience_types_key (`key`)
);

INSERT INTO audience_types (`key`, label, sort_order, is_active)
VALUES
  ('any', 'Any', 10, 1),
  ('qr', 'QR', 20, 1),
  ('hoa', 'HOA', 30, 1),
  ('homeowner', 'Homeowner', 40, 1),
  ('contractor', 'Contractor', 50, 1),
  ('pinterest', 'Pinterest', 60, 1),
  ('admin', 'Admin', 70, 1)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active),
  updated_at = CURRENT_TIMESTAMP;
