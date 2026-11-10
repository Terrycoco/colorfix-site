**UPDATED:** 2026-11-09 09:16:30 AM PST

# ANA — Department Manifest

**Department:** `ANA`

**Location:** `app/ANA/`

**Namespace:** `App\ANA`

**Status:** Verified repository inventory and architectural audit. The owner reports Browse Playlists deployed. Its standard-app-container restoration and subsequent landing-only branding/grid correction are implemented/tested locally and have not been deployed by this assignment. No new analytics/database verification or policy change was performed. Future Improvements remain proposals unless explicitly completed below.

**Source reviewed:** Root `SystemManifest.md` as locally replaced by the owner; REX manifest and relevant PHOTOS/PUB boundaries; all current ANA backend files; public/admin doorbells; React providers, tracking consumers and source propagation; registry-driven HTML prebuild and REX sitemap; older Analytics adapters; adjacent user-event funnel; migrations and tests. Root retains the original 13-section constitution and approved consolidation/Future Improvements policies. PUB's historical caveat about an incorrect root copy does not describe the current root. No other manifest was changed to update that history.

**Verification limits:** This document maps source code, not deployed behavior. Production `ana_pages`, `src_params`, configuration values, schema/FKs, server responses and historical events were not queried. The initial inventory was read-only. Subsequent authorized landing work modified the local files described below and used isolated test data only; no production analytics/configuration/schema mutation or deployment was performed. A file's existence is distinguished from a demonstrated caller.

## 1. Mission and ownership

ANA owns the current `analytics_events` recording policy and persistence, page-visit identity resolution, accepted source lookup, configurable crawler exclusion, own-device/test recording policy integration, and resource/source event reporting and authorized event deletion.

ANA's ordinary-page registry is `ana_pages`: registered paths supply reporting identity/labels, tracking enablement, sitemap selection and build-time metadata. Those last two are implemented cross-department/site-build consumers, not evidence that ANA owns routing, all SEO policy or all public page content.

**Does not own:** REX tokens, aliases, graph topology, resolution and destination access; SYSTEM administrator authentication; PLAYLISTS content/experiences/player behavior; SAS sending/recipients; PUB production/provider metrics; PHOTOS assets/crops; or the browser's ability to supply a referrer. ANA does not decide whether a page/resource is publicly available or indexable simply by recording a visit.

**Related but not consolidated:** The mixed-case `app/Analytics/` adapter records through ANA but retains older reporting code. The shared `UserEventService`/`PdoUserEventRepository` funnel records into `user_events`, not `analytics_events`, with different session, source, timing and internal-visitor rules. Whether these responsibilities should ultimately consolidate into ANA is an owner decision; this manifest does not silently transfer them.

No ANA Manager/Controller layer exists. Current services/repositories/endpoints are sufficient to describe the implementation; do not invent layers or add another tracker.

## 2. Core invariants

1. **One current recording policy:** HTTP recording aliases and the older PHP recording adapter converge on `ANAService::record()`. That service checks server-observed user agent and own-device/test rules before persistence. Calling its repository directly bypasses those checks and is not a general consumer recording API.
2. **Page identity is server-resolved:** For exactly `event_key=visit`, `resource_type=page`, the repository derives `ana_pages.id` from the submitted pathname and `track_enabled=1`. It ignores browser-supplied numeric page identity. Unregistered/disabled paths return event ID 0 without inserting.
3. **Independent controls:** `track_enabled` gates ordinary page visits; `include_in_sitemap` selects site sitemap paths. Neither implements authentication, route creation or a robots exclusion. REX destination visits of other resource types do not require page registration.
4. **Preserve event contracts:** Existing page/REX/article visits use `visit`; the homepage YouTube destination uses `click`/`outbound_link`; engagement readers reference `playlist_open` and `watch_more_click`. Do not invent a new `page_view` vocabulary or rename existing events during cleanup.
5. **Sources:** Non-null `src` must exist in `src_params`; unknown values throw rather than silently becoming Direct. Null source is valid. Reporting labels may group keys, but storage retains the original key.
6. **No bot inference from Direct:** A blank referrer, null source or missing user agent does not imply a crawler. Case-insensitive configured UA substrings apply only to analytics insertion, never site access or indexing.
7. **Own devices:** Persistent `cf_internal_viewer` preference and verified-admin integration suppress ordinary events. Explicit test mode permits own-device test events; crawler filtering still applies to tests. The preference is never an admin credential.
8. **Trust boundary:** Retained user agent comes exclusively from the request header, bounded to 1000 UTF-8 characters. Other event context is substantially client-supplied; it is not authenticated proof of a visit, referrer, visitor or destination entitlement.
9. **Counting semantics:** Current resource reports count event rows, not distinct people, tab sessions or network requests. Different event types/systems and REX entrances must not be added together as if they were unique visitors.
10. **Read-only audit:** No historical classification/deletion, attribution rewrite, source registration, tracking change or landing-page implementation is authorized by this manifest.

## 3. Directory and file inventory

All 14 current PHP files under `app/ANA/` are listed here. Relative locations below use that department root.

| File | Current responsibility |
| --- | --- |
| `Contracts/ANAEventRepositoryInterface.php` | `record(ANAEvent): int` persistence contract. |
| `Contracts/ANASourceRepositoryInterface.php` | `isValidSource(string): bool` accepted-key lookup. |
| `Contracts/ANAReportRepositoryInterface.php` | Resource counts/types, playlist engagement, event drill-down and single-row deletion. |
| `DTO/ANAEvent.php` | Readonly event fields and `withUserAgent()` copy; constructor does not perform complete domain validation. |
| `Services/ANAService.php` | PDO factory, central ignore/admin policy and trusted UA enrichment before recording. |
| `Services/ANAIgnoreService.php` | `ana_ignore` app-config patterns; request-local loading/cache, validation, case-insensitive match/save. |
| `Services/ANAReportService.php` | Report orchestration, playlist/article/page/outbound titles and engagement-rate calculation. |
| `Repos/PdoANAEventRepository.php` | Enabled-page path lookup, source validation and parameterized event insertion. One-time data corrections do not belong in this runtime repository. |
| `Repos/PdoANASourceRepository.php` | Current `src_params` membership query. |
| `Repos/PdoANAReportRepository.php` | Source-label grouping, counts/latest paths, page/PV/document titles, detail rows and deletion SQL. |
| `Endpoints/RecordEventEndpoint.php` | Implemented namespaced static recording HTTP handler. |
| `Endpoints/EventsEndpoint.php` | Implemented namespaced GET details / DELETE event handler. |
| `Endpoints/IgnoreConfigEndpoint.php` | Implemented namespaced GET/POST crawler settings handler. |
| `Endpoints/ReportEndpoint.php` | Older top-level PHP script, not a namespaced `ReportEndpoint` class. Duplicates counts orchestration, uses API-style bootstrap paths inappropriate for its current location; no current caller found. |

### External adapters, clients and execution boundaries

