# ColorFix Publishing Pipeline Plan

Last updated: 2026-06-28

## Source Of Truth

The architecture source of truth is [publishing-arm-architecture.md](publishing-arm-architecture.md).

This plan tracks implementation status and handoff notes. If this document conflicts with the architecture doc, the architecture doc wins.

## Target Pipeline

```text
Playlist
  -> Analyzer Job
  -> Creator Job
  -> Packager
  -> Package Batch + Packages
  -> Scheduler Queue Items
  -> Publisher Attempts
  -> Published Assets
```

## Current Build Direction

The publishing system is still under active construction. Existing test publishing data is disposable. The current refactor intentionally replaces older draft publishing tables and names with the final Packager/Scheduler/Publisher object model.

The main change in this pass is splitting the previous Publisher preparation work into:

- Packager: prepublish/package preparation.
- Scheduler: queue inventory, timing, and selection.
- Publisher: external API execution and proof-of-publication records.

## Final Publishing Tables

Preserved tables:

```text
publishing_channels
publisher_sync_runs
```

Final pipeline tables:

```text
package_batches
packages
scheduler_queue_items
scheduler_queue_item_attempts
publisher_attempts
published_assets
publishing_pipeline_events
```

Replaced/disposable legacy tables:

```text
publish_jobs
publish_outputs
publishing_jobs
publishing_assets
publication_schedule
publication_schedule_attempts
publications
```

Migration:

```text
database/migrations/2026_06_28_003_rebuild_packager_scheduler_publisher_schema.sql
```

This migration is destructive for the old publishing/scheduler tables by design. It does not drop `publishing_channels` or `publisher_sync_runs`.

## Implemented Pieces

### Pinterest OAuth And Boards

Pinterest OAuth is implemented with:

- `GET /api/pinterest/connect`
- `GET /api/pinterest/oauth/callback`

Pinterest app config keys:

```text
PINTEREST_APP_ID
PINTEREST_APP_SECRET
PINTEREST_REDIRECT_URI
```

Current requested scopes:

```text
boards:read
boards:write
pins:read
pins:write
```

Board sync stores destination metadata in `publishing_channels.metadata_json.destinations`.

Known boards:

- Test: `ColorFix API Test`
- Production: `ColorFix Makeovers`

### Packager

Packager service:

```text
app/services/PublishingPackagerService.php
```

The Packager takes finished Creator output and prepares:

- `package_batches`
- `packages`
- destination board metadata
- CTA page/playlist instance references
- canonical and tracked destination URLs
- title/description/alt text/media details
- platform metadata

Packager does not call external APIs and does not decide timing.

Current API entry points:

```text
api/v2/admin/packager/pinterest/list.php
api/v2/admin/packager/pinterest/package-from-creator.php
```

### Scheduler

Scheduler service:

```text
app/services/PublicationScheduler.php
app/repos/PdoPublicationScheduleRepository.php
```

Scheduler owns `scheduler_queue_items`.

Queue item statuses:

```text
waiting
in_progress
published
error
cancelled
skipped_duplicate
```

Scheduler responsibilities:

- accept Packages into the queue
- avoid duplicate queue rows for packages already waiting/in progress
- apply channel/environment timing
- apply mix-it-up selection
- avoid exact duplicate published pins within 90 days during normal runs
- bypass mix/duplicate selection on manual Run Now
- send selected Packages to Publisher
- mark queue items published or error after Publisher response

Current API entry points:

```text
api/v2/admin/publication-scheduler/list.php
api/v2/admin/publication-scheduler/schedule.php
api/v2/admin/publication-scheduler/schedule-package-batch.php
api/v2/admin/publication-scheduler/reschedule.php
api/v2/admin/publication-scheduler/cancel.php
api/v2/admin/publication-scheduler/delete-unscheduled.php
api/v2/admin/publication-scheduler/publish-now.php
api/v2/admin/publication-scheduler/run-due.php
```

Worker:

```text
scripts/run-publication-scheduler.php
```

### Publisher

Publisher execution path:

```text
PublicationScheduler
  -> PublicationExecutor
  -> PinterestOAuthService / Publisher repository
  -> PinterestPublisher
  -> Pinterest API
```

Publisher responsibilities:

