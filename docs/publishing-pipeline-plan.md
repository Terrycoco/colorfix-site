# ColorFix Publishing Pipeline Plan

Last updated: 2026-06-25

## Architecture Source Of Truth

The publishing arm architecture is defined in
[publishing-arm-architecture.md](publishing-arm-architecture.md). That document
owns the service boundaries, repository-only database rule, channel-adapter
rules, shared identifiers, lifecycle states, transaction expectations, and
test/production deletion rules.

This plan is the implementation handoff and roadmap. If this plan conflicts
with the architecture document, the architecture document wins.

## Handoff Snapshot: Publishing System

This section is the current handoff source of truth for the publishing build.
Older roadmap notes remain below for context.

### Current Deployed State

The publishing foundation is partially implemented and deployed:

- Pinterest OAuth is connected for the ColorFix Pinterest app.
- Pinterest board sync can store destination metadata.
- The Pinterest test board has been synced:
  - board name: `ColorFix API Test`
  - environment: `test`
  - board ID: `363595438606053913`
  - board slug: `terrymarr/colorfix-api-test`
- The Pinterest production board has been synced:
  - board name: `ColorFix Makeovers`
  - environment: `production`
  - board ID: `363595438606037369`
  - board slug: `terrymarr/colorfix-makeovers`
- Publisher preparation can create or reuse playlist instances and publishing
  records from creator outputs.
- CTA Pages exist so publisher-created instances can point to a stable CTA page
  instead of copying CTA buttons.
- The publication queue tables and scheduler service exist.
- The Scheduler admin page exists at `/admin/scheduler`.
- The scheduler runner script exists at
  `scripts/run-publication-scheduler.php`.
- First API publishing is intentionally test-first. Production publishing and
  production locks should only be enabled after test publishing is verified.

### Pipeline Shape

The current pipeline is:

```text
Playlist photos
  -> Analyzer
  -> Creator recipe
  -> Publishing job batch
  -> Publishing assets / generated files
  -> Publisher approval and preparation
  -> Playlist instance + CTA page + landing/tracked URLs
  -> Publication scheduler queue
  -> PublisherService execution
  -> Platform adapter, such as PinterestPublisherAdapter
  -> External platform API
  -> Publication record
```

The analyzer and creator deal with raw playlist content and generated files.
They do not care about publishing instances, boards, auth, or permanent URL
locking.

### Hard Architecture Rules

- Only repository classes touch the database.
- API route files parse input, call services, and return JSON.
- Services own workflow logic and call repositories.
- Channel adapters shape and send platform-specific API payloads.
- The scheduler owns queue timing, claiming, canceling, retrying, and run-ahead.
- Channel timing rules live on `publishing_channels.metadata_json.scheduler`.
- Automatic scheduler selection must avoid reposting the exact same pin within
  90 days when possible; manual `Run Now` is an explicit override.
- The scheduler must not know Pinterest payload details.
- The publisher must not redesign creator assets.
- Controllers, services, workers, schedulers, and channel adapters must not
  contain SQL.
- External API adapters must not persist data.
- Assets and test records are expendable before production publishing.
- Production publishing creates the durable public-link obligation.

### Module Responsibilities

Analyzer:

- Reads playlist photo roles.
- Determines possible creator outputs.
- For Pinterest, proposed pin types include `composite`, `idea`, and
  `idea_palette`.
- Uses playlist roles such as `ignore`, `before`, `after`, and `single`.
- A `before` can pair with every following `after` until the next `before` or
  `single`.
- Produces editable candidate rows; it does not create final files.

Creator:

- Saves reviewed recipes.
- Creates the publishing job batch in `publishing_jobs`.
- Creates one publishing asset row per selected candidate.
- Generates finished image/video assets from playlist/library ingredients.
- Current Pinterest creator outputs include composite pins, idea pins, and
  idea-palette pins.
- Registers generated files and metadata through repositories.
- On rerun, generated outputs for that job are replaceable before publishing.
- Does not create playlist instances, landing pages, tracked URLs, channels, or
  publication records.

Publisher:

- Takes approved publishing assets.
- Lets the admin choose platform, environment, destination, and CTA page.
- Creates or reuses a compatible playlist instance.
- Creates or reuses the landing page and tracked destination URL.
- Prepares channel-specific publication data.
- Hands each approved asset to `SchedulerService`.
- Stores shared fields in normal columns and platform-specific fields in JSON
  metadata.
- Validates readiness without crashing when auth, board IDs, or scopes are
  missing.
- Owns production locking rules after successful production publication.
- At execution time, calls the selected channel adapter and creates the
  `publications` row after confirmed external success.

Scheduler:

- Owns the persistent publication queue.
- Uses the shared `publication_schedule` table.
- Can list, schedule, reschedule, cancel, run ahead, claim due rows, and record
  attempts.
- Receives one scheduling request per asset.
- Calls `PublisherService` with due `job_id` and `asset_id`.
- Scheduling tracks are publishing channel plus environment, for example:
  `pinterest + test`, `pinterest + production`, `youtube + test`.
- Pinterest boards are destinations inside the Pinterest publisher, not
  separate scheduler tracks.
- Test and production tracks remain independent.
- Max posts per day, minimum spacing, publishing windows, lead time, and slot
  rounding are configured per channel and environment.
- Publisher does not calculate schedule times. When Publisher sends a job
  without a time, Scheduler allocates the next available slot for that
  channel/environment track.