| Location | Current role/callers |
| --- | --- |
| `api/v2/admin/ana/record-event.php`; `api/v2/admin/analytics/record-event.php` | Two public recording URLs delegate to the same ANA endpoint; no admin guard despite the URL prefix. |
| `api/v2/admin/ana/ignore-config.php` | Authenticated thin settings adapter. |
| `api/v2/admin/analytics/counts.php`, `events.php`, `delete-tests.php` | Admin report orchestration, thin event adapter and inline test-record deletion respectively. |
| `api/v2/ana/pages-public.php` | Public, read-only page registry projection used by HTML prebuild. Contains inline SQL; not an ANA endpoint-class adapter. |
| `src/ANA/ANAProvider.jsx` | ANA context; browser viewer, tab session, retained referrer and source. |
| `src/ANA/useANA.js` | `track()` event assembly, local exclusion, context/source/path and error handling. |
| `src/ANA/ANAClient.js` | Shared fetch recorder, own-device/test handling and successful/failed response parsing. |
| `src/ANA/ANATrack.jsx` | Component visit effect with optional sessionStorage deduplication. |
| `src/ANA/SourceNavigation.jsx` | Synchronizes React Router location with source-tagged browser history; does not itself insert an event. |
| `src/Router.jsx` | `ANAPageTracker`, homepage compatibility mapping and article visit wrapper. Mounts ANAProvider across ordinary public app routes. |
| `src/helpers/sourceParam.js`; `src/main.jsx`; `src/player-main.jsx` | External-referrer source recognition and internal navigation propagation; initialized before React in both entrypoints. Fast player entrypoint lacks the main router's top-level ANAProvider. |
| `src/lib/viewer.js`; `src/helpers/authHelper.js` | Browser UUID and persistent internal-viewer/test preferences shared with SYSTEM-facing UI. |
| `src/pages/REX/RexPublicPage/index.jsx` | Resolved destination providers/visit wrappers and manual route-REX visit before redirect. |
| `src/pages/PlayerPage/index.jsx` | Direct player playlist visit wrapper after successful content load; POD branch does not use that wrapper. |
| `src/components/GalleryItems/YouTubeItem.jsx` | Concrete ANA `click`/`outbound_link` producer with destination URL payload. |
| `src/pages/AdminAnalyticsPage/index.jsx`; `ANAIgnoreSettings.jsx` | `/admin/analytics`: resource/source counts, visits drawer, source/referrer/UA/test details, confirmed deletion, test mode and crawler editing. |
| `src/Analytics/AnalyticsProvider.jsx`, `useAnalytics.js`, `analyticsClient.js` | Older frontend context/hook/URL adapter. Provider still mounted by main entrypoint; hook has no current application consumer found. Client delegates to ANAClient; old hook omits ANA session/referrer context. |
| `app/Analytics/Contracts/AnalyticsEventRepositoryInterface.php`, `DTO/AnalyticsEvent.php`, `Services/AnalyticsService.php`, `Repos/PdoAnalyticsEventRepository.php` | Older PHP event/reporting interfaces. Recording delegates into ANAService; reports still read raw source keys and page titles from REX route catalog. Current HTTP reports use ANA instead. |
| `app/REX/Services/RexSitemapService.php`; `sitemap.php`; `.htaccess`; `public/robots.txt` | Dynamic site/REX sitemap composition and public delivery; REX owns current service, ANA supplies registry data. |
| `scripts/prebuild-homepage-html.mjs`; `api/tools/prebuild-front-page.php`; `package.json` | Build-time registered page metadata/static shells and homepage content; metadata script reads `pages-public.php` and now emits clean `og:url` alongside canonical. The specific BrowsePlaylistsTile marketing link uses `/browse-playlists`. Not analytics event writers. |
| `database/migrations/2026_11_09_001_browse_playlists_page.sql` | Standalone one-time page configuration correction, subject to review before application. No row-specific setup method or custom runner support. |
| `src/SAS/services/quickSendLinks.js`, `playlistsApi.js`, `podShare.js` | Supplied `src=sas` URLs; these are source-attribution consumers, not a separate ANA recorder. |

### Adjacent tracking system — inventory, not an ownership decision

`src/helpers/userEvents.js`, `app/services/UserEventService.php`, `app/repos/PdoUserEventRepository.php`, `app/repos/PdoTrackingLookupRepository.php`, `/api/v2/user-events/track.php`, and `/api/v2/admin/user-events/{list,clear,definitions,save-event}.php` implement a separate playlist-instance/CTA funnel. `src/pages/AdminUserEventsPage/index.jsx` is its active `/admin/user-events` dashboard. The helper's `trackUserEvent`/`trackCtaOnclickEvent` exports have no current application imports/callers found beyond their own internal call in the searched source. The API/dashboard and other repository users remain reachable; external/older clients are unverified.

`src/pages/AdminAssetAnalyticsPage/index.jsx` and `admin-asset-analytics.css` provide `/admin/asset-analytics`, an explicitly planned channel-metrics/pingback placeholder. They are not a working YouTube/Pinterest metrics collector. Do not infer completed provider analytics from this page.

## 4. Supported events, identities and attribution

### Implemented producers and readers

| Event/resource | Producer or consumer and semantics |
| --- | --- |
| `visit` / `page` | Main `ANAPageTracker` sends one attempt per pathname transition; enabled registry path required. `/` is once per stored ANA session; `/results/4` uses separate legacy homepage wrapper and resolves to `/`. |
| `visit` / REX destination resource | `RexPublicPage` tracks successful playlist/viewer/thumbs/document rendering with reservation/resolver/resource/experience context. Exact resource type comes from the resolved reservation. |
| `visit` / `page` via route REX | Manual recording before full-document navigation to the catalog path; can subsequently be followed by ordinary-page tracking. |
| `visit` / `playlist` direct player | `PlayerPage` wraps successfully loaded positive playlist identity, without requiring a REX. Context completeness differs on the separate fast-player entrypoint. |
| `visit` / `article` | `ArticleRoute` wraps valid numeric article IDs. General page visit may also be attempted; it persists only for a matching registered literal path. |
| `click` / `outbound_link` | Homepage/Gallery YouTube link submits destination URL and placement in payload, with no numeric resource ID. Reports group by payload target URL. |
| `playlist_open`, `watch_more_click` / `playlist` | Engagement repository/service queries exist. No current ANA frontend producer for these keys was found; the standard ANA Admin page requests `visit` or outbound `click`, not this engagement service method. Similar funnel names in `user_events` are not these rows. |

Endpoint validation requires a nonblank event key, not an event-name allowlist. Other callers can submit additional keys/types; that does not make them approved published business events. `tracking_event_types` belongs to the separate funnel's validation, not ANA's current event endpoint.

### Stored event and browser identifiers

`ANAEvent` constructor fields, in order: `eventKey`, `isTest=false`, `reservationId=null`, `resolverKey=null`, `resourceType=null`, `resourceId=null`, `experienceKey=null`, `src=null`, `sessionId=null`, `referrer=null`, `viewerId=null`, `path=null`, `payload=[]`, `userAgent=null`, `reservationToken=null`. `withUserAgent(?string $userAgent): self` returns a copy.

The HTTP contract uses snake_case: `event_key`, `is_test`, `reservation_id`, `reservation_token`, `resolver_key`, `resource_type`, `resource_id`, `experience_key`, `src`, `session_id`, `referrer`, `viewer_id`, `path`, `payload`. Client-supplied top-level UA does not populate retained `user_agent`.

* `resolveSessionId()` exported from ANAProvider supplies a browser function using sessionStorage `ana_session_id`, a random UUID/fallback, and no inactivity timeout. It is a tab storage lifetime, not a timed visit session or SYSTEM login session. Storage failure yields null.
* `ensureViewerId()` in `src/lib/viewer.js` persists a random UUID in localStorage `viewer_id`, with no explicit expiry. ANAProvider catches access failures; callers elsewhere do not universally do so. A UUID is a browser profile identifier, not a person's authenticated identity or a cross-device ID.
* `resolveReferrer()` stores the first `document.referrer` under sessionStorage `ana_referrer`, including an empty string; subsequent providers/documents reuse it while present. Storage failure falls back to current document referrer.
* `useANA().track()` sends `window.location.pathname`, not search/hash, and gets source at call time. Query-specific views such as `/picker?set=11` share `/picker` identity; the central page payload is empty and does not retain the set ID.
* Standard `useANA` does not set `reservation_token`, even when it has a reservation ID; public resolve payload does not include token in its `rex` context. Token can still be visible in the event path. The older client/PHP DTO support an explicit token field; do not assume every row populates it.