- receive the exact Package selected by Scheduler
- create `publisher_attempts`
- call the right platform adapter
- normalize success/error
- create `published_assets` on success
- lock production package/destination obligations after production success

Publisher does not rebuild Package data.

### Admin UI

Current admin pages:

```text
/admin/asset-creators
/admin/publisher
/admin/scheduler
```

The Publisher page is becoming the Packager-facing page:

- choose channel/platform
- choose Creator Job
- choose environment/destination
- choose CTA Page
- package Creator output
- send packages to Scheduler

The Scheduler page lists queue inventory and lets the admin:

- enqueue packages
- remove waiting/error/cancelled rows
- run a selected row now
- run due rows
- adjust channel timing

Published records should live in the Publisher/Published Assets view, not mixed with waiting queue inventory.

## Data Flow Rules

### Analyzer

Analyzer only answers: what can be made from this playlist?

It does not create generated files, playlist instances, packages, queue items, or external posts.

### Creator

Creator only creates the finished asset files and Creator Job grouping.

It does not choose boards, CTAs, landing URLs, scheduling, or publish through APIs.

### Packager

Packager creates a Package per publishable Creator asset.

Each Package must include everything Publisher needs later:

- platform/channel/environment
- board/destination metadata
- media URL/path
- title/description/alt text
- CTA page
- playlist instance
- canonical destination URL
- tracked destination URL
- source/tracking parameters
- duplicate fingerprint

### Scheduler

Scheduler owns the queue.

Sending a whole Package Batch to Scheduler should:

- inspect all packages in the batch
- skip any package already waiting/in progress
- enqueue only the missing packages
- return counts for enqueued, already waiting, and skipped packages

Scheduler decides what goes next when a channel/environment track is due. It should mix Creator Jobs and pin types when possible.

### Publisher

Publisher owns the external API attempt and success/failure result.

Publisher only creates a Published Asset after the channel returns success.

## Delete Rules

Safe to delete:

- Packaged Packages not queued/in progress/published
- waiting queue items
- error queue items
- cancelled queue items
- skipped_duplicate queue items
- test records without public production dependencies

Not safe to hard-delete:

- in_progress queue items
- published queue items
- Published Assets
- production packages/destinations after successful production publication

If a live external post is removed, mark its Published Asset removed instead of deleting history.

## Duplicate And Cooldown Rule

Normal Scheduler runs should avoid exact duplicates for 90 days.

Duplicate matching should use:

- same channel/environment
- same destination board
- same generated image/asset
- same destination URL
- Published Asset within the last 90 days

Manual Run Now overrides this rule and should be logged.

## Pinterest Reference

Pinterest Create Pin request shape:

```json
{
  "alt_text": "string",
  "description": "string",
  "title": "string",
  "link": "string",
  "ai_disclosures": {
    "values": ["string"]
  },
  "board_id": "string",
  "board_section_id": "string",
  "dominant_color": "string",
  "media_source": {
    "source_type": "string",
    "is_standard": true,
    "content_type": "string",
    "data": "string"
  },
  "parent_pin_id": "string",
  "sponsor_id": "string"
}
```

Pinterest single Pin analytics:

```text
GET https://api.pinterest.com/pins/{pin_id}/analytics
Authorization: Bearer <token>
```

Pinterest multiple Pin analytics:

```text
GET https://api.pinterest.com/pins/analytics
Authorization: Bearer <token>
```

Analytics metrics of interest include:

```text
IMPRESSION
OUTBOUND_CLICK
PIN_CLICK
SAVE
SAVE_RATE
TOTAL_COMMENTS
TOTAL_REACTIONS
VIDEO_START
VIDEO_10S_VIEW
VIDEO_AVG_WATCH_TIME
```

## Remaining Work

- Finish the shared status component for all six object types.
- Move all page-level alerts onto the shared toast component.
- Add full event logging into `publishing_pipeline_events`.
- Add Packager detail views for package batches and packages.
- Separate Publisher UI into “packages waiting for Scheduler” and “published records”.
- Add Published Assets detail modal with external URL, board, destination URL, and attempt history.
- Add removal tracking for external posts.
- Add analytics ingestion after publishing flow stabilizes.
- Add YouTube channel metadata, adapter, packaging rules, and scheduler timing defaults.
