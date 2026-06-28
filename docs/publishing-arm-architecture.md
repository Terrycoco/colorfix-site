# Publishing Arm Application Structure

Last updated: 2026-06-25

This document is the architectural source of truth for the publishing arm. It defines the intended module boundaries, ownership rules, identifiers, status flow, and deletion rules.

## Core Rule

Only repository classes may access the database.

No controllers, services, adapters, workers, scheduled tasks, or utility classes may contain raw SQL or call the database directly.

Required dependency flow:

```text
Controller
-> Service
-> Repository
-> Database
```

External platform flow:

```text
PublisherService
-> Channel Adapter
-> External API
```

Never allow:

```text
Controller -> SQL
Service -> SQL
Scheduler -> SQL
Channel Adapter -> SQL
Worker -> SQL
```

All persistence must go through repositories.

## Services

### AnalyzerService

Responsibilities:

- Load source content through repositories.
- Analyze playlists or other supported source types.
- Generate publishing candidates.
- Save or update analysis results through repositories.
- Return candidates for user review and editing.

Rules:

- Analyzer does not create publishing jobs.
- Analyzer does not generate final channel assets.
- Analyzer does not create playlist instances.
- Analyzer does not schedule or publish anything.

### CreatorService

Responsibilities:

- Create the `publishing_jobs` record.
- Receive the new `job_id`.
- Create one `publishing_assets` row per selected candidate.
- Receive one auto-increment `asset_id` per asset.
- Generate the final channel files.
- Save file paths and metadata through repositories.
- Move assets and job into review status.
- Delete failed or rejected unpublished jobs through coordinated repository cleanup.

Rules:

- Creator creates the job before creating assets.
- Creator creates each asset row before generating its final file.
- Creator does not create playlist instances.
- Creator does not create stable destination URLs.
- Creator does not schedule assets.
- Creator does not call external publishing APIs.

### PublisherService

Responsibilities before scheduling:

- Load and validate the approved job.
- Confirm job, assets, channel, and environment.
- Create the channel-specific playlist instance.
- Create stable destination URLs.
- Attach `src=<channel>` and `asset=<asset_id>` to each asset URL.
- Prepare channel-specific publication data.
- Choose the destination board or channel.
- Hand each approved asset to `SchedulerService`.

Responsibilities at execution time:

- Receive `job_id` and `asset_id` from Scheduler.
- Load the exact job and asset through repositories.
- Validate status, file, destination URL, channel, and environment.
- Select the correct channel adapter.
- Call the external API.
- Normalize the external response.
- Create publish-attempt records.
- Create a `publications` row on confirmed success.
- Update asset and job status through repositories.
- Return a normalized result to Scheduler.

Rules:

- Publisher owns channel communication.
- Publisher creates playlist instances only after asset approval.
- Publisher creates one publication per successfully published asset.
- Publisher does not contain SQL.
- Publisher must not let channel adapters persist data.

### SchedulerService

Responsibilities:

- Receive one scheduling request per asset.
- Store `job_id`, `asset_id`, channel, environment, and scheduled time through repositories.
- Store channel timing defaults on `publishing_channels.metadata_json.scheduler`.
- Apply max posts per day, spacing, publishing windows, lead time, and slot
  rounding by channel and environment.
- Identify due queue items.
- Call `PublisherService` with the due `job_id` and `asset_id`.
- Receive normalized success or failure results.
- Update queue status, retry timing, attempt count, and completion timestamps.
- Preserve completed scheduler rows as history.

Rules:

- Scheduler controls timing only.
- Scheduler does not publish directly.
- Scheduler does not call Pinterest, YouTube, or other channel APIs.
- Scheduler does not create publication records.
- Scheduler does not contain SQL.
- Publisher should send jobs without manually calculated times unless the admin
  explicitly overrides a row. Scheduler assigns the next open slot.
- Running a row now should let Scheduler compact later rows in the same
  channel/environment track.

Execution flow:

```text
PublisherService
-> SchedulerService queues asset
-> SchedulerService waits
-> SchedulerService calls PublisherService
-> PublisherService calls channel adapter
-> PublisherService returns normalized result
-> SchedulerService updates queue state
```

