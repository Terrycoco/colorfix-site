UPDATED: 2026-11-21 12:05:44 PST

# PUB — Department Manifest

**Department:** `PUB`

**Location:** `app/PUB/`

**Namespace:** `App\PUB`

**Status:** Current implementation inventory and architectural audit. Exceptions and recommendations below are not implemented or newly approved architecture.

**Source reviewed:** Department PHP; API doorbells and bootstrap/authentication; Admin routes and consumers; Remotion compositions; local rendering, export, worker and dispatch scripts; Vite configuration; related departmental consumers; archived migrations; and existing tests. This is a repository audit, not certification of deployment, live schema, cron, credentials, remote publications, or worker availability.

**Governing-document caveat:** The current working-tree `SystemManifest.md` contains an earlier REX manifest rather than the system constitution. It was read in full and left unchanged. The committed `HEAD:SystemManifest.md` supplies the departmental ownership, thin-doorbell, transaction and active-PUB-rendering rules used here. PHOTOS and REX manifests supply the numbered inventory format; PHOTOS explicitly limits its own source review to a supplied archive. This manifest verifies its PUB claims independently against repository source.

## 1. Mission and ownership

PUB turns existing content into publication products, prepares channel packages, controls release, and ships to Pinterest and YouTube. It currently owns analysis/proposals, production orders, generated publication media, approval state, packaging, queues and cadence, provider authentication and destinations, shipment receipts, video-job coordination, and publishing-specific recovery procedures.

The conceptual pipeline is **ANALYZE → CREATE → APPROVAL → PACKAGE → SCHEDULE → DISPATCH**. There are five stage managers, not six: approval is an asset flag edited through the Assets endpoint, not a separate manager. Schedule both selects work and calls Dispatch; it is not merely a passive decision DTO.

**Does not own:** Photo Library originals, source-photo replacement/deletion or crops; saved palettes or Palette Viewer content; playlist slides, playback, experience definitions or publication flags; REX token identity/resolution policy; SAS recipients/messages; ANA event storage/filtering; SYSTEM sessions; or ERR incident storage and generic recovery orchestration. PUB owns a publication order referring to these inputs, not the inputs themselves.

**CHEF distinction:** Current Pinterest and YouTube recipes, video blueprints/layers, Remotion execution, generated thumbnails and Mac render jobs are active PUB functionality. They are unrelated to retired Masks, Applied Palettes, base-photo/`photo_variants` rendering and Palette Sets. A recipe filename alone does not establish retirement: `api/functions/recipe-utils.php`, for example, concerns color/palette recipes rather than PUB media production.

## 2. Core invariants

1. **Authority:** Managers authorize stage transitions; specialists perform product/channel work. `Contracts/PubContract.php` describes routes, ingredients, defaults and stage expectations. Actual guards and writes must also be inspected; comments/contracts do not automatically enforce them.
2. **Prepared inputs:** ANALYZE prepares neutral playlist data once. Individual analyzers choose relevant `pin`/`yt` items; CREATE consumes prepared ingredients rather than rediscovering playlist content.
3. **Identity:** `pub_asset_id` identifies a production unit. `pub_run_id` identifies an analysis run; it is not a playlist or shipment ID. Source/type/photo identity drives existing-production matching; display `sort_order` is not a stable identity.
4. **Explicit reruns:** Existing in-house work requires an explicit replacement decision; shipped work requires an explicit new-version decision. Production signatures compare ingredients. Replacement does not authorize changing source content or reusing a shipped ID as a new publication.
5. **Approval and custody:** A new/recreated asset is unapproved. Approval is allowed on `created` assets. Packaging checks approval; a sealed package is held as `packed` until explicitly queued. Dispatch claims eligible assets before transport.
6. **Product versus transport:** Creators/recipes decide media composition. Packagers construct channel payloads. Shippers execute provider protocols. The generic Remotion renderer consumes a supplied plan; it should not choose a publication product.
7. **Source protection:** PUB output disposal must not unlink a Photo Library source. Existing paths call PHOTOS through `PdoPubAssetRepository::assertNotPhotoLibraryFile()`; that protection is not a complete transactional/shared-output disposal guarantee.
8. **Stable destinations:** Normal Pinterest packages attach `src=pin` to the destination. Packaging can recover an existing canonical Public playlist REX but does not create one. Dispatch protects the source Public REX tree before shipping; REX owns the reservation/lock implementation.
9. **Completion:** A worker claim is not a completed asset; a detached shipper launch is not a completed shipment. Only successful settlement/finalization promotes those outcomes. A top-level batch `ok` can coexist with individual failures.
10. **Safety boundaries:** Documentation does not authorize sending, deleting, migrating credentials or replaying unknown-outcome shipments. Shared PDO transaction ownership, cross-department writes and external side effects require explicit design; current exceptions are recorded below.

## 3. Directory and file inventory

Paths in this section are relative to `app/PUB/`. Grouped entries enumerate the current source files; grouping is not a claim that every file is an implemented interface.

