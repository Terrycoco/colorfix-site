ALTER TABLE applied_palettes
  DROP INDEX uq_applied_palettes_token,
  DROP COLUMN share_token;