### Source/referrer behavior

`initializeSourcePropagation()` establishes attribution once per document: explicit `src` → recognized external `document.referrer` → stored `ana_src` only when the current referrer is same-origin → empty source. A new untagged Direct entrance clears the stored source. Recognized mappings include Google→`google`, Pinterest/`pin.it`→`pin`, YouTube/`youtu.be`→`yt`, Bing, DuckDuckGo, Yahoo and Facebook.

`getCurrentSourceParam(search)` returns explicit search source when a search argument is supplied; ordinary calls use the current query then initialized session fallback. `withSourceParam()` and `applySourceToParams()` preserve source in internal links/parameters. Initial history replacement, patched push/replaceState, anchor mutation/click handling, and popstate support propagation. External destinations, downloads, fragments, file URLs and designated admin/API/static areas are excluded from automatic navigation rewriting. Current attribution deliberately overrides conflicting hardcoded internal source tags; it is not last-internal-link attribution.

Google is therefore recognized when the browser supplies its referrer and `google` is accepted in `src_params`; ANA stores the actual referrer separately. An unknown external host can remain Direct in source grouping while retaining its referrer. No referrer recovery is possible when the browser/app does not supply one. QR origin is not inferred: a QR needs an explicit accepted campaign source (`src`); there is no universally guaranteed `qr` key. Tagged source is not proof that a visitor genuinely came from that platform. Filtering Googlebot/Google-InspectionTool user agents does not filter ordinary human browsers merely because their referrer/source is Google.

**Preservation limit:** SPA navigation preserves original referrer/source, but referrer storage and source initialization have different reset rules. A later external entrance in the same tab can select a new source yet retain an old/empty `ana_referrer`. No inactivity/session reset synchronizes them. This is an implementation finding requiring a policy decision, not permission to alter existing attribution.

## 5. Public PHP entry points

### HTTP routes and access

| URL | Implemented contract and caller |
| --- | --- |
| `/api/v2/admin/ana/record-event.php` | Public POST → `RecordEventEndpoint::handle(PDO): never`. Main ANAClient default. Despite `/admin/` in URL, this doorbell does not include the admin guard. |
| `/api/v2/admin/analytics/record-event.php` | Same public POST handler, preserved compatibility URL used by `src/Analytics/analyticsClient.js`. |
| `/api/v2/admin/analytics/counts.php` | SYSTEM-authenticated GET `resource_type`, `event_key`; returns `ok`, echoed filters, `resource_types`, `items`. Inline construction of ANAReportService with playlist/article repositories. Current AdminAnalyticsPage caller. |
| `/api/v2/admin/analytics/events.php` | Authenticated thin adapter → `EventsEndpoint::handle(PDO): void`. GET drill-down or DELETE exact event ID; current visits drawer caller. |
| `/api/v2/admin/analytics/delete-tests.php` | Authenticated POST; inline `DELETE FROM analytics_events WHERE is_test=1`, returns `ok,deleted`. Confirmed AdminAnalyticsPage action; actual mutation, not a reporting filter. |
| `/api/v2/admin/ana/ignore-config.php` | Authenticated → `IgnoreConfigEndpoint::handle(PDO): never`; GET current patterns; POST JSON `patterns`, same-origin validation, returns `ok,item.patterns`. ANAIgnoreSettings caller. |
| `/api/v2/ana/pages-public.php` | Public GET, JSON/no-store; all registry rows projected as `id,title,path,og_title,og_description,og_image`. No `track_enabled` or `include_in_sitemap` filter. HTML prebuild consumer; not a tracking authorization list fetched by React. |

Recording: non-POST 405, invalid JSON/missing event key 400, exceptions including unknown sources 500 with message. Success is `{ok:true,event_id:<int>}`; ignored/unregistered/excluded success is exactly event ID 0, not a recorded event. No client retry queue exists.

Events GET requires resource/event plus a positive resource ID, or `target_url` for `outbound_link`. Optional `source_label` omitted means SQL NULL/Direct; an explicitly empty string is a real filter value. Result `{ok:true,items:[...]}` includes source/referrer/session/path/UA/test and reservation context. DELETE JSON `{id}` returns `{ok:true,deleted:1,id}`; invalid ID 400, not found 404, wrong method 405, other exceptions 500.

Ignore patterns reject malformed/generic tokens with 400; unknown errors log the exception class and return a generic 500. Counts/details/recording generally expose exception messages, unlike that sanitized settings failure. Authenticated API access is supplied by SYSTEM's `api/v2/admin/auth.php`/AdminGuard, including same-origin checks. Public recording does not run that guard.

`app/ANA/Endpoints/ReportEndpoint.php` is a top-level duplicate script and defines a global `respond()` function, not a callable static departmental class. Current routing does not include it. Its relative `../../../autoload.php` and `db.php` resolve outside the API folder; do not wire it as a working class based on its name.

### Service/repository contracts

| Interface | Inputs, outputs and errors |
| --- | --- |
| `ANAService::__construct(ANAEventRepositoryInterface $events, ANAIgnoreService $ignore, ?Closure $isVerifiedAdmin = null)`; `fromPdo(PDO $pdo): self`; `record(ANAEvent $event): int` | Factory injects repositories and lazy verified-admin check. Record returns inserted ID or 0; configuration/auth/storage errors can propagate. Calls server header policy before source/page insertion. |
| `ANAIgnoreService::__construct(PdoAppConfigRepository $configs)`; `patterns(): array`; `matches(string $userAgent): bool`; `save(array $patterns): array` | Uses `app_configs['ana_ignore'].patterns`, caches on instance, save updates same instance. Defaults only when configuration is absent/missing patterns; explicit empty array disables filtering. Invalid list throws `InvalidArgumentException`. |
| `PdoANAEventRepository::__construct(PDO $pdo, ?ANASourceRepositoryInterface $sources = null)`; `record(ANAEvent $event): int` | Page identity lookup then accepted-source query then parameterized INSERT. Null payload for empty array; encoding failure throws. No transaction or idempotency key wraps cross-request deduplication. |
| `PdoANASourceRepository::__construct(PDO $pdo)`; `isValidSource(string $src): bool` | Current database membership only, no hardcoded platform list or active flag filter/cache. Database failures propagate. |
| `ANAReportService::__construct(ANAReportRepositoryInterface $reports, ?PdoPlaylistRepository $playlists = null, ?PdoArticleRepository $articles = null)` | Optional owner-content readers supply titles; missing titles fall back by identity. |
| `ANAReportService::countEventsByResourceType(string $resourceType, string $eventKey): array`; `listResourceTypes(): array`; `countPlaylistEngagement(): array` | Delegated counts/types and enriched engagement rate. Repositories use row counts; engagement rate is watch-more/open percentage, not unique-visitor conversion. |
| `ANAReportService::listEventsForResource(string $resourceType, ?int $resourceId, string $eventKey, ?string $sourceLabel, ?string $targetUrl = null): array`; `deleteEventById(int $id): bool` | Detail rows and exact-ID deletion; invalid service/repository lookup inputs can return empty/false. No authorization inside service methods; doorbells guard administrative callers. |
| `PdoANAReportRepository(PDO $pdo)` | Implements the same five report-interface operations. Joins `ana_pages`/`src_params`; reads PV/document titles directly; SQL errors propagate. |

