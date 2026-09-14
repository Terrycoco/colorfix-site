-- Final legacy pre-PUB Publisher / Asset Creator table cleanup.
--
-- DO NOT RUN until this file has been reviewed against production.
--
-- Scope:
--   - Drops only confirmed obsolete pre-PUB publishing tables.
--   - Does not drop publishing_channels; current PUB OAuth/Auth still uses it.
--   - Does not drop any pub_* tables.
--   - Does not alter historical migration files.
--
-- Intentionally NOT dropped here:
--   publishing_channels
--     Current PUB Dispatch/OAuth code reads and writes this table through
--     App\PUB\Dispatch\Auth\PdoPubChannelAuthRepository.
--
-- FOREIGN_KEY_CHECKS is disabled because older migration generations allowed
-- conflicting/circular legacy references, notably publish_jobs/publish_outputs.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS publishing_pipeline_events;

DROP TABLE IF EXISTS scheduler_queue_item_attempts;
DROP TABLE IF EXISTS published_assets;
DROP TABLE IF EXISTS publisher_attempts;
DROP TABLE IF EXISTS scheduler_queue_items;

DROP TABLE IF EXISTS publication_schedule_attempts;
DROP TABLE IF EXISTS publication_schedule;
DROP TABLE IF EXISTS publications;
DROP TABLE IF EXISTS publishing_assets;

DROP TABLE IF EXISTS packages;
DROP TABLE IF EXISTS package_batches;

DROP TABLE IF EXISTS publisher_sync_runs;
DROP TABLE IF EXISTS publishing_jobs;
DROP TABLE IF EXISTS publish_outputs;
DROP TABLE IF EXISTS publish_jobs;
DROP TABLE IF EXISTS publishing_default_templates;

DROP TABLE IF EXISTS asset_creator_outputs;
DROP TABLE IF EXISTS asset_creator_inputs;
DROP TABLE IF EXISTS asset_creator_jobs;

SET FOREIGN_KEY_CHECKS = 1;