- Running a scheduled job early should compact the remaining future queue for
  the same channel/environment so empty slots are filled.
- Two queue rows may share the same scheduled time when they are on different
  platform/environment tracks.

Channel adapters:

- `PinterestPublisherAdapter` shapes and sends Pinterest Create Pin payloads.
- Future `YouTubePublisherAdapter` should shape and send YouTube
  upload/update payloads.
- Platform adapters read prepared payload data from `PublisherService`.
- Platform adapters return normalized success/failure results.
- Platform adapters must not access the database or decide lifecycle
  transitions.

PublishingJobService:

- Owns shared lifecycle rules.
- Validates job and asset status transitions.
- Enforces test versus production deletion rules.
- Prevents destructive changes to published production records.
- Coordinates full cleanup for unpublished jobs.

Analytics:

- Records ColorFix-side events.
- Accepts `asset_id` attribution from destination URLs.
- Later collects external platform metrics.
- Must keep test and production analytics separate.

### Important Tables

Creator tables:

- `asset_creator_jobs`: saved creator recipe/job.
- `asset_creator_inputs`: source assets used by a job.
- `asset_creator_outputs`: generated assets produced by a job.

Publishing tables:

- `publishing_channels`: platform/account/channel config and metadata.
- `publishing_jobs`: master publishing batch/job.
- `publishing_assets`: one generated channel asset within a publishing job
  (target table; current implementation is still converging from creator
  outputs/publishing-job rows).
- `publications`: one successful external platform post per asset (target
  table; current implementation is still converging from attempt metadata).
- `publisher_attempts`: publish attempts and API responses.
- `publisher_sync_runs`: sync jobs such as Pinterest board sync.

Scheduler tables:

- `publication_schedule`: one persistent queue row per publishing asset.
- `publication_schedule_attempts`: scheduler/executor attempts for queue rows.

Landing/player tables used by publishing:

- `playlist_instances`: instance shown by the public player.
- `landing_pages`: stable public URLs for instances.
- `cta_groups` / CTA Pages: reusable CTA screens assigned to instances.

Important identifiers for publishing/scheduler work:

- `job_id`: publishing batch.
- `asset_id`: one generated channel asset.
- `publication_id`: one successful external platform post.
- `channel`: platform/account family, such as Pinterest or YouTube.
- `environment`: test or production.
- `source_type` and `source_id`: original playlist/article/project.
- `playlist_instance_id`: stable player instance.
- `src=<channel>` and `asset=<asset_id>` in destination URLs.

### Current Files And Entry Points

Scheduler backend:

- `database/migrations/2026_06_22_001_create_publication_scheduler.sql`
- `app/repos/PdoPublicationScheduleRepository.php`
- `app/services/PublicationScheduler.php`
- `app/services/PublicationExecutor.php`
- `api/v2/admin/publication-scheduler/list.php`
- `api/v2/admin/publication-scheduler/schedule.php`
- `api/v2/admin/publication-scheduler/reschedule.php`
- `api/v2/admin/publication-scheduler/cancel.php`
- `api/v2/admin/publication-scheduler/publish-now.php`
- `api/v2/admin/publication-scheduler/run-due.php`
- `scripts/run-publication-scheduler.php`

Pinterest OAuth/publishing backend:

- `app/services/PinterestOAuthService.php`
- `app/services/Publishers/PinterestPublisher.php` (current name; target
  architecture calls this role `PinterestPublisherAdapter`)
- `app/lib/SecretBox.php`
- `api/pinterest-connect.php`
- `api/pinterest-oauth-callback.php`
- `api/v2/admin/publishing/pinterest/auth-status.php`
- `api/v2/admin/publishing/pinterest/sync-boards.php`
- `api/v2/admin/publishing/pinterest/dry-run.php`
- `api/v2/admin/publishing/pinterest/publish-test.php`

Pinterest OAuth scopes currently requested:

- `boards:read`
- `boards:write`
- `pins:read`
- `pins:write`

Pinterest returned a live Create Pin error when `boards:write` was missing,
even though the payload posts to `/pins`. Existing tokens must be reconnected
after scope changes; saved tokens do not gain new scopes retroactively.

Admin UI:

- `/admin/asset-creators`: analyzer, recipes, creator jobs, generated assets.
- `/admin/publisher`: publisher preparation and Pinterest connection status.
- `/admin/scheduler`: queue visibility, timing, cancel, run ahead.
- `/admin/cta-pages`: reusable CTA pages.
- `/admin/ctas`: individual CTA definitions.
- `/admin/playlist-instances`: instance/player inspection and editing.

### Test vs Production Rules

Test:

- Uses the `ColorFix API Test` Pinterest board.
- Does not lock assets.
- Does not lock destination URLs.
- Can be deleted, rebuilt, retried, or replaced.
- May save test platform IDs/URLs in metadata or attempts.
- Must not mark the production publication state as final.

Production:

- Uses the `ColorFix Makeovers` Pinterest board.
- A successful production publish marks the production publication as published.
- A successful production publish locks the stable destination URL.
- Once locked, the URL and landing-page relationship must remain stable.
- Content behind the instance and CTA page can still be edited.
- Published links must be preserved indefinitely.

### Current Limitations / Do Not Assume Done

- Production Pinterest publishing is still intentionally gated.
- The target architecture separates `publishing_jobs`, `publishing_assets`,
  and `publications`. The current deployed implementation is still converging
  toward that split.
- Some current route/service files still need repository-only audits and
  refactors to fully satisfy the architecture rule.