Ignore normalization accepts a list of at most 100 strings, trimmed token length 3–200 bytes, no control characters, case-insensitive deduplication. Generic `bot`, `crawler`, Mozilla/Chrome/Safari/AppleWebKit/Gecko prefixes are rejected. Defaults are `ElevenlabsCrawler`, `Googlebot`, `Google-InspectionTool`; matching is `stripos`, not regex, DNS or crawler authentication. Pattern loading is once per service instance/request path, not once per pattern; the next request sees saved configuration.

### Frontend interfaces

`ANAProvider({rex=null,viewerId=null,children})` supplies context without recording by itself. `useANA()` returns `track(eventKey, payload={}, resource={})`, resolving explicit resource fields before REX context. It catches recording failures, logs and returns null. `recordANAEvent(event, endpoint=defaultURL)` posts JSON with keepalive, applies test mode/local ignore, parses JSON, throws on unsuccessful response and persists server-recognized internal preference through the shared helper.

`ANATrack({eventKey='visit',payload={},resource={},oncePerSession=false,children})` invokes track once per mounted wrapper via ref. Optional session key is `ana_once:{session_id}:{event_key}:{resource_type}:{resource_id}`. Storage is an optimization; unavailable storage does not intentionally block rendering/tracking. Marks are set before request success, not after durable insertion.

## 6. Persistence and data relationships

| Table/configuration | Current use and boundaries |
| --- | --- |
| `analytics_events` | ANA-owned event identity `id`; event/test; reservation ID/token; resolver/resource/experience; `source_key`; session/referrer/viewer/path; payload JSON; retained UA and `created_at`. No IP, host-log request ID or authenticated visitor ID is written by current insert. |
| `ana_pages` | `id`, literal `path`, `title`, `track_enabled`; metadata `og_title`, `og_description`, `og_image`; `include_in_sitemap`. Current code reads/tracks/reports/builds from it. There is no generic page editor/write endpoint. One-time page corrections are standalone reviewed SQL migrations, never row-specific methods in runtime repositories. Do not assume registration is a React route declaration. |
| `src_params` | Accepted `src`, display label and descriptive metadata. Original stored keys remain unchanged; multiple keys with one label can aggregate into one report group. Archived migration replaced older `analytics_sources` FK; actual production constraints remain unverified. |
| `app_configs`, key `ana_ignore` | Pattern list, accessed through existing shared `PdoAppConfigRepository::getJson/setJson`; ANA owns this setting's semantics, not unrelated configs. |
| `playlists`, `articles`, `palette_viewers`, `project_documents` | Read dependencies for report titles. ANA owns neither content nor public/client eligibility. |
| `user_events`, `tracking_event_types`, `tracking_sources`, `tracking_audiences`; app-config `user_events_baseline` | Adjacent shared funnel and lookup/baseline storage, not current ANA source/event tables. Proposed consolidation must not merge their meanings silently. |

Current `analytics_events.created_at` is database `NOW()`. Shared `api/db.php` sets PHP America/Los_Angeles and PDO session offset for that date. Current SQL is therefore configured for Pacific wall-clock insertion; historical sessions/column types are not verified. AdminAnalyticsPage parses zone-less strings as browser-local time, so cross-timezone display can be misleading. The separate user-events repository explicitly generates UTC strings; do not mix its timestamps with ANA assumptions.

`2026_10_29_001_ana_crawler_filter.sql` adds nullable `user_agent VARCHAR(1000)` with historical rows left NULL; `2026_10_29_002_seed_ana_ignore.sql` seeds the existing config without overwriting authored values. Archived source migrations/tests document older `analytics_sources` and current `src_params`; they are not proof of current deployed rows/FKs. No page-registry migration or live values were inferred from fixture rows.

There is no explicit retention/TTL/purge job in the reviewed ANA code. Admin deletion physically removes rows; test deletion removes all test rows. No soft-delete/audit trail or bulk crawler-history classifier is implemented. Older UA-null rows cannot be reliably classified as bots solely from Direct source or timestamp correlations.

## 7. Dependencies and boundaries

### Verified collaborators

| Department/system | Current interaction |
| --- | --- |
| REX | Provides reservation/resolver/resource/experience context and stable navigation. Browser records events; resolver/server HTML entry does not directly insert ANA events. Tokens/aliases remain REX-owned. Route REX currently logs then redirects. |
| SYSTEM | Admin guards for reports/config/deletion; server verified-admin detection for first-event exclusion; browser login marks shared internal preference. Analytics preference cannot authorize admin access. No SYSTEM manifest exists in current tree. |
| PLAYLISTS | Direct player visits and report titles; REX playback supplies experience context. ANA does not change playlist availability/indexing flags or create share tokens. No PLAYLISTS manifest exists in current tree. |
| SAS | Sends existing normal/REX URLs tagged `src=sas`; downstream shared propagation/ANA records attribution if accepted. Sending does not itself constitute a website visit. No SAS manifest exists in current tree. |
| PUB | Pinterest packaging applies `src=pin`; destination visitors use ANA. No direct PUB→ANA insertion call was found. Planned provider metrics/pingbacks are not completed ANA provider integrations. |
| GALLERY / public React | Router tracks enabled page paths; YouTube Gallery item emits outbound click. Dynamic content result presentation remains GALLERY-owned. |
| PALETTES / PROJECTS / article infrastructure | PV/document/playlist/article identities/titles appear in reports; read-only joins/shared article repository do not transfer content ownership. |
| Site build / REX sitemap | Registry read endpoint feeds static metadata; REX sitemap reads inclusion flags at request time. This is a configuration dependency, not a reason to route every page through REX. |

### Architectural exceptions and observed debt

1. **Doorbells:** `counts.php`, `delete-tests.php` and `pages-public.php` contain orchestration or SQL. Only some paths have departmental adapters. `ReportEndpoint.php` duplicates reporting and is not a working namespaced adapter. Preserve current URLs until separately reviewed consolidation.
2. **Parallel old reporting:** Mixed-case `app/Analytics` duplicates reporting/title decisions and assumes REX route IDs for ordinary pages. Its record path already delegates centrally. No current production caller for its old reporting service was found; tests/legacy/external usage must be checked before retirement.
3. **Separate user-events funnel:** Shared services/repos/endpoints and dashboard have different schemas, UTC timestamps, mutable baseline and active-event validation. Its source lookup is `tracking_sources`, not `src_params`; internal events may be stored/filtered rather than skipped. Recommend evaluating ANA ownership, not merging rows/contracts as a mechanical cleanup. No live import of its frontend helper was found, but that does not prove external API disuse.
4. **Data/SEO coupling:** ANA registry drives tracking, REX sitemap and static SEO metadata. `pages-public.php` returns all rows, and prebuild sets `index,follow` for every registered subpage regardless of tracking/sitemap flags. `include_in_sitemap=0` is not noindex. Decide site-wide metadata ownership without redefining existing flags.
5. **Layering:** Path normalization/page policy currently sits in event repository; report repository selects titles from other domains while service separately enriches some titles. Read-only joins are allowed, but validation/ownership responsibilities are mixed.
6. **Browser-entry drift:** Main AppRouter mounts ANAProvider; fast `player-main.jsx` does not. Direct fast-player ANATrack can still submit a playlist visit, but default context supplies no ANA viewer/session/referrer; the legacy Analytics hook similarly lacks modern context. Do not claim complete context parity across every entrypoint.
7. **Referrer/source reset drift:** Original referrer persists across later same-tab external entrances; source can reset/change on those entrances. Missing/blocked browser referrers cannot be restored by this code. Unknown source validation can reject the entire event even when path/content are valid.
8. **Reporting semantics:** Aggregation groups by label and row count, does not exclude test rows by default, and does not deduplicate by reservation/session/viewer. The UI shows Direct for null/empty display labels and exposes actual source in detail. Tests and labels can affect apparent totals without changing stored source keys.
9. **Contract/test drift:** Older tests expect raw `source_key` results or omit newer required columns/tables. Presence of tests is not evidence every current test passes. The standard dashboard does not expose the implemented engagement method.

