# ColorFix Publishing Pipeline Plan

Last updated: 2026-06-12

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
- `publish_outputs.library_asset_id`
- `publish_jobs`
- `publish_outputs`

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
- `/admin/publisher`: publishing asset/status table.
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

## Publish Infrastructure Tables

Publisher infrastructure keeps shared fields in normal columns and
platform-specific API details in `metadata_json`.

- `publishing_channels`: one external destination/account/config. Multiple
  Pinterest rows can point at different boards, and future YouTube rows can use
  different API/account settings. Auth payloads belong here as encrypted blobs,
  never as raw token columns.
- `publisher_assets`: platform-neutral publish queue/assets using common
  columns: `platform`, `source_type`, `source_id`, `asset_type`, `title`,
  `description`, `image_url`, `destination_url`, and `status`.
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

Publisher services are platform boundaries. `PinterestPublisher` and future
`YouTubePublisher` read common asset fields plus their metadata/settings and
shape the API-specific request payload outside the DB layer.

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
