ALTER TABLE client_activity
  ADD COLUMN admin_read_at DATETIME NULL DEFAULT NULL AFTER occurred_at,
  ADD KEY idx_client_activity_admin_read (admin_read_at, activity_type);