- The scheduler queue, manual scheduling, and channel timing allocator exist.
  Continue testing max-posts-per-day, windows, spacing, and per-track collision
  behavior before production use.
- The scheduler runner exists, but a recurring cron/worker still needs to be
  installed and monitored.
- YouTube and Instagram adapters are not implemented.
- Publisher Settings UI for channels, destinations, and encrypted auth is still
  future work.
- Production lock enforcement should be audited anywhere slugs, landing pages,
  and publisher-created playlist instances can be deleted or changed.

### Next Recommended Steps

1. Finish the schema/service convergence to `publishing_jobs` +
   `publishing_assets` + `publications`.
2. Audit publisher/scheduler/API files for repository-only database access.
3. Verify one queued Pinterest test publish through `/admin/scheduler`.
4. Confirm the returned test Pinterest Pin ID and URL are recorded as test
   metadata/attempts, not production publication fields.
5. Add a cron entry for `scripts/run-publication-scheduler.php`.
6. Audit production lock enforcement across landing pages and playlist
   instances.
7. Enable production Pinterest publish only after the test path is stable.
8. Add Publisher Settings UI for platform config, destinations, and encrypted
   auth management.

## Core Boundary

ColorFix publishing should be split into four separate domains:

1. Library
   - The file catalog.
   - Stores file records for JPG, PNG, MP4, documents, and future asset files.
   - Knows where a file lives and basic metadata.
   - Does not own creator recipes or posting logic.

2. Creator
   - Builds new assets from ingredients and instructions.
   - Fetches source content and library assets.
   - Runs channel/layout-specific creator scripts.
   - Saves the generated file.
   - Registers the generated file in the Library.
   - Records inputs, outputs, and instructions so a job can be rerun.

3. Publisher
   - Posts finished Library assets to channels.
   - Stores live URL, channel status, published timestamp, tracking code, and errors.
   - Uses official APIs when practical.
   - Uses Playwright only when a channel cannot be automated cleanly through an API.

4. Analytics
   - Future module.
   - Pulls/scrapes channel analytics and ColorFix pingbacks.
   - Runs on a recurring schedule after assets have been published.

## Script Families

Creator scripts:

```text
scripts/asset-creators/
  pinterest/
    before-after-pin.mjs
  youtube/
    playlist-video.mjs
  shared/
    libraryClient.mjs
    creatorJobClient.mjs
    imageHelpers.mjs
```

Publisher scripts:

```text
scripts/publishers/
  pinterest/
    publish-pin.mjs
  youtube/
    upload-video.mjs
  site/
    add-playlist-to-set.mjs
  shared/
    publisherJobClient.mjs
    channelAuth.mjs
    retryHelpers.mjs
```

Creator scripts should usually be Node/JS:

- `sharp` for JPG/PNG composites.
- `remotion` for MP4/video.
- plain Node orchestration for DB/API/file work.

Publisher scripts should prefer channel APIs. Playwright is a publisher-side fallback for upload/posting flows or analytics scraping where APIs are not good enough.

## Database Shape

Already added:

- `asset_library`
- `photo_library.asset_library_id`
- legacy/current creator output links such as `publish_outputs.library_asset_id`
  where still present
- target publishing tables: `publishing_jobs`, `publishing_assets`,
  `publications`

Creator tables added in migration:

- `asset_creator_jobs`
- `asset_creator_inputs`
- `asset_creator_outputs`

Creator table purpose:

- `asset_creator_jobs`: recipe/instructions/run record.
- `asset_creator_inputs`: source library assets used by the creator.
- `asset_creator_outputs`: generated library assets returned by the creator.

## Current Routes

Admin pages:

- `/admin/library`: file catalog.
- `/admin/asset-library`: alias for Library.
- `/admin/asset-creators`: creator command center.
- `/admin/publisher`: publishing job/status table.
- `/admin/publishing`: alias for Publisher.

Creator page currently shows:

- creator jobs grid as the default command-center view
- search over saved creator jobs
- `New Creator Job` button
- `Open` and `Run` actions in the job grid
- modal workflow for starting a new creator job
- creator type dropdown
- playlist dropdown using playlists, not playlist instances
- `Analyze` action that proposes recipe pairs without writing anything
- editable proposed pair grid inside the modal
- include checkbox, order, before/after asset fields, Search Title, Description, source/confidence, add/remove controls
- source thumbnails can be clicked to preview larger images
- `Save Recipe` persists a reviewed recipe
- generated outputs are shown in the grid and in the modal

Important current behavior:

- Analyze/proposal is read-only and in-memory.
- It does not create `asset_creator_jobs`.
- It does not create `asset_creator_inputs`.
- It does not create output assets.
- It can be rerun repeatedly.
- `Save Recipe` creates or updates the creator job after user review/edit.
- `Run` / `Create Pin` generates composite JPG pins for saved jobs.

Publisher page currently shows:

- output ID
- channel
- asset type
- library asset ID
- status
- playlist
- instance
- tracking
- live URL
- published timestamp

## Deployment Status

Completed:

- Asset Library migration ran on remote.
- Asset Library page/API deployed and smoke-tested.
- Creator migration `2026_06_08_002_create_asset_creator_tables.sql` was deployed and applied on remote.
- Creator app files were added locally:
  - `app/repos/PdoAssetCreatorRepository.php`
  - `app/services/AssetCreatorService.php`
  - `app/controllers/AssetCreatorController.php`
