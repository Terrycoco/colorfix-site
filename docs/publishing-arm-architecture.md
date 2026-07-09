# Publishing Arm Application Structure

Last updated: 2026-06-28

This is the source of truth for the publishing arm. The pipeline is:

```text
Analyzer -> Creator -> Packager -> Scheduler -> Publisher -> Published Assets
```

The system must be inspectable end to end. From any page or record, it should be clear what the object is, what created it, where it is going, what happened before, what is allowed next, whether it is safe to delete, and whether it has gone public.

## Core Rule

Only repository classes may access the database.

Required dependency flow:

```text
Controller -> Service -> Repository -> Database
```

External platform flow:

```text
PublisherService -> Channel Adapter -> External API
```

Never allow controllers, services, schedulers, workers, utilities, or channel adapters to contain raw SQL or call the database directly.

## Object Model

There are six separate concepts. Do not collapse them into one object.

```text
Analyzer Job
Creator Job
Package
Scheduler Queue Item
Publisher Attempt
Published Asset
```

### Analyzer Job

Created by Analyzer.

Purpose:

- Inspect a playlist.
- Decide what can be produced.
- Create the recipe/candidate plan.
- Identify pin types, source slides, before/ColorFixed pairs, single ideas, palette pins, and needed titles/descriptions.

Status:

```text
analyzed
```

Allowed actions:

- rerun analysis
- edit/review recipe
- send to Creator
- delete

Analyzer does not generate final assets, package assets, create instances, schedule, or publish.

### Creator Job

Created by Creator when the Analyzer recipe is run.

Purpose:

- Generate final images/videos/pins.
- Attach creator-level titles, descriptions, URLs, pin types, and asset metadata.
- Group generated assets into one Creator Job.

Status:

```text
created
```

Allowed actions:

- recreate assets
- edit asset metadata
- send to Packager
- delete if nothing public or in progress depends on it

Creator does not create playlist instances, stable landing URLs, queue rows, publisher attempts, or external posts.

### Packager

Packager is the admin step between Creator and Scheduler.

Purpose:

- Select the publishing channel and destination for a Creator Job.
- Choose the CTA Page.
- Set the package label, instance title, and slug.
- Create or reuse the playlist instance for that destination.
- Create Package records from Creator outputs.
- Send the package batch to Scheduler.

Packager does not call Pinterest, YouTube, or any external publishing API.
Packager does not mark anything published. It only prepares packages.

### Package

Created by Packager.

Purpose:

- Wrap one finished Creator asset with everything needed to publish it later.
- Prepare the asset for a specific channel, destination, CTA page, environment, and tracking URL.
- Store all routing and posting instructions.

A Package is the box with the shipping label. Scheduler must not reconstruct board, CTA, URL, title, image, tracking params, or channel data.

Status:

```text
packaged
queued
published
test_published
error
```

Allowed actions:

- repackage
- send to Scheduler
- delete if not queued, in progress, or published

Each Package should carry:

- `package_id`
- `analyzer_job_id`
- `creator_job_id`
- `source_asset_id`
- channel/platform/environment
- destination board/channel metadata
- CTA page and playlist instance
- canonical and tracked destination URLs
- title, description, alt text
- media path/URL
- pin type or platform asset type
- duplicate fingerprint
- channel-specific JSON metadata

### Scheduler Queue Item

Created by Scheduler after it accepts a Package.

Purpose:

- Store publish-ready Packages in the waiting queue.
- Apply channel timing rules.
- Apply mix-it-up rules.
- Apply duplicate/cooldown rules.
- Select the next Package to send to Publisher.

Queue statuses:

```text
waiting
in_progress
published
error
cancelled
skipped_duplicate
```

Definitions:

- `waiting`: Package is sitting in Scheduler queue.
- `in_progress`: Scheduler selected this queue item and sent its Package to Publisher.
- `published`: Publisher returned a successful channel result.
- `error`: Publisher/channel failed or Scheduler failed.
- `cancelled`: admin manually stopped this item before publication.
- `skipped_duplicate`: Scheduler skipped this item because it violated duplicate/cooldown rules.

Allowed actions:

- `waiting`: remove/delete from queue, cancel, or Run Now.
- `in_progress`: no hard delete; wait for result or timeout recovery.
- `published`: view published record; do not hard-delete.
- `error`: retry or delete.
- `cancelled`: delete or explicitly restore/recreate.
- `skipped_duplicate`: delete or manual Run Now override with warning.

Scheduler owns timing and selection. Scheduler does not talk to Pinterest, YouTube, Instagram, or other platform APIs.

### Publisher Attempt

Created when Publisher tries to post a Package to a channel.

Purpose:

- Record each delivery attempt.
- Track retries.
- Capture channel response.
- Prevent ambiguous “did it post?” states.

Attempt statuses:

```text
sent
success
error
timeout
```

Publisher flow:

1. Receive selected Package from Scheduler.
2. Read package channel and environment.
3. Route to the correct channel adapter.
4. Send the package to the channel API.
5. Wait for success or error.
6. Return a normalized result to Scheduler.
7. Create/update Published Asset on success.

Publisher is not done when it sends the API request. Publisher is done when the channel returns success or error.
The Publisher admin screen is for published receipts, manual/backfill receipts, payload inspection, and platform links. It is not where Creator Jobs are packaged.

