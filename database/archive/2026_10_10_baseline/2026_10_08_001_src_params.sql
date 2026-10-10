-- The existing accepted sources are database-defined, not a PHP allowlist.
-- Copy every key (including inactive rows: the old FK accepted those too).
CREATE TABLE IF NOT EXISTS src_params (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  src VARCHAR(20) NOT NULL,
  label VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_src_params_src (src)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO src_params (src, label, created_at)
SELECT source_key, label, created_at
FROM analytics_sources
WHERE NOT EXISTS (
  SELECT 1 FROM src_params WHERE src_params.src = analytics_sources.source_key
);

-- Keep historical attribution keys and source grouping unchanged.
-- Replace the constraint in one ALTER; do not disable FK checks.
ALTER TABLE analytics_events
  DROP FOREIGN KEY fk_analytics_events_source,
  ADD CONSTRAINT fk_analytics_events_source
    FOREIGN KEY (source_key) REFERENCES src_params (src);