### PublishingJobService

Use a dedicated service for shared lifecycle rules.

Responsibilities:

- Validate allowed job status transitions.
- Validate allowed asset status transitions.
- Enforce test versus production deletion rules.
- Determine whether a job may be deleted.
- Coordinate full unpublished-job deletion.
- Prevent modification of frozen production records.
- Mark production records archived, inactive, or retired.
- Determine when all assets are complete and the job may become `published`.

Rules:

- Lifecycle rules must not be duplicated across Creator, Publisher, and Scheduler.
- Other services should call `PublishingJobService` for shared status and deletion decisions.
- `PublishingJobService` must use repositories for all persistence.

### AnalyticsService

Responsibilities:

- Record ColorFix-side events.
- Accept incoming `asset_id` attribution from the destination URL.
- Resolve job, publication, channel, environment, playlist instance, and source through repositories.
- Record events such as `playlist_open`, `replay`, `see_colors_used`, `share`, and `browse_more`.
- Later collect external platform metrics.
- Keep test and production analytics separate.

Rules:

- Analytics is not required to block initial publishing.
- The current publishing structure must preserve the identifiers needed for later analytics.
- Analytics must not change publishing job or publication state.
- Analytics does not contain SQL.

## Repositories

Create focused repositories for database access.

Suggested repositories:

```text
PublishingJobRepository
PublishingAssetRepository
PublicationRepository
SchedulerQueueRepository
PublishAttemptRepository
PlaylistInstanceRepository
AnalysisRepository
AnalyticsRepository
PlaylistRepository
```

Responsibilities:

- Contain all SQL.
- Map database rows to domain records.
- Provide explicit read and write methods.
- Enforce transaction boundaries where appropriate.
- Expose no platform-specific API logic.

Example methods:

```text
PublishingJobRepository.create(...)
PublishingJobRepository.findById(...)
PublishingJobRepository.updateStatus(...)

PublishingAssetRepository.create(...)
PublishingAssetRepository.findById(...)
PublishingAssetRepository.findByJobId(...)
PublishingAssetRepository.updateFilePath(...)
PublishingAssetRepository.updateStatus(...)

PublicationRepository.create(...)
PublicationRepository.findByAssetId(...)

SchedulerQueueRepository.enqueue(...)
SchedulerQueueRepository.findDueItems(...)
SchedulerQueueRepository.markCompleted(...)
SchedulerQueueRepository.markRetryPending(...)
```

Do not create generic repository methods that encourage arbitrary SQL-like access from services. Prefer explicit domain methods.

## Channel Adapters

Create one adapter per external platform.

Examples:

```text
PinterestPublisherAdapter
YouTubePublisherAdapter
InstagramPublisherAdapter
```

Adapter responsibilities:

- Build the platform-specific API request.
- Authenticate with the external API.
- Send the request.
- Receive the native platform response.
- Translate the response into a normalized result.
- Return the native response data for storage.

Adapters must not:

- Access the database.
- Create publication rows.
- Update jobs.
- Update assets.
- Update scheduler rows.
- Decide retry policy.
- Decide lifecycle transitions.

Normalized adapter success:

```json
{
  "success": true,
  "external_post_id": "987654321",
  "external_post_url": "https://...",
  "published_at": "2026-06-24T18:30:00Z",
  "raw_response": {}
}
```

Normalized adapter failure:

```json
{
  "success": false,
  "retryable": true,
  "error_code": "RATE_LIMITED",
  "error_message": "Platform rate limit exceeded",
  "raw_response": {}
}
```

## Controllers And API Endpoints

Controllers should remain thin.

Controller responsibilities:

- Validate request shape.
- Authorize the request.
- Call the appropriate service.
- Return the service result.

Controllers must not:

- Contain business rules.
- Contain lifecycle logic.
- Contain SQL.
- Call repositories directly.
- Call external channel APIs directly.

Example flow:

```text
CreateAssetsController -> CreatorService
ApprovePublishingJobController -> PublisherService
ScheduleAssetController -> SchedulerService
ExecuteScheduledAssetController -> SchedulerService or PublisherService through a controlled internal endpoint
DeletePublishingJobController -> PublishingJobService
```