| Location | Current responsibility and status |
| --- | --- |
| `Contracts/PubContract.php` | Implemented static contract, asset catalog, stage/specialist routing, ingredient bindings, defaults and playlist market-run definition. |
| `Analyze/AnalyzeManager.php` | Implemented run/source preparation, specialist coordination, proposal stamping and partial-failure reporting. |
| `Analyze/Sources/PlaylistSourcePreparer.php`; `Analyze/Support/DefaultPantry.php` | Playlist/PV/photo projection and preparation of default audio from `asset_library`. |
| `Analyze/Pinterest/CompositeAnalyzer.php`, `BeforeAfterVideoAnalyzer.php`, `IdeaAnalyzer.php`, `PaletteAnalyzer.php`, `TeaserAnalyzer.php` | Current Pinterest proposal specialists. |
| `Analyze/Pinterest/Support/TransformationPairFinder.php` | Current ordered Before/After pairing helper. |
| `Analyze/Pinterest/Support/PlaylistPaletteResolver.php` | Older REX/Viewer-based discovery, including token creation; referenced by the older analysis doorbell, not the current manager's neutral-source preparation. |
| `Analyze/YouTube/PlaylistVideoAnalyzer.php` | Current YouTube playlist-video preparation and proposal. |
| `Analyze/Metadata/ PinterestMetadataHelper.php`; `Analyze/Metadata/PdoPinterestMetadataRepository.php` | Zero-byte placeholders. The first filename actually begins with a space. No metadata interface is implemented here. |
| `Analyze/Pinterest.zip` | Archive containing analyzer copies and macOS metadata; not loaded as PHP source. No active import was found. |
| `Create/CreateManager.php`; `Create/CreateBoxValidator.php` | Intake, matching/replacement decisions, reservation, specialist coordination, recreation, asynchronous settlement and input assertions. |
| `Create/Pinterest/CompositeCreator.php`, `BeforeAfterVideoCreator.php`, `IdeaCreator.php`, `PaletteCreator.php`, `TeaserCreator.php` | Active image/video creators. `BeforeAfterVideoRecipe.php` supplies the current video plan; `Support/PinterestCreatorTools.php` supplies image-production primitives. |
| `Create/Pinterest/YoutubeTeaserCreator.php` | Readiness/preflight scaffold; no `create()` implementation or current manager registration. It is not the active `TeaserCreator`. |
| `Create/YouTube/PlaylistVideoCreator.php`; `PlaylistVideoRecipe.php` | Active YouTube production, blueprint construction, render-job creation and finished-video/thumbnail promotion; recipe settings and timing. |
| `Create/Video/PdoVideoJobRepository.php`; `VideoJobFileService.php`; `VideoWorkerHealthService.php` | Current queue persistence, server-side upload staging and heartbeat/readiness. |
| `Create/Video/Support/VideoCreatorTools.php`, `VideoLayerBuilder.php`, `YouTubeDurationEstimator.php` | Shared plan primitives, blueprint translation and preflight duration estimate. |
| `Create/Video/RenderJobFileService.php` | Similar staging implementation using `storage/pub-render-jobs`, with no current caller found. Active uploads use `VideoJobFileService` and `storage/pub-video-jobs`. |
| `Create/Video/VideoRendererInterface.php`; `LocalRemotionRenderer.php` | Empty interface/class scaffolds, not the active Mac renderer. |
| `Package/PackageManager.php`; `Package/Pinterest/PinterestImagePackager.php`, `PinterestVideoPackager.php`; `Package/YouTube/YouTubeVideoPackager.php` | Current package coordination and channel/MIME specialists. |
| `Schedule/ScheduleManager.php` | Queue/dequeue, configuration, release selection, Send Now and Dispatch handoff. |
| `Dispatch/DispatchManager.php`; `DispatchShipmentResult.php`; `PublishNotifier.php`; `ChannelConnectionContract.php` | Shipping custody, settlement/recovery, completed/in-progress result contract, notifications and channel connection compatibility interface. |
| `Dispatch/Pinterest/PinterestImageShipper.php`, `PinterestVideoShipper.php`, `PinterestShippingConfig.php`, `PinterestConnectionService.php` | Pinterest transport, board destination configuration, connection wrapper and board sync. |
| `Dispatch/YouTube/YouTubeVideoShipper.php`; `YouTubeShippingConfig.php` | YouTube resumable video transport, thumbnail upload and shipping policy. |
| `Dispatch/Auth/ChannelAuthContract.php`, `PdoPubChannelAuthRepository.php`, `PinterestAuthService.php`, `YouTubeAuthService.php`, `PinterestAuthConfig.php`, `YouTubeAuthConfig.php` | Current OAuth contracts/configuration, encrypted credentials, refresh/status/test/disconnect. |
| `Dispatch/Driver/DispatchDesk.php`, `DispatchDriverJob.php`, `DispatchDriverHandle.php`, `DispatchDriverLauncher.php`, `DispatchDriverRunner.php`, `DispatchDriverRouteContract.php`, `DispatchFailureDetailProvider.php` | Encoded job ticket, background launch/timeout, route execution, completion/failure desk and provider diagnostic contract. |
| `Endpoints/AnalyzeEndpoint.php`, `CreateEndpoint.php`, `PubAssetsEndpoint.php`, `PackageEndpoint.php`, `ScheduleEndpoint.php`, `DispatchEndpoint.php`, `ChannelConnectionEndpoint.php`, `PubErrorsEndpoint.php` | Current administrative HTTP adapters; some still contain orchestration/disposal beyond request adaptation. |
| `Endpoints/PinterestOAuthStartEndpoint.php`, `PinterestOAuthCallbackEndpoint.php`, `YouTubeOAuthStartEndpoint.php`, `YouTubeOAuthCallbackEndpoint.php`, `VideoJobCompletionEndpoint.php` | OAuth redirects/callbacks and worker completion adapter. |
| `Repos/PdoPubAssetRepository.php`, `PdoPubRunRepository.php`, `PdoPubScheduleRepository.php`, `PdoPubChannelConnectionRepository.php`, `PdoPubErrorRepository.php` | Current persistence and administrative projections. Asset repository also contains state/editability and ingredient-binding policy, beyond SQL alone. |
| `Services/PubRunService.php` | Run creation, stamping, counts, final status and older overwrite operations. |
| `Support/ProductionSignature.php` | Ingredient normalization/hash used to identify produced versions. |
| `PubCom/PubComSignal.php`, `PubComDisposition.php`, `PubComChannel.php`, `PubComWorkerContract.php`, `PubComManagerContract.php` | Specialist readiness/preflight signals and manager policy decisions. |
| `Errors/PubErrorReporter.php`, `PubErrorPolicy.php`, `PubErrorCode.php`, `CriticalErrorNotifier.php`, `PinterestApiException.php` | Structured logs, optional database/error-mail sink, severity policy and provider diagnostics. |
| `Errors/PubErrorHandler.php`; `RecoveryProvider.php`; `Steps/CheckVideoWorker.php`, `RetryCreate.php`, `RetryDispatch.php`, `LogDispatchAttempt.php`, `RequeueDispatchAsset.php` | PUB-owned procedures integrated with ERR, including bounded known-provider recovery. |
| `History/PdoPublicationHistoryRepository.php` | Empty class. Current scheduling history is read by `PdoPubAssetRepository`, not this scaffold. |
| `DTO/AnalysisProposal.php`, `CreatedAsset.php`, `PublicationPackage.php`, `QueuedAsset.php`, `PublishedAsset.php` | Empty classes with conceptual comments. Current stage interfaces exchange arrays; these are not implemented DTO pipelines. |

### Code outside the umbrella

| Location | Current role |
| --- | --- |
| `api/v2/admin/pub/` | Administrative adapters, compatibility orchestration, CLI clock and shared-secret worker handlers; details in section 5. |
| `oauth-google.php` | Public YouTube OAuth callback bootstrap to the PUB endpoint. |
| `src/pages/AdminPubPage/`; `src/routes/AdminRoutes.jsx` | `/admin/pub` stage UI, asset editors, connection controls, contracts/reference view and error/recovery integration. |
| `src/remotion/index.jsx`, `root.jsx`, `GenericVideo.jsx`, and related Remotion components | Active generic renderer/compositions and still-registered older YouTube composition. Runtime media execution is distinct from source-photo manipulation. |
| `scripts/pub-video-worker.mjs`; `start-pub-video-worker.sh`; `setup-pub-video-worker-secret.sh` | Current Mac listener, local launch and private-secret setup. |
| `scripts/render-remotion-video.mjs` | Current generic plan executor invoking Remotion; used by the Mac listener. |
| `scripts/pub-dispatch-driver.php` | CLI-only background shipping runner and settlement entry point. |
| `vite.config.js` | Development-only local render middleware containing product-specific translation/dispatch. This is outside the approved business-logic owner. |
| `scripts/render-youtube-video.mjs`; `export-youtube-draft.mjs`; `scripts/services/YoutubeDraftExportService.mjs`; `src/pages/YoutubePlayerPage/`; `app/services/YoutubeVideoExperienceService.php`; `api/v2/youtube-video-playlist.php` | Separate older preview/manual export path, still reachable through routes/npm/CLI. Not the current factory/worker path; not proven safe to retire solely from lack of factory callers. |

## 4. Supported products and stage contracts

### Implemented product routes

`PubContract::marketRun('playlist')` and `AnalyzeManager` currently support playlist sources. This does not imply arbitrary source types can be published merely because a repository has generic `source_type` columns.

| Analysis output | Stored asset type | Creator key | Active production |
| --- | --- | --- | --- |
| `composite` | `pin_composite` | `pinterest.composite` | Pinterest Before/After composite image. |
| `before_after_video` | `pin_before_after_video` | `pinterest.before_after_video` | Pinterest Before/After video. |
| `idea` | `pin_idea` | `pinterest.idea` | Pinterest single-photo image. |
| `idea_palette` | `pin_idea_palette` | `pinterest.idea_palette` | Pinterest photo plus linked PV colors. |
| `teaser` | `pin_teaser` | `pinterest.teaser` | Authored teaser photo/title image; optional, not a new YouTube teaser video. |
| `youtube_video` | `youtube_video` | `youtube.playlist_video` | Playlist-derived YouTube video plus thumbnail. |

