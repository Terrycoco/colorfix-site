ALTER TABLE photo_library
  ADD COLUMN photo_permission_status VARCHAR(20) NULL DEFAULT NULL AFTER client_id;
