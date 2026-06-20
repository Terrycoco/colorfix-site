ALTER TABLE ctas
  ADD COLUMN `onclick` VARCHAR(100) NULL AFTER params,
  ADD KEY idx_ctas_onclick (`onclick`);

-- Rollback:
-- ALTER TABLE ctas
--   DROP KEY idx_ctas_onclick,
--   DROP COLUMN `onclick`;