No new department is recommended. ANA is the existing candidate for analytics consolidation; final ownership of the adjacent funnel, shared source lookups and site-wide SEO/build policy requires owner approval.

## 8. Recording, navigation, reporting and discovery workflows

### Ordinary public page entrance

1. The app entrypoint initializes source propagation before React. The main router mounts ANAProvider and ANAPageTracker, including on direct `/search`, `/card`, `/browse-palettes` loads.
2. Tracker observes pathname, guards StrictMode/effect replay with `lastNavigation`, and calls `track('visit', {}, {resource_type:'page'})`. Query-only changes do not produce another pathname visit. Returning to a path after another path can produce a new event; root instead has session deduplication.
3. Client ignores marked own devices outside test mode, assembles context and posts. Public API constructs ANAEvent; service filters crawler/internal events and enriches server UA.
4. Repository strips search/hash/trailing slash from path, maps `/results/4` to `/`, queries `ana_pages WHERE path=:path AND track_enabled=1`; if absent returns 0. If found, validates non-null source and inserts with the resolved page ID.
5. Page registration changes take effect through the next event's DB lookup without frontend rebuild. Registering a path with a query string does not create a matching ordinary-page identity; use `/browse-playlists`, not `/browse-playlists?src=google`.

Registration controls storage/labels, not whether a request is attempted: the central tracker also attempts unregistered paths, which succeed with ID 0. A configured normal route can have the same source/referrer/session/viewer tracking as REX without a token.

### REX destinations and additional context

REX server resolution supplies bootstrap/API data under existing access rules. After successful resolution, RexPublicPage mounts a nested ANAProvider plus ANATrack for Player/PV/Thumbs/Document. Records add reservation ID, resolver key and destination/experience identity. The central top-level tracker separately attempts the literal `/t/{token}` as a page; normally no row exists, so it inserts nothing. Registering that literal path could create both page and destination rows.

For a route REX, `recordANAEvent()` is called directly with destination path, original `document.referrer`, ANA session and reservation context, then navigation proceeds in `finally`. It omits viewer ID and uses current document referrer rather than cached provider referrer. Page visit identity still resolves through `ana_pages`. The destination's new document then runs the ordinary tracker: on an enabled page this can count **two `visit/page` rows for one REX-mediated arrival**. For root, the manual REX event does not set the homepage once-key before its destination's first visit.

REX therefore adds share-link/reservation/destination/experience context; it does not provide exclusive Google detection or ordinary page tracking. Default reports group destination IDs/sources, not individual reservation IDs, though detail rows expose available reservation context. Resolver errors do not yield a successful destination visit, but the central page attempt may still occur.

### Reporting

Admin resource choices come from distinct stored resource types. Counts use requested event key; source display is `COALESCE(src_params.label,e.source_key)`, with NULL shown as Direct. Raw referrer does not independently classify/reclassify report groups. Thus Google recognition feeds `src=google`, then its registered label appears; absent recognition can display Direct despite a nonblank unknown-host referrer.

Detail filters use the same label grouping, not raw key equality; two keys sharing one label can appear together. Latest-visit paths are returned for same-origin Jump To. React text rendering escapes UA/referrer/payload strings; no raw HTML rendering of these detail fields was found. Test rows are marked in details but remain in ordinary counts until explicitly deleted. Single delete and delete-all-tests prompt in the UI and require server admin access.

### Sitemap and initial HTML

`RexSitemapService::siteUrls()` selects `SELECT DISTINCT path FROM ana_pages WHERE include_in_sitemap=1 ORDER BY path`, validates internal leading-slash paths, strips query/hash and deduplicates under fixed origin `https://colorfix.terrymarr.com`. `urls()` adds eligible active/unrevoked Public/Showcase playlist REX URLs, using existing playlist eligibility/resolution policy. Site-page rows do not undergo playlist eligibility or `track_enabled` checks.

`/sitemap.xml` maps to `sitemap.php`, emits XML/no-store, and is referenced by `public/robots.txt`. Changes to inclusion flags apply next request without rebuild. A sitemap entry supports discovery; it neither creates a React route nor guarantees Google indexing.

`scripts/prebuild-homepage-html.mjs` separately fetches `pages-public.php` and builds `dist/{path}/index.html` with clean React root, page title/description/OG/image, `index,follow` and canonical URL. This happens on build, not each request. It does not prerender subpage playlist content. `.htaccess` serves existing files/directories and otherwise the shared app shell; `/picker` has an explicit app-shell rewrite. New `/browse-playlists` should use its own registered clean path rather than canonicalizing the page to `/picker?set=11`.

### Browse Playlists landing — current integration; latest layout correction local only

`src/Router.jsx` exposes `/browse-playlists` as a normal child of the existing `App` → `AppLayout` → `MainLayout` route structure, under the existing ANAProvider and outside modal StandAloneLayout. It renders the existing PlaylistPickerPage with `landing=true`, fixing set 11 independently of query `set`. The shared App navigation supplies the ColorFix logo and existing Back button. Landing mode renders the requested heading/tagline/introduction without a duplicate logo banner or ColorFix Home link. No second ANATrack or event name was added. Existing picker URLs retain their original mode.

The landing reuses MainLayout's existing maximum width (1320px fallback), centering, responsive gutters and vertical padding, plus the normal App navigation/background. It does not define a competing page container or change html/body sizing/overflow. Landing-mode CSS only neutralizes the picker's viewport-height/flex-centering and panel/grid scroll constraints so the existing page scroll behavior reaches all content. The existing grid uses flexible columns within MainLayout content width in landing mode; its modal 1100px cap and fixed 240px card/track widths do not apply there. Grid and heading share the same gutters, thumbnails retain 4:3 proportions, and original picker modal rules remain active outside landing mode. The previous route bypass and full-viewport overrides are superseded by the container restoration recorded below. This is a presentation correction, not an ANA tracking change.

The landing requests `/api/v2/playlist-sets/get.php?id=11&marketing=1`. PLAYLISTS' existing endpoint/service adds an optional strict marketing mode: active/public/indexable playlists, Public/Showcase memberships and existing active/unrevoked REX only. Client/Concept and missing-token members are excluded, with no REX creation or flag changes. Nested picker set navigation propagates that stricter mode. Private/admin query overrides are not sent by this mode. Cards remain React Router Links; family search and return navigation use the existing flow. The independent existing color-family API was not redesigned.

The owner reports existing `ana_pages.id=4` has `/picker/?set=11`, tracking and sitemap enabled. That path does not match ANA's normalized `/picker` visit lookup, and metadata prebuild uses the raw path in canonical/OG metadata while stripping the query only for output directories. Reuse ID 4 rather than insert a second registration. The standalone `database/migrations/2026_11_09_001_browse_playlists_page.sql` prepares that correction while preserving the existing OG image and all `analytics_events.resource_id=4` relationships. It is not application repository code and has not been applied to production.

At the owner's clarification, the row-specific repository setup method, PHP callback and custom migration-runner support were removed. The established SQL runner remains unchanged. Review existing row/target-path conflicts and all pending SQL before approved application; unexpected paths or conflicts make this SQL a no-op, so verify the resulting row. No production database inspection/application occurred. See `docs/browse-playlists-landing.md` for deployment order and limitations.

