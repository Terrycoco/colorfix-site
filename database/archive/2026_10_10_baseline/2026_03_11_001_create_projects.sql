CREATE TABLE IF NOT EXISTS projects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(150) NOT NULL,
  title VARCHAR(255) NOT NULL,
  project_type VARCHAR(50) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  summary TEXT NULL,
  notes TEXT NULL,
  client_name VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_projects_slug (slug),
  KEY idx_projects_project_type (project_type),
  KEY idx_projects_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO projects (
  slug,
  title,
  project_type,
  status,
  summary,
  notes,
  client_name
) VALUES (
  'mojdeh-interior-makeover',
  'Mojdeh Interior Makeover',
  'interior',
  'draft',
  'Interior refresh project grouping photos, playlists, palettes, and related content.',
  'Initial project container for Mojdeh assets.',
  'Mojdeh'
);

INSERT INTO projects (
  slug,
  title,
  project_type,
  status,
  summary,
  notes,
  client_name
) VALUES (
  'whiskey-room-transformation',
  'Whiskey Room Transformation',
  'interior',
  'draft',
  'Project container for whiskey room photos, playlists, palettes, and supporting content.',
  'Use for before-and-after assets and story assembly.',
  NULL
);

INSERT INTO projects (
  slug,
  title,
  project_type,
  status,
  summary,
  notes,
  client_name
) VALUES (
  'palmdale-front-door-fix',
  'Palmdale Front Door Fix',
  'exterior',
  'draft',
  'Exterior project container for the Palmdale front door update and related assets.',
  'Holds photos, pins, palettes, and any future article content.',
  NULL
);

-- Rollback:
-- DROP TABLE IF EXISTS projects;