Package and Dispatch select `pinterest.image`, `pinterest.video`, or `youtube.video` from channel/MIME, not by rerunning creator selection. Pinterest static creators use image/GD production; video creators prepare generic render plans. The Before/After recipe produces 1000×1500 at 30 fps; the YouTube recipe produces 1920×1080 at 30 fps. Current generic composition identity is `colorfix-generic-video`; older `colorfix-youtube-video` remains registered separately.

### Actual persisted states and approvals

| Stage | Implemented state/control | Important distinction |
| --- | --- | --- |
| Analyze | Run plus returned Boxes/proposals | No durable `pub_assets` row is required merely to analyze. Each analysis starts a fresh run. |
| Create | `creating` → `created`, or `error`; edits may require `redo_required` | A queued/claimed Mac job can leave its asset `creating`; the gerund does not prove a renderer is actively executing. |
| Approval | `approved = 0/1` on `created` | `display_stage = approved` is a projection, not a separate persisted `pipeline_stage` or manager. |
| Package | `packing` → `packed`, `pending`, or package `error` | `pending` is stored. The general packing sweep selects `packing`, so pending work is not automatically retried by that sweep. |
| Schedule | `packed` ↔ `queued` | Queueing is explicit. Global/channel enablement controls automatic release, not whether media exists. |
| Dispatch | `queued` or dispatch-error retry → `shipping` → `shipped`, or dispatch `error` | A detached launch returns `in_progress`; shipment completion is a separate action. Older `dispatched`/`published` names remain in compatibility guards/queries. |

PubCom's worker interface declares `readiness(): PubComSignal` and `preflight(array $input): PubComSignal`; manager interface declares readiness and `handleSignal(PubComSignal $signal): PubComDisposition`. Operations such as `analyze()`, `create()`, `pack()`, `ship()` and `connectPubCom()` are concrete/conventional, not guaranteed by those two interfaces. Signals distinguish ready, notice, ineligible, unavailable and pending; manager policy decides continuation, unit skip, line stop or job stop. Exact per-stage policy matters; unavailable does not universally mean an exception or whole-job failure.

## 5. Public PHP entry points

“Public” here describes reachable interfaces, not anonymous permission. Administrative paths below load `api/v2/admin/auth.php` and shared bootstrap; worker paths use a separate secret. Endpoint classes live in PUB and normally expose `public static handle(PDO $pdo): void`.

### HTTP and CLI doorbells

Routes in the first table are relative to `/api/v2/admin/pub/`.

| Doorbell | Adapter/current inputs and result |
| --- | --- |
| `analyze.php` | `AnalyzeEndpoint`; POST source type/ID; returns analysis run, Boxes, existing matches and per-line skipped/failed results. |
| `create.php` | `CreateEndpoint`; POST `orders` and optional `existing_policy`/compatibility `duplicate_policy`. HTTP 409 `existing_asset_warning` before production when explicit decisions are needed; accepted batches return created/queued/failed arrays and counts. |
| `assets.php` | `PubAssetsEndpoint`; GET lists/detail and filed order; PATCH approval or allowed copy/ingredients; DELETE guarded asset disposal, with explicit `force_delete_shipped` for historical rows. |
| `package.php` | `PackageEndpoint`; GET package lists/detail; POST ID sends a unit to packing, no ID processes current packing; `action=update_pingback` updates destination. Returns packed/pending/failed arrays. |
| `schedule.php` | `ScheduleEndpoint`; configuration/rules, queue/dequeue and Send Now actions. Delegates release behavior to ScheduleManager. |
| `dispatch.php` | `DispatchEndpoint`; GET filters/lists/detail; POST ID ships an eligible unit, no ID processes currently shipping work. It is not an automatic sweep of all queued assets. |
| `contracts.php` | Inline GET adapter returns `PubContract::all()`; no business production itself. |
| `errors.php` | `PubErrorsEndpoint`; recent `pub_errors` projection, not ERR's complete incident history. |
| `channel-connection.php` | `ChannelConnectionEndpoint`; GET status; POST status/test/disconnect and channel-supported sync. Pinterest supports board sync; unsupported channel/actions throw and currently become 500. |
| `pinterest-oauth-start.php`, `pinterest-oauth-callback.php` | PUB OAuth endpoint classes; authenticated session state and provider redirect/callback. |
| `youtube-oauth-start.php` | `YouTubeOAuthStartEndpoint`; authenticated start; callback is root `/oauth-google.php`, not an invented adjacent route. |
| `schedule-clock.php` | `ScheduleEndpoint::handleClock(PDO $pdo): void`; CLI only, HTTP 404. Reconciles stale shipping then runs scheduling. Repository contract describes hourly cron; installed cron was not inspected. |
| `create-assets.php` | Older inline Boxes/run-count/CreateManager orchestration; not a thin doorbell. No current UI/worker caller found in the searched repository. Preserve until external consumers are checked. |
| `analyze-playlist-pinterest.php` | Older inline GET orchestration referencing absent `Analyze\Pinterest\PlaylistPinterestAnalyzer` and the older `PlaylistPaletteResolver`. Current UI uses `analyze.php`. This compatibility path cannot be represented as a working equivalent. |

Worker routes are relative to `/api/v2/admin/pub/video-jobs/`:

| Doorbell | Access, input and effect |
| --- | --- |
| `create.php` | Admin session; creates a job directly from nullable `pub_asset_id`, creator/recipe keys, props and output MIME. It bypasses creator preparation. Nullable preview jobs are representable, but the current Mac listener requires a positive asset ID. |
| `claim.php` | Shared worker secret; POST; returns the oldest queued job or no job. Inline repository orchestration. |
| `rendering.php` | Shared secret; POST job ID; marks claimed work rendering. |
| `upload.php` | Shared secret; multipart job ID/file; requires claimed/rendering job and stores staging bytes through `VideoJobFileService`. |
| `complete.php` | Shared secret; `VideoJobCompletionEndpoint` → CreateManager settlement with job/status/output path/size or error. |
| `heartbeat.php` | Shared secret; records global worker heartbeat/details. |
| `_worker_auth.php` | Included helper, not a production operation. Compares supplied JSON/form `worker_secret` with private server configuration using `hash_equals`; missing configuration is 500, invalid credentials 401. |

Most application doorbells are thin. Compatibility analysis/create and several worker files still perform validation, repository orchestration and response shaping inline, and rely on `admin/project-workflow/_helpers.php`. No new route is proposed here. General endpoint failures are not uniformly typed: several turn validation errors into HTTP 500, and some expose exception messages/class/file/line to authenticated callers. A batch HTTP 200 is not proof that every unit succeeded.

### Implemented service-level interfaces

Names below are relative to `App\PUB`. Managers construct with `(PDO $pdo, string $projectRoot)`.