## Workers And Scheduled Tasks

Cron jobs or workers may trigger service methods, but they must not contain business logic or database access.

Correct:

```text
SchedulerWorker -> SchedulerService.processDueItems()
```

Incorrect:

```text
SchedulerWorker -> raw SQL -> Pinterest API -> direct status updates
```

Workers should be replaceable without changing business behavior.

## Transaction Rules

Use database transactions through repositories or a transaction coordinator for operations that must succeed or fail together.

Job creation:

```text
create publishing job
create asset rows
commit
```

If asset-row setup fails, roll back the job creation.

Job deletion:

```text
delete scheduler rows
delete publish attempts
delete unpublished publication preparation rows
delete playlist instance owned by job
delete publishing assets
delete publishing job
commit
```

Delete generated files only after the database transaction succeeds, or use a cleanup strategy that can recover safely.

Publication success:

```text
create publication row
mark asset published
update publish attempt
update job if all assets complete
commit
```

Do not allow a successful external API response to leave only part of the local state updated.

## Ownership Rules

Analyzer owns:

```text
analysis candidates
```

Creator owns:

```text
job creation
asset-row creation
asset generation
review state
```

Publisher owns:

```text
approval
playlist instance creation
stable URLs
channel payload preparation
external API execution
publication creation
```

Scheduler owns:

```text
timing
queue state
retry timing
execution history
```

PublishingJobService owns:

```text
shared lifecycle rules
deletion eligibility
status-transition validation
production retention rules
```

Analytics owns:

```text
site events
platform metric collection
reporting attribution
```

Repositories own:

```text
all database access
```

Channel adapters own:

```text
platform-specific API communication only
```

## Required Shared Identifiers

The following identifiers must flow consistently through the service and repository layers:

```text
job_id
asset_id
channel
environment
source_type
source_id
playlist_instance_id
publication_id
```

Key rules:

```text
job_id identifies the publishing batch.
asset_id identifies one generated channel asset.
publication_id identifies one successful external platform post.
The stable destination URL identifies the playlist instance.
```

The URL query parameters carry:

```text
src=<channel>
asset=<asset_id>
```

The application may later resolve:

```text
asset_id
-> publication
-> job
-> channel
-> environment
-> playlist instance
-> source record
```

## Current Database Shape

As of the publishing schema reset migration
`2026_06_25_001_rebuild_publishing_arm_schema.sql`, the active publishing
tables are:

```text
publishing_channels
publishing_jobs
publishing_assets
publications
publisher_attempts
publisher_sync_runs
publication_schedule
publication_schedule_attempts
```

Legacy test tables were removed:

```text
publish_jobs
publish_outputs
```

`publishing_channels` is preserved across publisher test resets because it owns
channel configuration, encrypted auth payloads, and platform destination
metadata. Pinterest board IDs currently live in
`publishing_channels.metadata_json.destinations`.

`publishing_jobs` is the batch/job header. `publishing_assets` is one final
creator asset prepared for a channel/environment. `publications` is the record of
an actual external post. `publication_schedule` queues assets, not jobs.

## Status Rules

The master job status lives on `publishing_jobs`.

Suggested job states:

```text
creating
review
approved
queued
publishing
published
retry_pending
failed
archived
```

Each asset also has its own status.

Suggested asset states:

```text
creating
review
approved
queued
publishing
published
retry_pending
failed
```

All status transitions must pass through service-level validation.

No repository method should silently invent lifecycle behavior. Repositories persist requested transitions; services decide whether those transitions are legal.

## Deletion Rules

Test jobs may be hard-deleted at any time through service-controlled cleanup.

Unpublished production jobs may be hard-deleted.

Published production records must not be hard-deleted. Preserve:

```text
publishing job
publishing asset
publication
playlist instance
stable URL
scheduler history
publish attempts
analytics attribution
```

Use `archived`, `inactive`, or `retired` instead of deletion.

## Final Architectural Rule

```text
Controllers coordinate requests.
Services own business logic.
Repositories own all database access.
Channel adapters own external API communication.
Workers trigger services.
No other layer may contain SQL.
```