Registry-driven prebuild produces page-specific title, description, robots, canonical and OG URL using the updated clean path. `.htaccess` has a narrowly scoped rule for the generated landing index to avoid an automatic DirectorySlash canonical change. Sitemap behavior itself is unchanged: applying the page configuration makes its clean URL eligible on the next sitemap request. Metadata still needs the normal prebuild/deploy cycle. Initial subpage HTML is a metadata shell, not server-rendered playlist cards; rendered anchor links and the existing eligible-REX sitemap provide discovery. No new prerendering system, redirect, reservation or alias was introduced.

Public marketing destinations updated locally: the specific homepage BrowsePlaylistsTile (React and static prebuild), unavailable-landing/player navigation and SAS Browse Playlists Quick Link. Existing versioned/audience/return/nested picker targets and every legacy URL remain valid. PLAYLISTS, SAS and GALLERY manifests are absent; their missing contracts are reported rather than invented. REX's documented sitemap behavior did not change, so its manifest remains untouched.

## 9. Security, privacy and retention behavior

Public recording is intentional for anonymous users and carries no admin authorization. It accepts much client-controlled context; no event-name/resource allowlist, visit authentication, request nonce, explicit rate limiting or origin validation was found in that handler. Hosting controls are unverified. Do not equate ordinary client telemetry with tamper-proof access logs. Parameterized SQL and server-observed UA address specific risks, not event authenticity.

Admin report/deletion/config doors load SYSTEM auth, whose guard checks session and same-origin request context. Crawler settings also explicitly validate origin. Included endpoint classes do not authenticate themselves independently. The stray ReportEndpoint script lacks a guard and has invalid bootstrap paths; do not expose it while doing a mechanical cleanup.

ANAService excludes a truthy internal cookie (`1/true/yes`), or verifies an existing SYSTEM session and sets a one-year analytics preference cookie. Browser localStorage/cookie marks survive logout and operate per browser/profile/origin; Mac recognition does not automatically mark another phone browser. Browser query opt-ins `cf_internal`/`internal_viewer` can mark the preference; this grants no privileges. Test-mode localStorage `analyticsTestMode` causes `is_test=1` and bypasses own-device exclusions, but not crawler exclusion.

Retained referrers may contain paths/query data, event paths may contain REX tokens, and arbitrary payloads can contain client-supplied data. No automatic redaction/consent policy or retention schedule was found in ANA. UA can be absent on historical/new requests; no IP is stored by this insert. Server/hosting access-log retention and correlation are outside this implementation. Historical UA-null events are not backfilled or guessed.

Session ID, source and referrer storage have no timer reset. Viewer/internal marks are browser-local, not shared identity or access controls. Browser referrer restrictions, blocked storage, blocked JS or network failures can reduce context/recording. The separate user-events funnel has different UTC/internal/baseline behavior; its `clear.php` sets/clears a reporting cutoff rather than deleting history.

### Counting and reliability risks verified from source

* Route REX plus its registered destination can produce two page visit rows; registering literal article/REX paths can also overlap existing resource visit events. These are distinct event contexts but can inflate totals if treated as unique arrivals.
* Main tracker only observes pathname: query-only picker-set changes are not separate visits and cannot identify which set was shown. This is current counting semantics, not automatically a defect to fix.
* Tracker/ANATrack session/ref marks precede success, and there is no retry/outbox. A rejected source, JSON challenge, blocked request, dropped connection or transient failure can lose an attempt; homepage dedupe can suppress retry for the session. Main tracker also runs before the child page has necessarily loaded successfully.
* ANATrack's ref is per mounted instance, not per resource prop. For example, an in-place ArticleRoute ID change can preserve the wrapper and suppress a new article visit. Actual navigation/remount behavior needs an isolated browser regression test before a fix.
* Original referrer can become stale within a reused tab while source changes; fast-player/old-hook context is incomplete. Shared history/source synchronization guards reduce one class of duplicate effect but do not prove universal deduplication.
* Every successful repository call inserts a row: no global database uniqueness/idempotency key prevents deliberate duplicates, reloads or repeated component events. Reports count test rows and source-label groups without unique-visitor semantics.

## 10. Changes, tests, and documentation maintenance

### Existing verification sources — inspected, not a blanket pass claim

| Test/source | Current intended coverage and limits |
| --- | --- |
| `api/tests/ANAUserAgentIgnoreTest.php` | Specific/mixed-case crawlers, human/missing UA and client-payload UA rejection; isolated fake insertion repository/config. |
| `api/tests/ANARecordingPolicyTest.php`; `fixtures/ANAFixtureDatabase.php` | Config cache/save, defaults, migration historical NULL, Direct/test/UA bounds, parameterized persistence, registered page-ID resolution, legacy adapter. Fixtures do not establish live page/source rows. |
| `api/tests/ANAInternalDeviceTest.php` | Cookie and verified-session first-event exclusion, unverified sessions, own-device test bypass and historical preservation. |
| `api/tests/ANASourceParamsTest.php`; `ANAClientSourceTest.php` | Accepted sources/migration, raw-key preservation/label report behavior, older FK compatibility. Historical expectations/schema may differ from current reports. |
| `api/tests/ANAViewerTitlesTest.php` | Intended PV title fallback/count preservation; fixtures expect older source fields and omit some current dependencies. Needs current-source review before claiming it passes. |
| `api/tests/ANARecordingHttpFixture.php`; `scripts/test-ana-recording.mjs` | Local fixture HTTP/browser recording, config auth/editing, crawler variants, internal/test policy, details/UA rendering and legacy URLs; uses temporary files/DB. Not executed for this documentation task. |
| `scripts/test-browse-playlists-layout.mjs` | Actual compiled router/CSS at 1920×1080, 1280×800 and 390×844 with 24 fixture cards: initial header geometry at scroll zero, shared page scrolling to the final card, no horizontal/picker overflow, actual MainLayout geometry equal to `/privacy`, and legacy modal constraints. Optional before snapshots compare `/privacy`, `/search` and picker sets 11/12 exactly. All APIs intercepted. |
| `scripts/test-source-propagation.mjs` | Intercepted browser fixtures for explicit/referrer sources, source clearing, internal links/history/query/hash, external/download exclusion, canonical safety and React source synchronization. Does not test all actual main-router/REX counting or stale-referrer scenarios. |
| `api/tests/RexSitemapTest.php` | Flag refresh, sanitized/deduplicated site paths and eligible/excluded REX URLs in fixtures. Not production indexability evidence. |

**Initial manifest audit checks:** repository/source/caller inventory, interface and route cross-checks, existing test inspection, 10 read-only JavaScript source-helper assertions (Google/Pinterest/YouTube and same-origin/lookalike recognition, explicit source, query/hash preservation and external-link exclusion), Markdown structure/table/render checks, and before/after file hashes. No app build, test that writes fixtures, browser visit that could record production events, API/database operation, deployment or Google live test was performed. That initial audit modified only this file. The subsequently authorized landing implementation and its verification are recorded below.

Before approved changes, characterize both recording aliases and report contracts, source/referrer/session behavior, own-device/crawler/test exclusions, existing page/REX event counts, sitemap flags, metadata prebuild and all current/legacy clients. Use safe fixtures for mutations. Update this manifest when an interface, ownership, policy or dependency changes; do not independently change the root constitution.

### Change log

