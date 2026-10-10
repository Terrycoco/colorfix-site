CREATE TABLE IF NOT EXISTS hoa_scheme_mask_maps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hoa_id INT NOT NULL,
  scheme_id INT NOT NULL,
  asset_id VARCHAR(64) NOT NULL,
  mask_role VARCHAR(64) NOT NULL,
  scheme_role VARCHAR(128) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_scheme_asset_mask (scheme_id, asset_id, mask_role),
  KEY idx_scheme_asset (scheme_id, asset_id),
  KEY idx_hoa_asset (hoa_id, asset_id)
);