### Published Asset

Created only after a successful Publisher result.

Purpose:

- Preserve proof of what went public.
- Support analytics.
- Support duplicate checks.
- Support troubleshooting.
- Support removal tracking.

Do not hard-delete Published Assets. If an external post is removed, mark it `removed_from_channel` and preserve the historical record.

Published Asset should store:

- `published_asset_id`
- `analyzer_job_id`
- `creator_job_id`
- `package_id`
- `queue_item_id`
- `attempt_id`
- `source_asset_id`
- channel/platform/environment
- board/channel metadata
- title and description
- destination/tracked URL
- external channel post ID and URL
- published timestamp
- duplicate fingerprint
- removal flags

## Final Tables

Current publishing tables:

```text
publishing_channels
publisher_sync_runs
package_batches
packages
scheduler_queue_items
scheduler_queue_item_attempts
publisher_attempts
published_assets
publishing_pipeline_events
```

`publishing_channels` stores platform/account/channel configuration, encrypted auth payloads, scheduler defaults, and destination metadata such as Pinterest board IDs.

`package_batches` groups packages prepared from a Creator Job for a selected channel/environment. It is the Packager’s batch record, not the published proof.

`packages` stores one publish-ready Creator asset and all routing/posting instructions.

`scheduler_queue_items` stores queue inventory and queue status.

`publisher_attempts` stores external post attempts and raw/normalized responses.

`published_assets` stores confirmed external publication receipts.

`publishing_pipeline_events` is the shared timeline/event log.

Legacy disposable tables are intentionally replaced by the final schema:

```text
publish_jobs
publish_outputs
publishing_jobs
publishing_assets
publication_schedule
publication_schedule_attempts
publications
```

## Scheduler Rules

Scheduler wakes according to channel timing settings. It does not preassign fixed publish times to every package.

Channel timing settings live in `publishing_channels.metadata_json.scheduler` and may include:

- enabled
- start time
- end time
- interval minutes
- max per day
- timezone
- environment overrides

Scheduler tracks are channel plus environment. Pinterest boards are destinations inside the Pinterest channel, not separate timing tracks.

Normal Scheduler selection should:

- find a due channel/environment track
- filter eligible waiting queue items
- skip exact duplicates inside the cooldown window
- apply mix-it-up rules
- prefer older waiting items after mix rules are satisfied
- mark the selected queue item `in_progress`
- send that exact Package to Publisher

Manual Run Now is an override. It should publish the selected queue item/package and bypass automatic duplicate and mix-it-up selection, while logging the override.

## Duplicate Rule

Normal Scheduler runs should avoid exact duplicates in the configured cooldown window.

Current cooldown:

```text
90 days
```

Duplicate matching should use:

- same channel
- same board/destination
- same generated pin asset/image
- same destination URL
- published within the last 90 days

Normal run:

- skip duplicate queue item
- mark it `skipped_duplicate`
- store skip reason
- store matching prior Published Asset if available

Manual Run Now may override this.

## Mix-It-Up Rule

When choosing the next queue item, Scheduler should avoid repetitive posting:

- avoid same Creator Job back-to-back if possible
- avoid same playlist/source back-to-back if possible
- avoid same pin type back-to-back if possible
- avoid same visual/template style back-to-back if possible
- respect priority if present
- prefer older waiting items after mix rules are satisfied

## Delete Rule

Anything except `in_progress` or `published` is generally safe to delete from the pipeline.

Safe to hard-delete:

- analyzed Analyzer Jobs
- created Creator Jobs/assets with no public dependencies
- packaged Packages not queued/in progress/published
- waiting queue items
- error queue items
- cancelled queue items
- skipped_duplicate queue items
- queued jobs only when none of their child queue items are in progress or published

Not safe to hard-delete:

- in_progress queue items
- published queue items
- Published Assets
- jobs containing in_progress queue items
- jobs containing published queue items

For external removals, add a remove/unpublish action and mark the Published Asset as removed from channel.

## Timeline Events

Every status change should be written to `publishing_pipeline_events`.

Suggested events:

```text
analyzer_job_created
analyzed
creator_job_created
assets_created
package_created
packaged
sent_to_scheduler
queued_in_scheduler
selected_by_scheduler
sent_to_publisher
publisher_attempt_created
publisher_success
publisher_error
published_asset_created
duplicate_skipped
manual_run_now_override
manually_cancelled
deleted_from_pipeline
removed_from_channel
timeout_recovery
```

Each event should include relevant IDs, stage, event type, old/new status, message, actor/source, timestamp, and metadata JSON.

## UI Rules

Use shared status and toast components instead of per-page one-off status systems.

Status displays must identify object type:

```text
Analyzer Job
Creator Job
Package
Scheduler Queue Item
Publisher Attempt
Published Asset
```

Use shared toast types:

```text
success
error
warning
info
```

Every user-facing error should be human-readable while preserving technical details in attempt/event records.

## Channel Adapters

Create one adapter per external platform:

```text
PinterestPublisher
YouTubePublisher
InstagramPublisher
```

Adapters build platform-specific API requests, call the platform, and return normalized results. They must not access the database or decide lifecycle transitions.
