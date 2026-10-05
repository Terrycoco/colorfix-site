-- ANA events reference analytics_sources; client-tagged REX visits need this key.
INSERT INTO analytics_sources (source_key, label, is_active)
SELECT 'client', 'Client', 1
WHERE NOT EXISTS (
    SELECT 1 FROM analytics_sources WHERE source_key = 'client'
);