| Interface | Inputs, outputs and failure behavior |
| --- | --- |
| `Analyze\AnalyzeManager::analyze(string $sourceType, int $sourceId): array` | Prepared playlist → run/Boxes/matches and skipped/failed summaries. Invalid source/identity throws; individual specialists can fail without cancelling other lines. |
| `Create\CreateManager::processBatch(array $orders, array\|string $existingPolicy = 'check'): array` | New Boxes or existing-asset orders → warning or created/queued/failed outcome. `createBatch(array $boxes, array\|string $existingPolicy = 'check'): array` delegates. |
| `Create\CreateManager::recreate(int $pubAssetId): array` | Replays filed ingredients after preflight/stage checks; returns synchronous or queued outcome, or throws. |
| `Create\CreateManager::settleVideoJob(int $jobId, string $status, ?string $outputRelPath = null, ?int $outputFileSizeBytes = null, ?string $errorMessage = null): array` | Promotes completed output or records failure; terminal jobs return already-settled behavior. Creator/asset/path mismatches fail. |
| `Package\PackageManager::sendToPacking(int $pubAssetId): array`; `processPacking(): array` | Mark/process one or sweep packing work; `{packed, pending, failed}`. Gate/state errors throw; specialist failures are recorded per unit. |
| `Schedule\ScheduleManager::getConfig(): array`; `updateSettings(bool $schedulerEnabled, string $timezone): array` | Read/update scheduler configuration; invalid timezone/settings fail. |
| `Schedule\ScheduleManager::saveChannelRule(string $channel, bool $enabled, int $releaseIntervalMinutes, int $sameSourceMax, int $sameSourceWindowMinutes, bool $notifyOnPublish = false): array` | Saves validated channel release policy. |
| `Schedule\ScheduleManager::enqueueAsset(int $pubAssetId): array`; `dequeueAsset(int $pubAssetId): array`; `sendNow(int $pubAssetId): array`; `run(): array` | Queue mutations, manual release, automatic selection and shipping handoff. These are potentially provider-writing operations. |
| `Dispatch\DispatchManager::shipOne(int $pubAssetId, bool $notifyOnPublish = false): array`; `processShipping(): array` | Claims eligible work or processes shipping rows; outcomes distinguish completed/in-progress/failure. `shipOneForErrRecovery(int $pubAssetId): array` suppresses duplicate recovery reporting. |
| `Dispatch\DispatchManager::completeShipment(int $pubAssetId, array $receipt, bool $notifyOnPublish = false): array` | Finalizes receipt/signature/order, promotes REX lock and sends policy-controlled notification; failures throw except documented best-effort follow-ups. |
| `Dispatch\DispatchManager::failShipment(int $pubAssetId, string $code, string $message, ?array $diagnostics = null): array`; `reconcileStaleShipping(int $maxAgeSeconds = self::STALE_SHIPPING_SECONDS): array` | Failure/recovery and stale-shipping settlement; default threshold 1800 seconds. |
| `Analyze\Sources\PlaylistSourcePreparer::prepare(int $playlistId): array` | Neutral playlist/items/linked PV projection; depends on injected playlist repository, PV service and REX relationships. |
| `Analyze\Support\DefaultPantry::prepareAudio(array $reference): array` | Resolves default audio identity into usable path/URL/volume; invalid/missing input fails rather than asking the renderer to fetch configuration. |
| `Create\Video\PdoVideoJobRepository::createJob(?int $pubAssetId, string $creatorKey, string $videoRecipeKey, array $props, string $outputMimeType = 'video/mp4'): array`; `claimNextQueuedJob(): ?array` | Persists/claims jobs. Also exposes `markRendering`, `completeJob`, `failJob`, `findById`, `failActiveJobsForAsset`; no per-worker lease token. |
| `Create\Video\VideoJobFileService::store(int $jobId, array $file): array`; `VideoWorkerHealthService::recordHeartbeat(array $details = []): array`; `status(): array` | Upload path/size result and heartbeat/readiness projection. Invalid uploads throw. |
| `Dispatch\Auth\ChannelAuthContract` | `channelKey(): string`, `status(): array`, `authorizationUrl(string $state): string`, `handleCallback(string $code): array`, `testConnection(): array`, `disconnect(): void`. Concrete services can refresh/persist credentials during status/token acquisition. |
| `Support\ProductionSignature::fromIngredients(array $ingredients): string` | Stable production signature from prepared ingredients, not a provider idempotency key. |
| `Contracts\PubContract` | `all`, `defaults`, `stage`, `get`, `effective`, `assetTypes`, `assetCatalog`, `marketRun`, `boxFields`, ingredient/default lookup helpers. Static arrays/null for unsupported lookups; no implicit production execution. |

`PdoPubAssetRepository` supplies implemented low-level reservation/order methods (`reserveWithOrder`, `replaceWithOrder`, `getOrder`, `updateOrder`), stage/approval/package/queue transitions, history/admin reads, `finalizeShipment`, disposal guards and `deleteUnsent`. These are not all approved cross-department write APIs merely because methods are public. Actual external consumers are listed in section 7. `Services/PubRunService` supplies `start`, `stampBox`/`stampBoxes`, progress refresh/completion, reads and overwrite operations; overwrite has no current caller found and has safety drift described below.

## 6. Persistence and data relationships

Names/relationships below are verified from current queries. Column existence, live foreign keys, indexes and whether every archived migration was applied were not checked against production.

| Data | Current owner/use |
| --- | --- |
| `pub_runs` | Analysis identity/source, expected/actual/failed counts and preparation/final status. `PubRunService` computes ready/partial/failed outcomes; preparing is the initial work status. |
| `pub_assets` | Production identity, run/source/channel/type/creator/order position, copy/destination, files/MIME/sizes/thumbnail, approval, pipeline/error state, sealed package, receipt/signature and attempts/dispatch timestamp. |
| `pub_asset_orders` | Filed creator key and prepared ingredient JSON by asset. Kept for production/recreate/recovery; current successful finalization deletes the order after recording its production signature. |
| `pub_video_jobs` | Nullable asset association, creator/recipe, props JSON, output MIME/path/size, status/error and job timestamps. Queue identity is distinct from asset identity. |
| `pub_schedule_settings` | Global enablement and configured scheduler timezone. |
| `pub_schedule_channel_rules` | Channel enablement, interval, same-source quota/window and publish-notification policy. |
| `pub_errors` | Optional PUB diagnostic sink/notification tracking; not a replacement for ERR incidents. Dispatch injects the repository/notifier; Create and several other reporters use file logging alone. |
| `publishing_channels` | **Still active:** encrypted OAuth credentials/status via `Dispatch/Auth/PdoPubChannelAuthRepository`, used by both providers. The legacy-looking name is not evidence it can be dropped. |
| `pub_channels`, `pub_sync_runs` | Separate destination metadata and sync bookkeeping via `PdoPubChannelConnectionRepository`, used by Pinterest shipping configuration/board sync. These do not currently replace the OAuth store. |

Source playlist and palette/PV tables are read through PLAYLISTS/PALETTES paths; `asset_library` is read directly by `DefaultPantry`. Asset administrative projections also join playlist titles. These are read dependencies, not PUB ownership of those records. PHOTOS scans PUB file columns, order ingredients and video props for source-photo usages; a read-only compatibility probe for `pub_render_jobs` does not demonstrate an active PUB renderer for that table.

Current successful shipment history is `PdoPubAssetRepository::listShippedForSchedule()`. Deleting a shipped asset can therefore remove history used for cadence/teaser rules; an empty `History` scaffold provides no independent archive.

### Transactions and filesystem boundaries

* Reservation/replacement, order editing, shipment finalization, asset deletion and video claiming have repository-owned transactions. Several call `beginTransaction()`/`commit()` unconditionally. They cannot safely be composed under an arbitrary outer coordinator transaction without review, contrary to the preferred shared-PDO convention.
* `claimNextQueuedJob()` selects the oldest queued row `FOR UPDATE` then marks claimed. It is a database claim, not a renewable lease; there is no claim-owner fencing token in the worker request contract.
* `markShipping()` conditionally claims an eligible asset; this protects that asset from a second queued claim, not the entire scheduling lane or external provider exactly-once delivery.
* `finalizeShipment()` records shipment and deletes its filed order in one database transaction. Provider calls, video/thumbnail promotion, job settlement, REX locks and mail are outside that transaction.
* Video settlement can promote files, update the asset and complete the job in separate steps. `PubAssetsEndpoint` and older run overwrite can unlink files before all database disposal succeeds. Those are existing recovery hazards, not approved disposal conventions.

