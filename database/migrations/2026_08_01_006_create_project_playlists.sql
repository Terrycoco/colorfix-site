CREATE TABLE IF NOT EXISTS project_playlists (
    project_playlist_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    project_id BIGINT UNSIGNED NOT NULL,
    playlist_id INT NOT NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (project_playlist_id),

    UNIQUE KEY uq_project_playlists_project_playlist (project_id, playlist_id),
    INDEX idx_project_playlists_project_id (project_id),
    INDEX idx_project_playlists_playlist_id (playlist_id),

    CONSTRAINT fk_project_playlists_project
        FOREIGN KEY (project_id)
        REFERENCES projects(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_project_playlists_playlist
        FOREIGN KEY (playlist_id)
        REFERENCES playlists(playlist_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
