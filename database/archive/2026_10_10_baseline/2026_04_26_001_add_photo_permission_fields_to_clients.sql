ALTER TABLE clients
  ADD COLUMN photo_permission_status VARCHAR(20) NOT NULL DEFAULT 'unknown' AFTER notes,
  ADD COLUMN photo_permission_requested_at DATETIME NULL DEFAULT NULL AFTER photo_permission_status,
  ADD COLUMN photo_permission_granted_at DATETIME NULL DEFAULT NULL AFTER photo_permission_requested_at;