Archived publishing migrations describe several superseded schemas. In particular, `2026_09_14_001_remove_legacy_publishing_tables.sql` preserves `publishing_channels`. They are historical evidence, not an executable current-schema cleanup plan.

## 7. Dependencies and boundaries

### Verified consumers and collaborators

| Collaborator | Current relationship and boundary |
| --- | --- |
| PLAYLISTS | `PdoPlaylistRepository::getAdminRowById()` and `getActivePublishingSlides()` prepare publication source data; slide `pin`/`yt` participation is separate from public browser/index eligibility. PUB does not change playlist flags. |
| PALETTES/PV | `PALETTES\PV\PVService::getPV()` supplies linked photo/color/kicker/intro content. The older resolver uses shared `App\Services\ViewerService` instead. Palette records remain PALETTES-owned. |
| PHOTOS | Source `PhotoEntity` values, and `PhotoLifecycleService` protection before PUB unlink operations. PHOTOS dependency scanning inspects PUB's durable ingredients/props and output paths; PUB must not bypass it for Photo Library originals. |
| REX | Current preparation reads related active Public-experience viewer reservations; image packaging looks up an existing Public destination; Dispatch changes publication locks through REX's repository/relationship implementation. Older palette resolver can reserve a missing Public REX. No token generation belongs to PUB itself. |
| ERR | PUB provides department-specific recovery handlers/steps; ERR owns incident/bookmark lifecycle and generic execution. `ERR/Services/AssetOutcomeReconciler.php` reconciles video outcomes. PUB managers report selected incidents through ERR, not every ordinary PubCom notice. |
| Shared asset library | `Analyze/Support/DefaultPantry.php` reads music files. `app/repos/PdoAssetLibraryRepository.php` imports `PubContract` to protect the default music asset from deletion. This is a bidirectional policy dependency, not an established separate ASSETS department interface. |
| SYSTEM/shared infrastructure | Admin guard, PDO/autoload, SecretBox/EnvLoader, SMTP/config, filesystem, PHP GD/cURL, CLI PHP/GNU timeout, Node/Remotion/browser dependencies. The bootstrap's deployment assumptions are shared infrastructure, not publishing policy. |
| ANA | Pinterest `src=pin` supports downstream attribution. No direct ANA event-recording call was found in PUB; ANA storage/filtering is not a PUB stage. |
| SAS/POD | Share/playback consumers can use the same underlying playlists/REX. No current direct PUB stage caller from SAS/POD was found; do not invent send/dispatch coupling. |
| Admin frontend | `/admin/pub` → `AdminPubPage` stage components. Analyze calls playlist search plus Analyze/Create; asset editors call Assets/Create; Package calls Package/Schedule; scheduling and shipping views call their endpoints; connection controls start OAuth; recovery UI also uses ERR. |

Music editing uses existing Asset Library retrieval; the video editor also inspects REX experiences. Client-side API use does not transfer ownership of those domains to PUB. `PubInfoPage` at `/pub-info` is informational, not an anonymous publication endpoint.

### Architectural exceptions — current implementation, not approved future design

1. **Stray production logic:** `vite.config.js` contains `/api/v2/admin/pub/render-video-local`, product-specific plan/legacy recipe translation, script selection and output placement. It is a development-server middleware, not a deployed PHP doorbell. It has no admin-session check in that handler and no current frontend caller was found. Its Before/After branch references absent `scripts/render-pinterest-before-after-video.mjs`. Actual development exposure and external use remain unverified.
2. **Parallel older workflow:** Manual YouTube rendering, PPTX draft export and the YouTube player/service/API prepare a separate older representation. Npm/CLI/routes remain callers, so they are not automatically dead code. Necessary product preparation should ultimately belong to PUB; generic rendering/preview adapters may remain at execution boundaries after a usage decision.
3. **Inline orchestration:** Compatibility analysis/create and worker handlers carry responsibilities that should normally live behind PUB endpoints/managers. Some current endpoint methods directly perform disposal or stage policy. Moving code is proposed, not performed.
4. **Mixed repository policy:** State/editability guards, ingredient binding/rebuild decisions and unconditional transaction ownership live in repositories alongside SQL. The constitution's service/repository separation is transitional here.
5. **Dual channel identity:** OAuth and destination metadata use different tables/IDs. `PinterestConnectionService::syncBoards()` reads `publishing_channel_id` from a `pub_channels` result; its repository normalizes `pub_channel_id` without adding that alias. This source-level mismatch needs an isolated test/live-schema check; it must not be “fixed” by declaring the credential store obsolete.
6. **Overlapping discovery/linking:** Older `PlaylistPaletteResolver` both discovers content and can create REX; current source preparation reads REX relationships and PALETTES PVs instead. This overlaps REX preparation/Viewer responsibilities already recorded in REX's manifest.
7. **Stale lifecycle bookkeeping:** `PubRunService::overwriteStatus()` blocks `creating/dispatched/published` but omits current `shipping/shipped`; overwrite unlinks before repository deletion can reject a historical asset. No caller was found, so this is a dormant capability hazard, not evidence of current UI deletion. Progress projection also omits current `queued`/`pending` while retaining older stage names.
8. **Interface/scaffold drift:** Empty DTOs/history/render classes and contract descriptions suggest features not represented by actual interfaces. Current manager/specialist array contracts must not be documented as completed typed adapters.
9. **Error and notification inconsistency:** File-only versus DB/error-mail reporters expose different histories. `DispatchDriverJob` carries notification intent, but `DispatchDesk::reportSuccess()` calls `completeShipment()` without passing it. Async Pinterest notification intent can be lost; YouTube's completion policy always notifies independently.

No new department is justified by this audit. Consolidation into PUB, PHOTOS, REX, PALETTES, PLAYLISTS, SYSTEM and ERR should be considered first. Shared asset-library policy ownership remains a decision for the maintainers, not an ownership assignment made by this document.

## 8. Publishing and recovery workflows

### Analyze and Create

1. Admin selects a playlist. Analyze creates a fresh run and prepares all active publishing slides once, retaining participation flags, roles/types, photo identity/path/URL, and linked Public-experience PV data.
2. Pinterest specialists filter `pin` participation. Transformation pairing associates each Before with following After candidates until the next Before/Single boundary. Idea uses After/Single photos; Palette uses matching linked-PV colors; Teaser uses explicitly authored teaser photo/title and may validly produce nothing.
3. YouTube filters `yt` participation, requires a usable cover and playable content, prepares music/hue-wheel inputs and estimated duration. The cover is separate from playable slides. Default music currently references Asset Library ID 734 with volume 0.2; Analyze resolves the reference into usable ingredients.
4. Manager stamps source/run/channel/type/creator identity, merges approved proposal fields and carries forward matching in-house outside copy. Specialists are isolated: one failed/unavailable line need not cancel unrelated products.
5. Create first checks existing production. Default policies are `unshipped=check`, `shipped=check`; explicit alternatives are `replace` and `new_version`. Compatibility string `include` selects both alternatives. Warning responses precede reservations/production, rather than replacing silently.
6. Create reserves the asset/filed order before production, then calls its specialist. Static images finish synchronously; videos enqueue durable jobs and keep the asset creating. Recreate replays filed ingredients after checks, resetting approval. Bound ingredient edits can require `redo_required`; copy-only changes to packaged/queued work invalidate its package and return it to packing.