- Creator API files were added locally:
  - `api/v2/admin/asset-creators/list.php`
  - `api/v2/admin/asset-creators/detail.php`
  - `api/v2/admin/asset-creators/save.php`
  - `api/v2/admin/asset-creators/add-output.php`
- PHP syntax checks passed for creator repo/service/controller/API files.
- Remote creator migration applied successfully.

Smoke-tested:

```bash
curl 'https://colorfix.terrymarr.com/api/v2/admin/asset-creators/list.php'
```

Expected result after deploy:

```json
{"ok":true,"items":[]}
```

Also smoke-tested:

```bash
curl -sS -X POST https://colorfix.terrymarr.com/api/v2/admin/asset-creators/propose.php \
  -H 'Content-Type: application/json' \
  --data '{"creator_key":"pinterest.before_after_composite","source_type":"playlist","playlist_id":37}'
```

That returned a real proposal for playlist `#37`, pairing photo `641` before with photo `642` after from saved palette set `150`.

## Next Build Step

Keep refining the first creator workflow:

`pinterest.before_after_composite`

Current first-pass behavior:

1. Start from a saved creator job.
2. Read playlist-based instructions.
3. Resolve before/after source images from `asset_library_id`, `photo_library_id`, or public URL.
4. Generate one or more 1000 x 1500 composite JPG pins.
5. Save generated files under `/photos/pins/generated/job-{id}/`.
6. Register each generated file in `asset_library`.
7. Replace rows in `asset_creator_outputs`.
8. Keep instructions intact so the job can be edited and rerun.

Remaining creator refinements:

1. Keep syncing new `photo_library` uploads into `asset_library` while both systems coexist.
2. Make output previews easier to inspect.
3. Add safe trash/delete handling for test outputs that have not been published.
4. Add status rules for generated/approved/published/retired assets.
5. Add more creator types after composite pins are stable.

## 2026-06-12 Creator Handoff

Current useful state:

- `/admin/asset-creators` is the current command center for asset creation.
- Composite Pinterest pins are the first runnable creator type.
- Jobs are saved in `asset_creator_jobs`.
- Inputs are tracked in `asset_creator_inputs`.
- Generated pins are registered in `asset_library` and linked through `asset_creator_outputs`.
- Current generated pin format is 1000 x 1500 JPG, with before image on top and after image below.
- Generated files are written to `/photos/pins/generated/job-{jobId}/pin-{jobId}-{pairOrder}-{pairKey}.jpg`.
- Output preview thumbnails in the creator grid can be clicked to open an in-place preview modal; clicking again/closing dismisses it.
- Output preview URLs are cache-busted from generated/updated output data so rerun-check-rerun should show the latest image.

Recent fixes:

- `AssetCreatorRunService` now prefers live `photo_library.rel_path` when a recipe side has `photo_library_id`.
- This fixes old saved recipes whose `public_url` points at a stale photo file after a photo replacement.
- Job `#2` had that exact problem: recipe URL ended in `2ea2d2732400.jpg`, but `photo_library_id = 661` now points to `6567fb326a68.jpg`.
- After deploying the runner/API fix, job `#2` was run through `api/v2/admin/asset-creators/run.php` and succeeded.
- The composite badge renderer now uses a rounded translucent black badge with a white border.
- `public/fonts/Montserrat.ttf` is included in the frontend build and deployed to `/fonts/Montserrat.ttf`.
- `AssetCreatorRunService::findFont()` checks `/fonts/Montserrat.ttf` first, with system font fallbacks.
- There is also a larger GD fallback so BEFORE/AFTER text should not collapse to tiny text if TTF lookup fails.

Important coexistence note:

- Terry is still actively using `photo_library`.
- The new `asset_library` is not yet the only source of truth.
- While both systems coexist, creators must tolerate:
  - recipe sides that only have `photo_library_id`
  - recipe sides with stale `public_url`
  - photo rows not yet synced into `asset_library`
- Do not assume every current or new photo has an `asset_library_id` yet.

Known rough edges / likely next creator work:

1. Keep the generated badge styling iterative; Terry is visually tuning size, opacity, font, and placement.
2. Add a clearer delete/trash flow for test generated assets that are not published.
3. Make the creator job grid easier to work in as more rows appear.
4. Improve created output inspection: preview, public URL, asset ID, and maybe a direct library link.
5. Add a deliberate sync path from `photo_library` to `asset_library` for newly uploaded photos until the old photo library is retired.
6. Timestamp cleanup is still tabled: target standard is store UTC, display browser-local.

Publisher comes after creator:

1. Choose approved generated pin asset.
2. Create or update publish output.
3. Post to Pinterest later.
4. Store live URL and published timestamp.

Pinterest board setup while API access is pending:

- Use `board_name = "ColorFix Makeovers"`.
- Use `board_url = "https://www.pinterest.com/terrymarr/colorfix-makeovers/"`.
- Use `board_slug = "terrymarr/colorfix-makeovers"`.
- Store `board_id = null`.
- Do not require `board_id` for asset generation, queue creation, manual export,
  or publisher setup.
- After Pinterest API access is approved, add a board sync step:
  1. Call Pinterest list boards.
  2. Find the board named `ColorFix Makeovers`.
  3. Store the returned `board_id`.
  4. Allow queued pins to publish.

Pinterest Create Pin API reference:

Pinterest's published Create Pin request shape is:

```json
{
  "alt_text": "string",
  "description": "string",
  "title": "string",
  "link": "string",
  "ai_disclosures": {
    "values": [
      "string"
    ]
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

ColorFix publisher mapping:

- `title`: publishing job title.
- `description`: publishing job description.
- `alt_text`: publishing job alt text, when available.
- `link`: tracked destination URL, not the raw canonical URL.
- `board_id`: Pinterest destination metadata once board sync has populated it.
- `media_source.source_type`: likely `image_url` first, or base64/upload mode if
  Pinterest requires it later.
- `media_source.content_type`: generated media MIME type, usually `image/jpeg`
  for current pin assets.
- `media_source.data`: public media URL or encoded media data, depending on
  selected Pinterest media source mode.

Optional Pinterest fields such as `board_section_id`, `dominant_color`,
`ai_disclosures`, `parent_pin_id`, and `sponsor_id` should stay in
platform-specific metadata/settings until needed. Missing `board_id` blocks API
publishing, but must not block asset generation, publisher setup, queue
creation, or manual export.

Pinterest Pin Analytics API reference:

Single-pin request shape:

```bash
curl --location --request GET 'https://api.pinterest.com/pins/{pin_id}/analytics?' \
  --header 'Content-Type: application/json' \
  --header 'Authorization: Bearer <Add your token here>'
```

Single-pin response shape:

```json
{
  "property1": {
    "daily_metrics": [
      {
        "data_status": "READY",
        "date": "string",
        "metrics": {
          "property1": 0,
          "property2": 0
        }
      }
    ],
    "lifetime_metrics": {
      "TOTAL_COMMENTS": 10,
      "TOTAL_REACTIONS": 12
    },
    "summary_metrics": {
      "IMPRESSION": 240,
      "OUTBOUND_CLICK": 20,
      "PIN_CLICK": 37,
      "QUARTILE_95_PERCENT_VIEW": 8,
      "SAVE": 20,
      "SAVE_RATE": 0.18,
      "VIDEO_10S_VIEW": 2,
      "VIDEO_AVG_WATCH_TIME": 2507.75,
      "VIDEO_MRC_VIEW": 20,
      "VIDEO_START": 29,
      "VIDEO_V50_WATCH_TIME": 10031
    }
  },
  "property2": {
    "daily_metrics": [
      {
        "data_status": "READY",
        "date": "string",
        "metrics": {
          "property1": 0,
          "property2": 0
        }
      }
    ],
    "lifetime_metrics": {
      "TOTAL_COMMENTS": 10,
      "TOTAL_REACTIONS": 12
    },
    "summary_metrics": {
      "IMPRESSION": 240,
      "OUTBOUND_CLICK": 20,
      "PIN_CLICK": 37,
      "QUARTILE_95_PERCENT_VIEW": 8,
      "SAVE": 20,
      "SAVE_RATE": 0.18,
      "VIDEO_10S_VIEW": 2,
      "VIDEO_AVG_WATCH_TIME": 2507.75,
      "VIDEO_MRC_VIEW": 20,
      "VIDEO_START": 29,
      "VIDEO_V50_WATCH_TIME": 10031
    }
  }
}
```

Multiple-pin request shape:

```bash
curl --location --request GET 'https://api.pinterest.com/pins/analytics?' \
  --header 'Content-Type: application/json' \
  --header 'Authorization: Bearer <Add your token here>'
