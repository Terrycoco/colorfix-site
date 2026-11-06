# ANA configurable crawler filtering

Status: LOCAL ONLY. No production migration, deployment, configuration edit, historical update, reclassification, or deletion was performed.

## Existing patterns used

- Configuration: `app_configs.config_key/config_json`, accessed through `App\Repos\PdoAppConfigRepository`, as used by Watch/Card configuration and the legacy user-event baseline. Production schema and keys were inspected read-only before implementation.
- Public APIs remain dumb doorbells. Both recording URLs bootstrap autoload/PDO and call `App\ANA\Endpoints\RecordEventEndpoint::handle`.
- The endpoint validates the request and maps its existing fields to the ANA DTO. `ANAService` owns filtering and server-header capture; `PdoANAEventRepository` owns parameterized persistence.
- The old `App\Analytics` repository's recording method is a compatibility adapter into the same ANA service. Its report methods remain unchanged. There is now one production `INSERT INTO analytics_events` implementation.
- Settings use the existing SYSTEM guard, `AdminHttp`, `PdoAppConfigRepository`, and AdminDialog/AdminField/AdminButton UI components. Event details use the existing Analytics drawer/grid.

## Configuration and behavior

Key: `ana_ignore`. JSON shape:

```json
{"patterns":["ElevenlabsCrawler","Googlebot","Google-InspectionTool"]}
```

Edit through **Admin Analytics → Crawler filtering**. Use one specific literal user-agent substring per line. Matching is case-insensitive. Empty lines are removed, duplicate case variants are combined, and an explicitly empty list disables filtering. Generic browser tokens such as Mozilla/5.0 are rejected. Limits: 100 patterns, 3–200 bytes per pattern.

The existing configuration repository has no persistent cache. The ANA config service memoizes the loaded list for its request/service instance; it does not query once per pattern. Settings responses are no-store. A save refreshes the same service instance and the next HTTP recording request loads the new database value. The seeded defaults apply if the configuration key is absent; an existing empty list is authoritative.

Only `$_SERVER['HTTP_USER_AGENT']` determines matching and the retained `user_agent` field. Client-supplied user-agent values are ignored. The raw header is checked against the list, then retained text is bounded to 1,000 UTF-8 characters. A missing or empty header records NULL and does not automatically cause exclusion. Direct attribution and blank referrers do not affect this policy.

Matching requests return the existing HTTP 200 `{ "ok": true, "event_id": 0 }` and do not reach event persistence. The crawler check applies in Test Mode too. Existing client-side own-device exclusions remain unchanged; ordinary admin visits are suppressed unless Test Mode is enabled. Src values, test flags, session/viewer IDs, request contracts and registered-page eligibility remain intact. The existing reservation_token column is carried through the shared DTO/persistence path so the older recording adapter retains its existing token value.

The new User Agent column in the event-detail drawer is rendered as ordinary React text, without HTML injection. Historical NULL values display an em dash.

## Migrations

Apply in order through the existing migration runner BEFORE deploying the new recording code:

1. `database/migrations/2026_10_29_001_ana_crawler_filter.sql`: adds nullable `analytics_events.user_agent VARCHAR(1000) DEFAULT NULL`. Existing rows remain NULL. No UPDATE or backfill.
2. `database/migrations/2026_10_29_002_seed_ana_ignore.sql`: seeds the three requested tokens using the existing config table; preserves an already-authored config row.

They are separate migrations so the runner's existing duplicate-column handling cannot skip the config seed if the column already exists. Neither migration was executed in production. Tests apply the actual column alteration and seed in an isolated SQLite fixture, with the seed's MySQL upsert translated to SQLite's equivalent; production MySQL application remains pending.

## Exact changed files for this request

Runtime/configuration/admin UI:

- `api/v2/admin/ana/record-event.php`
- `api/v2/admin/analytics/record-event.php`
- `api/v2/admin/ana/ignore-config.php` (new)
- `app/ANA/DTO/ANAEvent.php`
- `app/ANA/Endpoints/RecordEventEndpoint.php`
- `app/ANA/Endpoints/IgnoreConfigEndpoint.php` (new)
- `app/ANA/Services/ANAService.php`
- `app/ANA/Services/ANAIgnoreService.php` (new)
- `app/ANA/Repos/PdoANAEventRepository.php`
- `app/ANA/Repos/PdoANAReportRepository.php`
- `app/Analytics/Repos/PdoAnalyticsEventRepository.php`
- `app/SYSTEM/Resources/admin-route-coverage.json` (register protected config route)
- `src/pages/AdminAnalyticsPage/index.jsx`
- `src/pages/AdminAnalyticsPage/ANAIgnoreSettings.jsx` (new)