### Active CHEF video production and Mac worker

1. Before/After and YouTube creators build recipe/blueprint output; `VideoLayerBuilder` translates the YouTube blueprint into generic layers. `VideoCreatorTools` provides primitives; the duration estimator is an estimate rather than a second renderer.
2. Creator readiness checks a heartbeat stored at `storage/pub-video-worker/heartbeat.json` with a 20-second freshness window. The listener heartbeats/polls approximately every five seconds. A heartbeat proves recent listener activity, not that every job can render.
3. `scripts/pub-video-worker.mjs` uses `COLORFIX_BASE_URL` and the shared worker secret; local launcher reads `~/.config/colorfix/pub-video-worker.secret`. It claims a job, marks rendering, writes `exports/pub-video-jobs/{jobID}/recipe.json`, and runs `scripts/render-remotion-video.mjs`.
4. The generic executor uses supplied composition/plan/render settings with `src/remotion/index.jsx`; the generic composition renders supplied layers/audio. It does not request a playlist or select a product.
5. The worker uploads MP4, reports completion/failure, and attempts local cleanup. Server staging uses `storage/pub-video-jobs/` with a deny-all `.htaccess`; promotion validates managed staging paths. `VideoJobFileService` checks upload status, nonempty size and a 500 MiB bound; it does not independently prove MP4 codec/content from trusted inspection.
6. Create promotes completed bytes to the creator's publication output, builds/promotes the YouTube thumbnail, marks the asset created and job complete, and refreshes run/ERR outcomes. Terminal repeated completion is handled as already settled. Partial failures between filesystem and database actions are not an atomic rollback.

Queue states are `queued → claimed → rendering → complete/failed`. There is a global heartbeat but no per-job renewable lease/fencing owner in the current protocol. No automatic abandoned-video-claim reaper was found. The local rendering child has no explicit overall execution timeout in the worker. These gaps differ from Dispatch's server-side driver timeout and stale-shipping reconciliation.

### Approval, Package and Schedule

1. Approval sets a flag on created media. Ingredient edits invalidate approval and demand recreation; copy edits can invalidate an already built package without re-rendering unchanged media.
2. Package marks approved work packing and routes by channel/MIME. Image packages contain title/description/link/image URL; Pinterest video packages hold local video transport input; YouTube packages hold video and thumbnail paths plus copy. Managers store the specialist's sealed package.
3. Normal Pinterest image packaging can recover a blank destination from an existing canonical Public playlist REX, without reserving one. Teasers clear an inherited source REX and wait for a deliberate external destination, commonly the shipped YouTube URL; waiting becomes pending. Normal Pinterest destination tagging replaces/adds `src=pin` rather than changing REX identity.
4. YouTube packaging checks readable nonempty video and JPEG thumbnail constraints, copy and paths; shipping policy separately decides provider privacy. Packed work is held until enqueued.
5. Schedule reads global timezone/enablement, per-channel enablement/cadence/quota, eligible queued packages and durable shipped history. Teasers require the corresponding shipped YouTube URL; Pinterest selection considers description repetition, asset-type history and sibling order with deterministic tie-breaking.
6. Automatic run selects eligible releases and calls Dispatch. Manual Send Now accepts packed/queued media and bypasses automatic cadence/switches; on a failure before custody changes, it can undo its temporary queue change. Neither path owns provider transport.

There is no observed scheduler-wide lock/reservation transaction serializing channel cadence. Atomic per-asset shipping claims do not prove that two concurrent schedule runs cannot release different assets too close together.

### Dispatch, receipts and recovery

1. Dispatch conditionally marks eligible queued/error-dispatch work shipping, incrementing attempt count. It validates the sealed package and specialist readiness and protects the source Public playlist REX tree before external transport.
2. Pinterest images post synchronously. Pinterest video uses a detached route: register media, upload, poll readiness, create Pin. YouTube uses a detached route: resumable video upload followed by thumbnail upload. Current YouTube privacy policy is **private**, with the Google-project audit restriction documented in shipping configuration; “shipped” does not promise a publicly visible YouTube video.
3. `DispatchDriverLauncher` uses CLI PHP and GNU `timeout` through a detached shell, with an encoded `DispatchDriverJob` ticket. Pinterest video has a 600-second route bound; YouTube 900 seconds. The CLI runner reloads shipping custody/package, calls the route and reports through `DispatchDesk`; the wrapper also settles process exits/timeouts. Host binaries/`exec` availability are prerequisites, not verified deployment facts.
4. Finalization requires filed production ingredients, computes the signature, and atomically records receipt/shipped status and deletes the order. REX publication-lock promotion follows best-effort; notification errors are logged rather than undoing shipping. YouTube completion always requests publish mail; other notification policy can be requested by the scheduler, subject to the async propagation gap above.
5. Exceptions record dispatch error and release temporary publication protection as appropriate. Existing published protection is preserved. Stale shipping reconciliation defaults to 1800 seconds and reports failure through the manager; it does not establish that a timed-out provider performed no action.
6. Provider-local retries exist: YouTube resumable transport and thumbnail retries; Pinterest video readiness polling; and a narrowly recognized Pinterest create-Pin code/message failure with bounded attempts. These are not general safe replays of every error. `processShipping()` is a current administrative capability that also requires duplicate-execution care.
7. PUB's ERR provider maps 4001 to stopped video-worker/Create recovery, 4002 to publishing authorization recovery, and 4003 to unknown dispatch recovery. Saved bookmarks replay the exact order/asset through PUB-owned steps. ERR owns incident state; a newly queued render remains pending recovery until its outcome is known. ERR failures must not reverse a successfully settled video job.

**Unknown outcome:** Provider publication can succeed before local receipt finalization fails. A YouTube video can exist even if its subsequent thumbnail upload fails. No durable, general provider-attempt/idempotency record resolving these cases was found. A production signature is not a provider deduplication guarantee. Manual or automated retries need that distinction preserved.

## 9. Security, publication and deletion rules

* Admin operations use the existing SYSTEM guard. They are not Gallery/public browsing APIs. `analyze.php`'s comment describing a “public doorbell” does not bypass its admin authentication.
* Root `oauth-google.php` is intentionally reachable without an admin request guard, but the YouTube callback requires matching one-use session state and restricts the return path to `/admin/`. Pinterest uses one-use state with a 900-second lifetime. A corresponding explicit YouTube state TTL was not found.
* Worker routes authenticate separately via `render_worker_secret` from the private server secrets file, using constant-time comparison; they do not accept a claimed Google/user-agent identity as authorization. Secrets/cookies are not documented here. Job actions authorize through that shared credential, without per-worker claim ownership.
* OAuth tokens are encrypted through shared SecretBox and refreshed server-side. Status can create/update channel records or refresh credentials; it is not a pure read. No live scopes, credentials or provider access were tested.
* The Vite local-render handler has a separate development boundary; do not assume the PHP admin guard protects it. The older `api/v2/youtube-video-playlist.php` is a read/preview API outside the PUB-admin doorbells and has no equivalent admin guard in that file. Its source-exposure/access policy requires review independently of provider publishing.
* Before external publication, Dispatch requires the source's active Public REX and protects its reservation tree. PUB is not the definitive validator of all destination public/client/fallback rules: unresolved REX access and publication-lock enforcement issues remain recorded in REX's manifest. Public experience is not synonymous with every playlist being public/indexable.
* `PubAssetsEndpoint` blocks shipping deletion; historical deletion requires explicit strict `force_delete_shipped`. It fails active render jobs and checks PHOTOS protection before unlinking generated video/image/thumbnail paths. It then deletes asset/order rows. This does not revoke a remote Pin/YouTube publication, and historical deletion can affect scheduler history and remote media availability.
* `PubRunService::overwrite()` is a separate older file/row disposal path with stale guards and no found caller. Neither it nor forced asset deletion is certified as safe shared-output lifecycle management by this audit.
* No deletion, provider publishing, schema change, worker launch, rendering or notification was performed for this manifest. No retired photo-system cleanup is included.

