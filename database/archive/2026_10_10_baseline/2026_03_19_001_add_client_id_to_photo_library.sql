ALTER TABLE photo_library
  ADD COLUMN client_id BIGINT UNSIGNED NULL AFTER source_id,
  ADD KEY idx_photo_library_client (client_id),
  ADD CONSTRAINT fk_photo_library_client
    FOREIGN KEY (client_id) REFERENCES clients(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;