Migrations:

- `database/migrations/2026_10_29_001_ana_crawler_filter.sql` (new)
- `database/migrations/2026_10_29_002_seed_ana_ignore.sql` (new)

Tests/fixtures:

- `api/tests/ANAUserAgentIgnoreTest.php` (updated from the preceding local substring-filter change)
- `api/tests/ANARecordingPolicyTest.php` (new)
- `api/tests/ANARecordingHttpFixture.php` (new; CLI development-server/environment gated)
- `api/tests/fixtures/ANAFixtureDatabase.php` (new)
- `api/tests/ANAClientSourceTest.php` (nullable UA/token fixture columns)
- `api/tests/ANASourceParamsTest.php` (nullable UA/token/config fixtures, SQLite dialect adapter, current source_label report assertion)
- `scripts/test-ana-recording.mjs` (new)

Documentation:

- `docs/ana-crawler-filtering.md` (this file)

Existing unrelated workspace changes, including pending REX SEO and playlist-list UI work, were not deployed or incorporated into this feature's source edits.

## Verification results

- PASS: PHP syntax for all 17 changed PHP files.
- PASS: `php api/tests/ANAUserAgentIgnoreTest.php` — 13 substring-exclusion/ordinary-event checks.
- PASS: `php api/tests/ANARecordingPolicyTest.php` — 27 migration, parameterized storage, UTF-8 bound, request-cache, config refresh, Direct/test, history-preservation and legacy-adapter checks.
- PASS: `node scripts/test-ana-recording.mjs` — 34 isolated HTTP/browser checks across BOTH recording URLs, including uppercase/lowercase variants of all seeded crawlers, HTTP 200/event_id 0 with no insertion, human and missing-UA storage, client-supplied-UA rejection, Direct/blank referrer, protected configuration, cross-origin refusal, editable/empty lists, own-device exclusion, Test Mode, and escaped UA display in the actual Analytics event-detail grid.
- PASS: existing ANA client-source migration and src_params/source-report regressions, using local fixtures only.
- PASS: `node scripts/audit-system-admin-guards.mjs` — 343 protected routes classified, including the new config API; both public receivers stay public.
- PASS: `npm run build`.
- PASS: scoped `git diff --check`.

No IP/DNS lookup, visit counter, rule counter, ignore log, traffic-classification field or request-ID system was added. Site access, robots.txt, indexing, playback, REX resolution and client permissions were not changed.


## Own-device exclusion correction — November 2, 2026

Local fix; not deployed. ANA previously checked only in-memory admin state, so
page entrances could record before session verification and direct REX tracking
could bypass the hook check. The existing `cf_internal_viewer` local-storage/cookie
preference from the older tracker is now shared by all ANA client paths.

- A verified ColorFix login/session or successful protected SAS search marks the
  current browser. SAS sign-in also marks it immediately.
- The marker survives logout, page reloads, and the loss of an admin cookie. It
  controls analytics only and never authorizes an admin API.
- Client-side normal events from marked devices stop before fetch, including
  direct REX calls and the legacy analytics client.
- The central ANA service also honors the marker cookie. A verified SYSTEM
  session covers initial requests that arrive before browser marking; that path
  sets the marker cookie, which the client synchronizes into local storage.
- Ordinary anonymous visitors incur no extra authentication lookup. Direct
  attribution and blank referrers remain valid visitor events.
- Explicit Test Mode still records `is_test=1`; crawler filtering still applies.
- Explicit `cf_internal=1` / `internal_viewer=1` URLs retain their existing marking
  behavior. Storage is scoped to the browser/profile and ColorFix origin.
- No schema change, historical update, or deletion is part of this correction.

Changed files for this correction:

- `src/helpers/authHelper.js`
- `src/helpers/userEvents.js`
- `src/ANA/ANAClient.js`
- `src/ANA/useANA.js`
- `src/Analytics/analyticsClient.js`
- `src/Analytics/useAnalytics.js`
- `src/SAS/components/SASSignIn.jsx`
- `src/SAS/services/photosApi.js`
- `app/ANA/Services/ANAService.php`
- `api/tests/ANAInternalDeviceTest.php`
- `scripts/test-ana-recording.mjs`
- `scripts/test-sas-auth.mjs`
- `docs/ana-crawler-filtering.md`

Verification: own-device PHP tests (13 checks), ANA policy (27 checks), crawler
substring (13 checks), expanded ANA HTTP/browser fixture (40 checks), existing
login/browser regressions, SAS shared-login/persistence/revocation/browser tests,
full production build, PHP syntax and scoped whitespace validation passed.
