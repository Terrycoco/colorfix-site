-- Existing ANA rows retain NULL; no historical classification or backfill.
ALTER TABLE analytics_events
    ADD COLUMN user_agent VARCHAR(1000) NULL DEFAULT NULL;

