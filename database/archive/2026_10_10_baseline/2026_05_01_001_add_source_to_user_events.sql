ALTER TABLE user_events
  ADD COLUMN source VARCHAR(100) NULL AFTER cta_id,
  ADD KEY idx_user_events_source (source),
  ADD KEY idx_user_events_source_event_created (source, event_type, created_at);

-- Rollback:
-- ALTER TABLE user_events
--   DROP KEY idx_user_events_source_event_created,
--   DROP KEY idx_user_events_source,
--   DROP COLUMN source;