## 10. Changes, tests, and documentation maintenance

### Verification performed for this manifest

* Read governing/reference documents and all PUB source areas; cross-checked registrations, concrete signatures, state writes and direct callers rather than treating DTO comments as implementation.
* Searched repository PHP/JavaScript/config/scripts for PUB routes, classes, table reads/writes and execution paths. Checked actual doorbells, guards, root OAuth callback, Admin routes and worker/driver commands. Inspected archive membership without extracting/modifying it.
* Inspected archived migrations and current query names; no production database/schema read was made. Live foreign keys, deployed aliases, external callers, cron, worker services, provider accounts and external publications remain unverified.
* Inspected static `PubContract` arrays using autoload/static access, without database bootstrapping or production operations. Stripped-source reads used `php -w`, not execution of endpoint/manager workflows.
* Checked this file's numbered sections, Markdown structure/table syntax, source paths and relevant interface names. Compared working-tree content hashes against the pre-task baseline to distinguish this new document from unrelated existing changes.

### Existing tests and gaps

`api/tests/PhotoLifecycleTest.php` and `PhotoRetirementTest.php` include source-photo/PUB ingredient protection coverage. They do not establish complete factory/provider behavior. `scripts/test-pub-dispatch-err.php` is an operational integration script using configured persistence and potentially notification; it is not a safe isolated documentation check and was **not executed**. No dedicated isolated PUB stage/provider/worker regression suite or package test command was found in the searched repository.

Future implementation needs safe fixtures/fake transports for proposal participation and pairing; existing/new-version decisions; approval and ingredient invalidation; packing pending/retry; schedule timezone/quota/concurrency; worker claim/settlement/duplicate completion; filesystem/DB failure points; driver timeouts/late outcomes; remote partial success; OAuth state/refresh; notification propagation; and PHOTOS-original/shared-output protection. Do not validate those by publishing or destructively deleting production assets.

### Change log

| Pacific date | Change |
| --- | --- |
| 2026-11-08 | Created PUB implementation inventory and architectural audit; documented uncertainties, exceptions and proposed improvements. Application behavior unchanged. |

Update the timestamp and caller/state evidence whenever PUB contracts, recipes, worker protocols, ownership or provider lifecycle change. Proposed improvements below require separate approval; their presence is not an instruction to implement them.

## 11. Future Improvements

These recommendations are prioritized by risk and operational value. Ownership proposals are recommendations for review, not resolutions of conflicts.

### 11.1 Priority 1 — Resolve unknown-outcome shipment recovery

* **Problem:** Remote publication, thumbnail upload, local receipt finalization and timeout settlement can disagree; retries can duplicate remote content. Ingredient signatures alone cannot resolve an already-created provider object.
* **Affected components:** DispatchManager, both video shippers, driver runner/desk, asset finalization, ERR recovery.
* **Recommended ownership:** PUB owns provider-attempt state and reconciliation; ERR invokes PUB-owned recovery and records its outcome.
* **Intended outcome:** Distinguish definite rejection, resumable work, completed publication and uncertain outcome before retrying. Preserve known remote IDs even when later steps fail.
* **Dependencies:** Provider API capabilities, existing receipt/order retention and migration/retention decisions; no new department required.
* **Risks:** Replaying unknown work, losing recovery evidence or changing receipts/contracts; remote cleanup must never be assumed reversible.
* **Verification:** Fake provider success followed by DB failure, video success/thumbnail failure, timeout with late receipt, repeated completion and recovery. Prove no duplicate transport on reconciled success.

### 11.2 Priority 1 — Define durable file/history disposal and transaction ownership

* **Problem:** Asset endpoint and older run overwrite can unlink before database disposal; shared PUB paths/history and partial settlement are not comprehensively coordinated. Repository-owned transactions impede composition. Older overwrite guards omit current states.
* **Affected components:** PubAssetsEndpoint, PubRunService, PdoPubAssetRepository, video promotion/job settlement, PHOTOS guard and scheduler history reads.
* **Recommended ownership:** PUB owns generated publication output/history disposal; PHOTOS retains exclusive source-photo lifecycle ownership. Maintainers decide whether dormant overwrite is removed or maintained.
* **Intended outcome:** One guarded PUB disposal path, explicit history/remote-media retention policy, post-commit safe file cleanup and coordinator-owned transactions without bypassing PHOTOS.
* **Dependencies:** Confirm external overwrite callers, shared file references and desired historical deletion contract; production schema evidence before migrations.
* **Risks:** Broken remote image URLs, lost schedule history, source deletion, orphaned output or nested-transaction failures.
* **Verification:** Fixtures for shipping/shipped rejection, forced historical deletion, shared output, PHOTOS originals, rollback and filesystem failure, interrupted promotion and composed transactions.

### 11.3 Priority 1 — Fence worker jobs and concurrent release decisions

* **Problem:** Global heartbeat is not a job lease; abandoned claims have no found automatic recovery, and concurrent scheduling can exceed cadence despite per-asset claims. Assetless preview job support disagrees with the listener.
* **Affected components:** Video job repository/handlers/listener/health, Create settlement, ScheduleManager, stale Dispatch recovery.
* **Recommended ownership:** PUB owns job/release policy; worker scripts execute its protocol; ERR records blocked/recovery state.
* **Intended outcome:** Explicit job custody, bounded execution/recovery and concurrency-safe release rules; a deliberate decision about preview jobs.
* **Dependencies:** Worker deployment topology, DB locking strategy, backward-compatible protocol/version rollout and safe retry decisions from 11.1.
* **Risks:** Older workers settling reassigned jobs, duplicate renders/shipments, false expiry while slow but healthy work continues.
* **Verification:** Two workers/schedulers, dead listener, stalled render, late upload/completion, heartbeat without progress, timezone/quota edges and existing-worker compatibility.

### 11.4 Priority 2 — Reconcile channel identities and OAuth boundaries

* **Problem:** Current OAuth credentials and destination metadata use different stores; board sync expects a mismatched ID key. Callback state lifetimes and status side effects differ.
* **Affected components:** PdoPubChannelAuthRepository, PdoPubChannelConnectionRepository, PinterestConnectionService/ShippingConfig, both OAuth endpoint/service families.
* **Recommended ownership:** PUB owns provider credentials/destinations; SYSTEM continues to own administrator authentication and generic security primitives.
* **Intended outcome:** Verified identity mapping and consistent contracts before any consolidation; preserve active `publishing_channels` until a separately reviewed migration exists.
* **Dependencies:** Live schema/rows, encrypted credential compatibility, provider scopes/redirect configuration and any external connection consumers.
* **Risks:** Lost tokens, wrong destination board, login/callback breakage or accidental publication-policy changes.
* **Verification:** Fixture-based board sync, valid/invalid/expired/replayed state, refresh/disconnect/status behavior, destination lookup and masked diagnostics. No production send required.

### 11.5 Priority 2 — Clarify REX/content and shared-service boundaries

