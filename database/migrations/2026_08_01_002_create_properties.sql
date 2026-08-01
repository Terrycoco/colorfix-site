CREATE TABLE IF NOT EXISTS properties (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    address_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,

    name VARCHAR(255) NULL,
    notes TEXT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    INDEX idx_properties_address_id (address_id),
    INDEX idx_properties_client_id (client_id),

    CONSTRAINT fk_properties_address
        FOREIGN KEY (address_id)
        REFERENCES addresses(id),

    CONSTRAINT fk_properties_client
        FOREIGN KEY (client_id)
        REFERENCES clients(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
