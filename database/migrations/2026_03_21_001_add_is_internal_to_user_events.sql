ALTER TABLE user_events
  ADD COLUMN is_internal TINYINT(1) NOT NULL DEFAULT 0 AFTER user_agent,
  ADD KEY idx_user_events_internal_event_created (is_internal, event_type, created_at);