| Pacific date | Change |
| --- | --- |
| 2026-11-09 | Created repository-based ANA manifest and public landing-page tracking investigation. Documented current contracts, legacy paths, counting/attribution risks, and unimplemented recommendations. |
| 2026-11-09 | Implemented/tested local Browse Playlists integration and clean OG URL generation; production page correction/deployment pending review. |
| 2026-11-09 | Removed row-specific repository setup and custom PHP migration support at owner direction; retained the one-time correction only as a standalone SQL migration. |
| 2026-11-09 | Corrected public landing layout: modal CSS load-order collision, viewport scroller and width cap; verified compiled desktop/mobile geometry and unchanged legacy pickers. Layout fix remains local only. |
| 2026-11-09 | Restored Browse Playlists to the existing App/MainLayout container and removed page-wide viewport overrides; verified standard-page and legacy-picker geometry unchanged. Container restoration is local only. |
| 2026-11-09 | Removed duplicate landing branding/Home link and corrected the existing grid sizing within MainLayout; checked three viewports and unchanged legacy/standard pages. Local only. |

## 11. Future Improvements

**Proposed only.** Priorities reflect current risk/value, not implementation authorization. All current event names, source reporting and public/client access must remain unchanged unless separately approved. Ownership conflicts remain for the owner to decide.

### 11.1 Priority 1 — Define and test page/REX visit counting boundaries

* **Problem:** Route REX plus destination can double-count page visits; per-mounted-wrapper refs and pre-success dedupe can suppress valid visits/retries. Path/query counting and test inclusion are implicit.
* **Affected components:** ANAPageTracker, ANATrack, RexPublicPage route branch, article/player wrappers, event repository and report aggregation.
* **Recommended ownership:** ANA owns event/deduplication semantics; REX owns destination navigation, and resource departments supply identity. Owner decides whether REX entrance and destination are separate visits and how reports present them.
* **Intended outcome:** Explicit compatible counting rules and targeted guards, preserving useful reservation context and current event names without a second tracking pipeline.
* **Dependencies/risks:** Existing historical/report expectations, homepage once behavior and external clients; removing one event could change established totals or erase link attribution.
* **Verification:** Isolated direct/SPA/back/reload, StrictMode, route REX→root/card, token/article changes, request failure followed by retry, query-only navigation and test-mode cases; compare exact rows/context, not access-log request counts.

### 11.2 Priority 1 — Align attribution/session reset and entrypoint context

* **Problem:** Cached original referrer can disagree with newly selected source; fast player/old hook lack full ANA context. Unknown inferred/tagged keys reject events.
* **Affected components:** ANAProvider, sourceParam, useANA, player-main, old Analytics hook, src_params lookup/reporting.
* **Recommended ownership:** ANA owns attribution/session semantics and shared client behavior; source departments keep navigation/content; SYSTEM owns authentication only.
* **Intended outcome:** Documented first-entrance versus later-entrance policy and complete supported entrypoint context, preserving accepted source keys and existing reports.
* **Dependencies/risks:** Owner decision on tab versus timed sessions/new external arrivals; source-row inventory; browser storage/privacy behavior. A reset-policy change can alter attribution of established journeys.
* **Verification:** Same-tab Direct→Google and Google→Pinterest, internal full reload/SPA/new tabs, missing/blocked referrer/storage, explicit accepted/unknown source, fast player and normal route parity, label grouping unchanged.

### 11.3 Priority 2 — Consolidate ANA adapters and decide adjacent funnel ownership

* **Problem:** Old Analytics reports/DTOs, inline API counts/deletes/registry reads and a misplaced ReportEndpoint duplicate capabilities. Separate shared user-events funnel overlaps analytics but uses different contracts/data.
* **Affected components:** app/Analytics, stray ReportEndpoint, counts/delete-tests/pages-public doorbells, shared user-event services/repos/lookups/admin API and dashboards.
* **Recommended ownership:** ANA is the recommended owner for analytics recording/reporting; SYSTEM retains guard; PLAYLISTS retains player/CTA content. Owner must explicitly decide whether/how the user-event funnel joins ANA and who owns its lookups; no new department is justified by current evidence.
* **Intended outcome:** One authoritative implementation behind preserved URLs, with deliberate adapters for genuinely different reports. Retire only verified obsolete callers/classes; do not mechanically combine events/tables or delete history.
* **Dependencies/risks:** External/CLI/maintenance caller verification, old report/schema characterization, source registry differences and UTC/Pacific compatibility. Removal by name alone can break older clients or dashboard totals.
* **Verification:** HTTP contracts, admin access, direct recording through both aliases/legacy PHP adapter, detail/count grouping, single/test deletion fixtures, separate funnel baseline/definitions/internal behavior and route/import checks before retirement.

### 11.4 Priority 2 — Clarify registry, sitemap and SEO policy ownership

**Local progress:** The focused Browse Playlists implementation uses the current mechanisms and a standalone ID-preserving SQL correction. The broader ownership, general authoring and independent-flag policy questions below remain unresolved; this item is not complete.

* **Problem:** Tracking registry also controls metadata builds and sitemap paths, but flags are independent; all-row prebuild sets index-follow even for non-sitemap/disabled tracking rows. No current page authoring interface was found.
* **Affected components:** ana_pages reads/report identity, pages-public, prebuild-homepage-html, RexSitemapService, route definitions and public metadata delivery.
* **Recommended ownership:** ANA owns page tracking identity/enablement; REX currently composes sitemap; page/content owners supply routes/content. Owner decides site-wide metadata composition and authoring responsibilities, rather than this audit assigning them anew.
* **Intended outcome:** Clear independent tracking/discovery/indexability controls using existing mechanisms, safe clean paths and consistent initial/rendered canonical metadata. Keep current REX token/alias eligibility unchanged.
* **Dependencies/risks:** Registry/live flags, desired public page list, static deployment behavior and existing REX sitemap policy; unintended exposure or broken canonical/old picker compatibility if flags are conflated.
* **Verification:** Tracking-on/sitemap-off and inverse fixtures, query stripping/duplicate paths, registered-but-unimplemented route, clean page metadata after build, robots/header/content checks and unchanged Public/Showcase/client REX cases.

### 11.5 Priority 2 — Specify telemetry trust, privacy and retention boundaries

* **Problem:** Public event context is largely client-supplied; arbitrary payload/path/referrer can retain sensitive navigation data, and no automatic retention/redaction policy is defined. Historical unknown-UA classification is inherently limited.
* **Affected components:** RecordEventEndpoint, ANA DTO/service/repository, settings/deletion/reporting, browser producers and hosting integration.
* **Recommended ownership:** ANA owns telemetry validation/minimization/retention policy; SYSTEM supplies admin security; hosting supplies edge controls. Owner decides retention and any event-validation/rate-limit policy.
* **Intended outcome:** Explicit supported input/trust and data-lifetime contracts without turning ANA into authentication or blocking crawlers/site access. Preserve historical source meaning and avoid unsupported bot reclassification.
* **Dependencies/risks:** Actual schema sizes/constraints and external clients; over-strict checks may drop legitimate events, while indiscriminate retention cleanup is destructive and requires separate approval.
* **Verification:** Fixtures for malformed/oversized context, server-UA authority, query/token handling, public versus admin origin/access, escaped details, explicit retention/deletion scope and no accidental publication/access changes.

### 11.6 Priority 3 — Align reporting timestamps, tests and observable failures