```

Multiple-pin response shape:

```json
{
  "property1": {
    "property1": {
      "daily_metrics": [
        {
          "data_status": "READY",
          "date": "string",
          "metrics": {
            "property1": 0,
            "property2": 0
          }
        }
      ],
      "lifetime_metrics": {
        "TOTAL_COMMENTS": 10,
        "TOTAL_REACTIONS": 12
      },
      "summary_metrics": {
        "IMPRESSION": 240,
        "OUTBOUND_CLICK": 20,
        "PIN_CLICK": 37,
        "QUARTILE_95_PERCENT_VIEW": 8,
        "SAVE": 20,
        "SAVE_RATE": 0.18,
        "VIDEO_10S_VIEW": 2,
        "VIDEO_AVG_WATCH_TIME": 2507.75,
        "VIDEO_MRC_VIEW": 20,
        "VIDEO_START": 29,
        "VIDEO_V50_WATCH_TIME": 10031
      }
    },
    "property2": {
      "daily_metrics": [
        {
          "data_status": "READY",
          "date": "string",
          "metrics": {
            "property1": 0,
            "property2": 0
          }
        }
      ],
      "lifetime_metrics": {
        "TOTAL_COMMENTS": 10,
        "TOTAL_REACTIONS": 12
      },
      "summary_metrics": {
        "IMPRESSION": 240,
        "OUTBOUND_CLICK": 20,
        "PIN_CLICK": 37,
        "QUARTILE_95_PERCENT_VIEW": 8,
        "SAVE": 20,
        "SAVE_RATE": 0.18,
        "VIDEO_10S_VIEW": 2,
        "VIDEO_AVG_WATCH_TIME": 2507.75,
        "VIDEO_MRC_VIEW": 20,
        "VIDEO_START": 29,
        "VIDEO_V50_WATCH_TIME": 10031
      }
    }
  },
  "property2": {
    "property1": {
      "daily_metrics": [
        {
          "data_status": "READY",
          "date": "string",
          "metrics": {
            "property1": 0,
            "property2": 0
          }
        }
      ],
      "lifetime_metrics": {
        "TOTAL_COMMENTS": 10,
        "TOTAL_REACTIONS": 12
      },
      "summary_metrics": {
        "IMPRESSION": 240,
        "OUTBOUND_CLICK": 20,
        "PIN_CLICK": 37,
        "QUARTILE_95_PERCENT_VIEW": 8,
        "SAVE": 20,
        "SAVE_RATE": 0.18,
        "VIDEO_10S_VIEW": 2,
        "VIDEO_AVG_WATCH_TIME": 2507.75,
        "VIDEO_MRC_VIEW": 20,
        "VIDEO_START": 29,
        "VIDEO_V50_WATCH_TIME": 10031
      }
    },
    "property2": {
      "daily_metrics": [
        {
          "data_status": "READY",
          "date": "string",
          "metrics": {
            "property1": 0,
            "property2": 0
          }
        }
      ],
      "lifetime_metrics": {
        "TOTAL_COMMENTS": 10,
        "TOTAL_REACTIONS": 12
      },
      "summary_metrics": {
        "IMPRESSION": 240,
        "OUTBOUND_CLICK": 20,
        "PIN_CLICK": 37,
        "QUARTILE_95_PERCENT_VIEW": 8,
        "SAVE": 20,
        "SAVE_RATE": 0.18,
        "VIDEO_10S_VIEW": 2,
        "VIDEO_AVG_WATCH_TIME": 2507.75,
        "VIDEO_MRC_VIEW": 20,
        "VIDEO_START": 29,
        "VIDEO_V50_WATCH_TIME": 10031
      }
    }
  }
}
```

The multiple-pin analytics response is nested by returned pin/property keys, so
the analytics importer should not assume fixed names like `property1`. It should
iterate all top-level keys, then all nested metric groups.

Later analytics work should store the returned metrics against the publishing
asset and external Pinterest pin ID. Preserve daily metrics, lifetime metrics,
summary metrics, data status, and sync timestamp so ColorFix can compare
Pinterest-side engagement with landing-page traffic attributed by
`src=pinterest&asset={asset_id}`.

## Publish Infrastructure Tables

Publisher infrastructure keeps shared fields in normal columns and
platform-specific API details in `metadata_json`.

- `publishing_channels`: one external destination/account/config. Multiple
  Pinterest rows can point at different boards, and future YouTube rows can use
  different API/account settings. Auth payloads belong here as encrypted blobs,
  never as raw token columns.
- `publishing_jobs`: master publishing batch/job.
- `publishing_assets`: one generated channel asset in a publishing job, using
  common columns such as `platform`, `source_type`, `source_id`, `asset_type`,
  `title`, `description`, `image_url`, `destination_url`, and `status`.
- `publications`: one confirmed external platform post per publishing asset.
- `publisher_attempts`: each API publish attempt, request/response payloads,
  retry timing, external ids/URLs, and errors.
- `publisher_sync_runs`: API sync jobs such as Pinterest list-boards.

Do not add channel-specific DB columns like `pinterest_board_id` or
`youtube_playlist_id` to the core queue. Use `metadata_json`:

- Pinterest asset/channel metadata: `board_id`, `board_name`, `board_url`,
  `board_slug`.
- YouTube asset/channel metadata: `video_path`, `thumbnail_path`,
  `youtube_channel_id`, `youtube_playlist_id`, `privacy_status`,
  `category_id`, `tags`, `made_for_kids`.

Publisher services own workflow boundaries. Channel adapters such as
`PinterestPublisherAdapter` and future `YouTubePublisherAdapter` read prepared
asset fields plus their metadata/settings and shape/send the API-specific
request payload outside the DB layer.

## Publisher Environments And Locking

Publisher supports two environments:

- `test`: expendable publishing targets for QA and preview.
- `production`: public/out-in-the-wild publishing targets.

Platform examples:

- Pinterest test uses a secret/private board.
- Pinterest production uses the public destination board.
- YouTube test uses an unpublished/private/unlisted state.
- YouTube production uses the public release settings.

Publisher locking is the core safety rule.

When a publishing job is posted to a production channel, the publisher locks
the permanent landing destination it points to. The lock protects the public URL
and the playlist instance relationship because that URL may now exist on an
external platform.

After lock:

- the landing URL must remain stable and undeletable
- the playlist instance-to-landing URL relationship must remain stable
- destructive slug changes are blocked
- deleting the landing record is blocked
- content behind the URL may still change
- slides inside the playlist instance may still be edited
- CTA buttons inside the assigned CTA page may still be edited

Before production publication, and for test-environment outputs:

- assets are expendable
- test board posts are expendable
- unpublished/private YouTube test posts are expendable
- publisher records may be replaced, retried, or deleted as needed
- playlist instances and landing URLs are not locked merely because a test
  export or test post exists

Only production publishing creates the long-lived public-link obligation.

## Future Publisher Settings Admin

Add a dedicated Publisher Settings admin page when the first API auth work is
ready.

That page should let admins manage publisher configuration without touching
creator/analyzer assets:

- platforms, such as Pinterest, YouTube, and future Instagram
- platform accounts/configs
- encrypted auth payloads and token metadata
- API base URLs
- account names and external account IDs
- platform status, such as active, pending API access, disabled, or expired auth
- destinations under each platform/account
- destination environment: `test` or `production`
- destination status and readiness

Pinterest destinations should support:

- board name
- board URL
- board slug
- board ID when returned by the Pinterest API
- test board, such as `ColorFix API Test`
- production board, such as `ColorFix Makeovers`

YouTube destinations should support:

- YouTube channel ID
- playlist ID
- privacy status
- category ID
- tags
- thumbnail defaults
- made-for-kids setting
- test/unpublished settings
- production publish settings

Auth fields must remain encrypted in the database. The UI may show connection
status, expiry, account labels, and sync state, but should not expose raw
tokens.

This settings page is configuration only. It does not create creator assets.
Finished creator assets remain reusable; publisher setup chooses the platform
and destination at the publishing stage.

## Publisher Responsibilities Roadmap

The publisher takes finished creator assets and turns them into
platform-ready, trackable, publishable records.

1. Receive finished assets

   Input comes from the creator. Each asset already has:

   - image or video file
   - source playlist/article/project
   - asset type
   - title
   - description
   - alt text, when available

   The publisher does not redesign the asset.

2. Choose the publishing channel

   Assign the asset to a configured platform channel, such as:

   - Pinterest
   - YouTube
   - future Instagram

   The channel determines platform settings, auth, defaults, and publishing
   behavior. Board names, YouTube playlists, privacy modes, and other final
   posting targets are destinations/settings inside that platform channel.
   The channel also includes the publishing environment: `test` or
   `production`.

3. Find or create the audience-specific playlist instance

   For playlist-based assets, the publisher must:

   - identify the source playlist
   - find an existing compatible Pinterest playlist instance
   - create one if none exists
   - avoid creating one instance per Pin
   - reuse the same Pinterest instance for all Pins from that playlist

4. Attach the correct CTA screen

   For Pinterest, attach the reusable Pinterest CTA screen.

   That screen currently includes:

   - Replay
   - Share
   - See Colors Used, when applicable
   - Explore ColorFix
   - Get Your Own Color Makeover
   - Ask Terry a Question

   The publisher attaches the CTA screen by ID or stable key. It does not copy
   the CTA items into every instance.

5. Create or retrieve the permanent landing URL

   The publisher creates the stable public URL for the playlist instance.

   Example:

   ```text
   https://colorfix.terrymarr.com/s/cottage-exterior-ideas
   ```

   That URL belongs to the playlist instance, not to an individual Pin.

6. Create the tracked publishing asset URL

   Every Pin from the same playlist uses the same landing page, with its own
   publishing asset tracking parameter.

   Example:

   ```text
   https://colorfix.terrymarr.com/s/cottage-exterior-ideas?src=pinterest&asset=123
   ```

   The publisher must:

   - preserve `src=pinterest`
   - append the publishing asset ID
   - avoid generating a separate landing page per Pin

7. Create the publisher record

   For each finished creator asset, store:

   - platform channel
   - environment
   - platform
   - source record
   - playlist instance
   - CTA screen
   - canonical destination URL
   - tracked destination URL
   - board or platform destination
   - title
   - description
   - alt text
   - public media URL
   - publishing status

8. Apply platform-specific settings

   For Pinterest:

   - environment-specific board destination
   - board name
   - board URL
   - board slug
   - board ID when available
   - Pin title
   - Pin description
   - destination link
   - image source
   - optional alt text

   For YouTube later:

   - environment-specific publish/privacy mode
   - channel ID
   - playlist ID
   - privacy status
   - category
   - tags
   - thumbnail
   - made-for-kids setting

   Platform-specific fields should live in channel settings or metadata, not
   contaminate the shared core model unnecessarily.

## Publication Scheduler Tracks

The publication scheduler uses one shared `publication_schedule` table, but
cadence rules are not global. Scheduling rules are applied independently per
platform channel and environment.

ColorFix scheduling tracks are:

- `pinterest` + `test`
- `pinterest` + `production`
- `youtube` + `test`
- `youtube` + `production`
- future `instagram` + environment

Pinterest boards are destinations inside the Pinterest publisher, not separate
scheduler tracks. A Pinterest test board and Pinterest production board stay
separate because their environments are separate. Additional Pinterest boards
still use the Pinterest scheduling track unless ColorFix later creates a truly
separate Pinterest account/channel.

Normal scheduler flow is queue-based. Publisher rows enter the scheduler as
`waiting` work with no fixed time. When the scheduler wakes up, it checks the
selected platform channel and environment track, then claims the next eligible
row as `processing`.

The scheduler must apply:

- max posts per day
- minimum spacing
- publishing windows
- collision checks
- mix rules by creator job, source playlist, and pin type
- duplicate cooldown checks for exact pins already published in the previous
  90 days

against that selected track. Exact pin identity is based on generated
`asset_library_id` first, creator output id second, and media URL/path as a
fallback.

Manual exact scheduling is still allowed for overrides. Two tasks may share the
same `scheduled_at` when they belong to different platform channels or different
environments.

Manual `Run Now` bypasses timing, mix, and duplicate-cooldown rules because it is
an explicit admin command.

Queue reporting can show every platform together, but the scheduler must never
apply one global cadence across Pinterest, YouTube, Instagram, or other future
platform channels.

9. Validate readiness

   Before publishing, verify:

   - media file exists
   - public media URL is reachable
   - title exists
   - description exists
   - destination URL exists
   - playlist instance exists
   - CTA screen is attached
   - required platform destination exists
   - auth is available

   If auth or board ID is missing, the publisher should not crash.

10. Manage publishing status

    Typical states:

    - draft
    - ready_for_review
    - ready_to_publish
    - pending_pinterest_auth
    - published
    - failed

    The publisher owns movement through these states.

11. Support manual publishing

    Until Pinterest approval arrives, the publisher should provide a manual
    export screen with:

    - image
    - title
    - description
    - destination URL
    - board name
    - status

    That allows manual posting without rebuilding anything.

12. Publish through the platform API

    Once auth exists, the platform adapter should:

    - build the API request
    - send the asset
    - receive the platform response
    - save the returned platform post ID
    - save the published URL
    - save the publication time
    - mark the asset published
    - lock the landing destination only when publishing to `production`

13. Handle errors safely

    On failure, the publisher should:

    - keep the asset record
    - store the error
    - avoid duplicate posts
    - allow retry
    - distinguish auth errors from payload errors
    - not recreate playlist instances or destination URLs unnecessarily

14. Preserve idempotency

    Running the same publishing preparation twice must not create duplicates.

    It should reuse:

    - the same playlist instance
    - the same CTA screen
    - the same destination URL
    - the same publishing job record where appropriate

15. Lock permanent destinations after publication

    Once an asset using a destination has been published to a production
    channel:

    - keep the URL stable
    - prevent destructive slug changes
    - prevent deletion of the landing record
    - keep the playlist instance relationship stable
    - allow content behind the URL to change
    - allow slides in the playlist instance to change
    - allow CTA buttons in the assigned CTA page to change
    - preserve published links indefinitely

    Test-environment posts do not create this lock. They remain replaceable and
    disposable until a production publish occurs.

16. Record analytics context

    The publisher must make sure landing traffic can be attributed to:

    - source platform
    - publishing job
    - playlist
    - playlist instance
    - subsequent Browse/Watch Next activity

    That is how we will know which specific Pin caused a playlist view.

## 2026-06-10 Creator Script Starter

Added local creator-script structure:

- `scripts/asset-creators/README.md`
- `scripts/asset-creators/registry.mjs`
- `scripts/asset-creators/run.mjs`
- `scripts/asset-creators/shared/creatorContract.mjs`
- `scripts/asset-creators/shared/creatorApiClient.mjs`
- `scripts/asset-creators/pinterest/before-after-composite/index.mjs`

Added npm commands:

```bash
npm run asset-creators:list
npm run asset-creators:run -- --creator=pinterest.before_after_composite --phase=propose
```

Original script-starter status:

- `pinterest.before_after_composite` is registered and callable.
- The `propose` phase returns a valid proposal result shape.
- API-side pair proposal is wired for the admin UI.
- Proposal endpoint: `api/v2/admin/asset-creators/propose.php`.
- Proposal service: `app/services/AssetCreatorProposalService.php`.
- First-pass pair detection uses saved palette set photo roles:
  - `photo_type = before`
  - paired with `photo_type = full`, `main`, or `after`
  - ignores zoom/detail photos
  - falls back to low-confidence playlist-order pairs only when no saved palette set pairs exist
- Image generation is not wired yet.

Completed after this step:

1. Added `Save Recipe` in the creator modal.
2. Saved reviewed pair instructions into `instructions_json`.
3. Added `Open` to reload/edit saved jobs.
4. Added `Run` / `Create Pin`.
5. Generated composite JPGs through PHP/GD for now.
6. Registered generated JPGs in `asset_library`.
7. Added `asset_creator_outputs` rows.
8. Allowed rerun from the command center using the saved recipe.

Still pending from the original list:

1. Consider moving generation into the Node creator script once the layout is stable.
2. Add delete/retire handling for test outputs that are not published.
3. Add publisher-side Pinterest posting.

## Timestamp Standard Handoff

Decision:

- Standardize on: store UTC, display local.
- This should apply to events, analytics, created/updated timestamps, publisher timestamps, creator runs, and future automation jobs.
- This is especially important for view counts and future channel analytics because viewers may be in different timezones.

Target rules:

1. Database/session time should be UTC:
   - `SET time_zone = '+00:00'`
   - SQL `NOW()` should mean UTC.
2. PHP write helpers should be explicit:
   - Add/keep `AppTime::utcNow()` for database writes.
   - Keep any local/Pacific helper clearly named, such as `pacificNow()` or `localNow()`, only for user-facing defaults.
3. API responses should expose timestamp values as UTC, preferably ISO strings with `Z`:
   - example: `2026-06-12T14:05:00Z`
4. React/UI should use one shared formatter that treats raw DB timestamps as UTC and displays in browser local time unless a page explicitly asks for Pacific.
5. Do not slice timestamp strings in the UI for display. Parse and format them.

Known current inconsistency:

- `user_events` currently writes UTC explicitly, which is good.
- Some newer code uses `AppTime::now()`, which currently means America/Los_Angeles, not UTC.
- Many repos still use SQL `NOW()`. This is acceptable only after the DB session is UTC.
- `api/db.php` was recently changed to set the DB session to Pacific; that should be changed to UTC as part of the timestamp cleanup.
- Some admin pages print raw timestamps or slice strings. Those need shared local display formatting.

Recommended cleanup order:

1. Change `api/db.php` DB session to UTC.
2. Update `AppTime` with explicit UTC/local method names.
3. Convert creator/publisher/event-style repo writes to UTC helpers or UTC SQL.
4. Add a shared frontend date formatter.
5. Update visible admin pages that show `created_at`, `updated_at`, `last_run_at`, `published_at`, and event times.
6. Do not bulk-convert historical rows until we know which tables were written under which timezone. Treat old rows as historical and table-specific.