* **Problem:** Older palette resolution can create REX while current preparation reads relationships; shared Viewer and asset-library policies cross department boundaries. Dispatch safety depends on unresolved REX lock/access semantics.
* **Affected components:** PlaylistPaletteResolver, PlaylistSourcePreparer, PinterestImagePackager, DispatchManager, REX linkers/locks, PV/shared Viewer and asset-library repository/default pantry.
* **Recommended ownership:** REX token/relationship/access policy; PALETTES PV content; PHOTOS source paths/assets; PLAYLISTS publishing source selection; PUB product ingredients/destinations. Maintainers decide the shared asset-library interface owner.
* **Intended outcome:** Reuse owner-published interfaces and avoid duplicate discovery/token creation or direct cross-owner writes, while preserving stable destinations and `src` tracking.
* **Dependencies:** REX manifest's publication-lock/access/fallback decisions and actual external callers; confirm neutral-source projection contract.
* **Risks:** Changed public/client access, wrong PV/photo selection, new tokens, lost tracking or music defaults.
* **Verification:** Stable Public destination reuse, non-public/client cases, linked PV matching, source-photo protection and default audio lifecycle guards.

### 11.6 Priority 2 — Consolidate active product preparation and review older tool retirement

* **Problem:** Vite middleware and older manual YouTube/export paths duplicate product preparation and contain missing-script/transitional dependencies; scaffold names obscure actual rendering ownership.
* **Affected components:** Vite local-render route, manual render/export scripts, YouTube player/service/API, Remotion roots, empty render/history/DTO/metadata classes, archive and older Pinterest analysis doorbell.
* **Recommended ownership:** PUB owns media product preparation/recipes; execution scripts and frontend renderer remain adapters; PLAYLISTS owns source/playback data. No new umbrella is justified.
* **Intended outcome:** One current preparation pipeline with explicitly supported preview/export adapters; retire only proven obsolete tools/placeholders after caller review. Preserve active CHEF/Remotion functionality.
* **Dependencies:** Confirm external/npm/manual consumers, development exposure and whether PPTX export remains wanted; select supported preview semantics.
* **Risks:** Removing a manually used tool, confusing current video rendering with retired image systems, or altering visual output/access.
* **Verification:** Current Pinterest/YouTube recipe render fixtures, thumbnails/audio/timing, manual export parity where retained, route/import/build checks and explicit missing-script handling. No production file deletion in an audit.

### 11.7 Priority 3 — Complete thin-doorbell and lifecycle/interface cleanup

* **Problem:** Older API orchestration and worker handlers are inline; repositories contain state policy, run projections retain stale stage names, PubCom interfaces omit operation contracts and endpoint errors vary.
* **Affected components:** Compatibility/worker doorbells, PUB endpoint classes/managers/repos, PubRunService, PubContract, PubCom and empty DTO placeholders.
* **Recommended ownership:** PUB endpoints adapt requests; PUB managers/services own decisions; repositories persist; shared bootstrap/auth remain infrastructure.
* **Intended outcome:** Small owner-respecting delegations using existing URLs, accurate lifecycle projections and documented concrete contracts; no parallel API or mandatory speculative DTO framework.
* **Dependencies:** Caller inventory, decisions on dormant compatibility paths, transaction changes and isolated regression fixtures.
* **Risks:** Changed batch warning/error responses, approval/queue semantics or worker compatibility during a mechanical move.
* **Verification:** Contract tests for text/body/status responses, partial batches, existing-asset warnings, all current run states and API/worker regression checks.

### 11.8 Priority 3 — Unify observable outcomes and notification propagation

* **Problem:** File-only and DB-backed error sinks differ; async notification intent is dropped by DispatchDesk; operational scripts are not isolated regression tests.
* **Affected components:** PubErrorReporter/Policy/repository, endpoint/manager construction, DispatchDriverJob/Desk, PublishNotifier, PubErrors UI and ERR integration.
* **Recommended ownership:** PUB owns publication diagnostics/notification policy; ERR retains incident/recovery lifecycle; shared SMTP handles delivery only.
* **Intended outcome:** Consistent inspectable outcomes and explicit notification handoff, without creating a second ERR system or emailing during tests.
* **Dependencies:** Error retention/redaction policy, async ticket compatibility and test transport injection.
* **Risks:** Duplicate mail/incidents, exposed provider diagnostics, confusing successful shipping with failed notification.
* **Verification:** Sync/async notification policy matrix, SMTP failure after successful shipment, ERR outage, partial batch logging and safe isolated replacements for operational test execution.

## 12. Completed Improvements

No improvements have been implemented under this manifest. This assignment created documentation only. Existing code described above is not being retrospectively claimed as a completed roadmap item.

### Complete ANALYZE photo collection (2026-11-18)

`Analyze/Sources/PlaylistSourcePreparer::prepare(int $playlistId)` now returns top-level nullable `editorial_type` from PLAYLISTS alongside source_type/source_id/title/items/linked_pvs. Allowed stored values are makeover, spotlight, teaching, themed_makeover, palette_ideas, palette_exploration, mistakes_fixes and step_by_step_makeover. This is source context, not a channel/product-routing rule.

The preparer receives `PdoPhotoLibraryRepository` as its fifth constructor dependency, wired by AnalyzeManager. It bulk-loads complete Photo Library records via PHOTOS' `findManyByIds()` and attaches every returned owner column under `items[].photo`. Existing normalized photo_library_id/image_url/file_path fields are retained when an image URL is usable. The library title supplies photo.title; the authored slide title remains items[].title. Existing slide image_url is preferred for delivery, with library rel_path as fallback. A library record with no usable URL still supplies metadata; missing records retain the existing ID/URL delivery fallback when possible; non-photo slides gain no fabricated photo.

Every prepared photo contains `editorial:{photo_description,design_notes,refined_commentary}`, read through PhotoEditorialService::getMany(), with nulls for absent text/rows. No publisher/experience flags filter this collection; every specialist receives the same prepared source via AnalyzeManager. Linked public-PV projection and analyzer selection remain unchanged. These are authenticated internal snapshots, not a new public Photo Library response. Source content is read-only; no editorial text is generated or persisted here.

`Contracts/PubContract.php` documents the expanded source shape. `scripts/test-pub-photo-collection.php` checks full snapshot retention (including extra owner columns), editorial values/nulls, photo versus slide titles, repeated IDs, URL fallback, absent images/records, neutral slide collection and nullable playlist classification using isolated fixtures. Production database/HTTP execution was not performed. No schema, frontend, creation recipes, shipping or alias changes.

## ARTICLES website release (2026-11-21)

Website ArticleCreator creates drafts and retains the existing playlist/article receipt. `Services/ArticlePublicationManager(PDO)::release(int $pubAssetId, string $action)` calls ARTICLES' sole publication service. It locks the PUB asset and source relationship in a shared transaction, checks the receipt URL matches the linked article, and requires approved=1 and pipeline_stage=created for publish. Unpublish does not require approval. Missing/stale relationships fail closed. No website Packager, shipment, queue, or new relationship is invented; article status remains ARTICLES-owned and the PUB production stage remains created.

Authenticated POST `/api/v2/admin/pub/article-publication.php` delegates to `Endpoints/ArticlePublicationEndpoint` with `{pub_asset_id,action:publish|unpublish}` and returns the ARTICLES publication receipt. The Assets detail pane exposes these actions. Articles are excluded from bulk media packaging and the media packaging button. PUB approval remains the existing asset review flag, not a versioned snapshot of article copy. Existing non-article pipelines are unchanged. Tests: ArticlePublicationTest and local HTTP/browser coverage; no production changes.