* **Problem:** Zone-less ANA timestamps are interpreted as browser-local; separate funnel uses UTC; old tests/engagement readers drift from current schema/UI. Ignored ID 0 and transport failures have limited operational distinction.
* **Affected components:** AdminAnalyticsPage formatting, ANAReportService/repository, shared DB/time infrastructure, tests/fixtures, client error handling and older user-event reports.
* **Recommended ownership:** ANA owns its report/time contracts and fixtures; shared time infrastructure remains shared; adjacent funnel owner is pending 11.3.
* **Intended outcome:** Accurate documented time/count semantics and current isolated regression coverage, without relabeling historical rows or adding speculative counters/log systems.
* **Dependencies/risks:** Read-only historical/schema/timezone verification and desired backward-compatible response/display contract. Blind timezone conversion can shift history; stale fixtures can conceal regressions.
* **Verification:** DST and differing browser timezone cases, label/raw-key/test-row behavior, missing resources, current schema fixtures, unknown-source and non-JSON failures; explicitly separate inspected, executed and production-verified evidence.

## 12. Completed Improvements

### 2026-11-09 — Browse Playlists landing integration (local only)

* Reused main-router ordinary-page tracking, added no event names/tracker, and kept source/referrer/internal exclusions intact.
* Prepared a standalone SQL correction for existing page #4, outside runtime repositories. Removed the one-time repository setup operation and restored the original migration runner. No schema change, duplicate registration or history rewrite; production execution remains pending separate review.
* Extended existing metadata prebuild to emit canonical-matching OG URLs. Page registration remains dynamic for recording/sitemap and build-time for metadata.
* Verified locally: 8 current focused PHP checks covering page identity/history, attribution, sitemap and PLAYLISTS marketing filtering; existing ANA internal-device (13), recording policy (27) and sitemap regressions; nine browser/build groups covering real main-router single visits, Google/SAS attribution, own-device exclusion, desktop/mobile, existing pickers, anchors, family search/return and initial metadata. PHP syntax, targeted lint/whitespace, frontend build and separate SAS build pass. Removed the tests for the discarded repository migration/CLI support. The standalone MySQL migration was not executed. Fixtures/intercepted requests only, no production events.
* Remaining: owner-reviewed standalone SQL correction, deployment and real Apache/live response checks. Existing public links remain compatible; no REX token/alias/resolver or production data was changed. Broader attribution, REX duplicate-counting, funnel consolidation and registry-ownership proposals are still unimplemented.

### 2026-11-09 — Browse Playlists normal-page layout correction (local only)

**Superseded:** The full-viewport expansion bypassed the established app container and was not authorized. The restoration below replaces that approach; these earlier measurements are historical, not the current layout contract.

* **Root cause:** Compiled CSS loaded `.playlist-thumbs { justify-content:center }` after the equally specific landing override. With a viewport-height flex container and an oversized nonshrinking panel, its header was centered above the scroll origin. At zero window/body/picker scroll, the logo measured approximately -250px at 1920px and -390px at 1280px. The retained internal scroller and 1100px panel/grid limits compounded the modal presentation. The route was already outside StandAloneLayout; this was not user scrolling.
* **Correction:** Landing-only block/document flow, normal page scrolling, scoped document overflow reset, uncapped panel/grid width and selectors that survive either shared Thumbs stylesheet loading later. Card markup/styles, router, ANA events/records, REX, eligibility, metadata, sitemap, SAS and PUB implementations remain unchanged by this fix.
* **Verification:** Compiled actual `/browse-playlists` route, 24-card API fixture, at 1920×1080, 1280×800 and 390×844. Logo top is now 87px/87px/69px and panel width 1857px/1217px/351px. Complete logo/heading/tagline/introduction fit at initial document scroll zero; no horizontal overflow or nested modal scroller; browser-wheel scrolling reaches the final card. `/picker?set=11` and set 12 geometry/computed layout match the captured pre-fix snapshots exactly. The existing nine-group landing browser regression, frontend build, targeted lint and whitespace checks pass. No production analytics requests or database operations were made.
* **Files:** `src/pages/PlaylistPickerPage/playlist-picker.css`, layout regression script, existing landing test's document-scroll reset, and this manifest. The owner reports the earlier landing deployed; this correction is local, not a claim of live deployment verification. PLAYLISTS has no department manifest in the tree, so no new manifest was invented.

### 2026-11-09 — Restore the established app container (local only)

* **Root cause:** Browse Playlists was a sibling of `App`/`MainLayout`, so it never received the shared ColorFix app container. The preceding clipping change additionally overrode document overflow and uncapped the page at viewport width. That approach diverged from the existing architecture.
* **Correction:** Moved the existing landing route inside `App` → `MainLayout`; App continues to supply AppLayout/navigation. Removed landing html/body overrides, viewport sizing/padding/background and uncapped grid rules. MainLayout owns width/centering/gutters. Landing CSS still disables only modal height/flex-centering/internal scrolling so the complete branded header stays visible. No replacement wrapper or shared-layout modification was introduced. Card/logo/header JSX and all analytics/data/SEO/REX/SAS/PUB behavior remain unchanged.
* **Verification:** Actual compiled routes with all APIs intercepted, 24 fixture cards, at 1920×1080, 1280×800 and 390×844. Landing MainLayout geometry matches `/privacy` exactly for width/maximum width, horizontal positioning, margins, padding and background. Desktop maximum width is 1320px; logo top is 134px/134px/112px after the existing shared navigation. Complete header is visible at scroll zero; no horizontal or nested picker overflow; shared page scrolling reaches the final playlist. Full before/after snapshots for `/privacy`, `/search`, `/picker?set=11` and set 12 are unchanged at all three sizes. Existing nine-group landing regression, frontend build, targeted lint and whitespace checks pass.
* **Files:** `src/Router.jsx`, `src/pages/PlaylistPickerPage/playlist-picker.css`, the existing layout regression script and this manifest. No database access/mutation, production analytics request or deployment occurred. Shared layout source files and the root constitution were not changed.

### 2026-11-09 — Landing branding and grid sizing correction (local only)

* **Root cause:** Restoring App/MainLayout left the page-specific logo banner and Home link in place. The existing shared grid retained `max-width:1100px`; picker rules additionally fixed its tracks and cards to 240px. At 1920px the app supplied 1256px of content, but the grid measured 1100px and four cards plus gaps used only 1008px. These were nested modal constraints, not a MainLayout width defect.
* **Correction:** Removed only landing-mode duplicate branding/Home markup and their unused CSS. Extended the existing landing-mode grid rules with responsive flexible tracks, full available content width and automatic card width; removed modal grid-wrapper horizontal inset so cards align with the heading. Shared App/MainLayout/navbar, 4:3 thumbnails, card styling, links and family-search flow remain intact. No independent container/grid component or global layout override was introduced.
* **Verification:** Actual compiled route in Chromium at 1920×1080, 1280×800 and 390×844 using 24 fixture cards and intercepted APIs. Grid widths are 1256px, 1201px and 343px, respectively, with four/four/one columns. Heading/tagline/introduction and standard navigation/Back are visible at scroll zero; no duplicate banner/Home link, horizontal overflow or nested picker scroller. Page scrolling reaches the final playlist; cards retain existing clickable REX links and thumbnail proportions. Before/after computed layout snapshots for picker sets 11/12, `/privacy` and `/search` are unchanged. Existing nine-group browser regression, frontend build, focused lint and whitespace checks pass.
* **Files:** `src/pages/PlaylistPickerPage/index.jsx`, its existing CSS, both existing landing browser scripts, and this manifest. PLAYLISTS has no department manifest in this tree; no new manifest was invented. This is a presentation correction only: ANA runtime/database, REX, backend eligibility, SEO/sitemap, SAS and PUB were not modified. Local only; no deployment or production analytics requests.

After separately approved improvements are implemented and verified, record their date, scope, ownership/interface effects, tests and local/deployed status here; update the relevant future item and technical inventory together.
