# Browse Playlists landing — local implementation and deployment review

Status: **LOCAL ONLY; no production database mutation or deployment performed.**

## Existing ANA page #4: verified code behavior

The owner reports `id=4`, `path=/picker/?set=11`, `track_enabled=1`, `include_in_sitemap=1`. Those production values have not been independently queried during this task.

* `PdoANAEventRepository::record()` parses the event path to its pathname and trims trailing slashes, then matches `ana_pages.path` exactly. `/picker?set=11` becomes `/picker`; neither it nor `/picker/?set=11` matches the reported query-bearing registry path. An explicitly supplied page ID cannot force a match.
* Existing page history references `analytics_events.resource_type='page', resource_id=4`; reporting joins that ID to `ana_pages.id`. Updating the existing registration preserves those relationships. No event is rewritten and no duplicate page is inserted.
* The metadata builder strips query/fragment for its output directory but uses the original registry path in canonical/OG metadata. Reusing #4 with clean `/browse-playlists` corrects both identity lookup and generated metadata.
* `RexSitemapService::siteUrls()` already selects the flag and strips query/fragment. Previously the reported row would produce `/picker/`, not a dedicated set-11 URL. After the reviewed update it produces `/browse-playlists`, without changing REX eligibility.

## One-time database correction — standalone SQL, not runtime code

The one-time update belongs in `database/migrations/2026_11_09_001_browse_playlists_page.sql`, outside application repositories. The row-specific repository method, PHP callback and custom migration-runner support have been removed at the owner's instruction. The established SQL migration runner is unchanged.

Before applying, independently review the configured database's page #4, any other `/browse-playlists` registration, and its historical event count. No production preview or mutation was run during this task. Review all pending SQL files before invoking the existing migration runner; it applies pending migrations, not just this file.

The SQL updates existing ID 4 only at one of the known old/new paths, provided no other row owns the new path. It changes the title, clean path, tracking/sitemap flags and SEO title/description. It preserves the authored OG image and all event rows. Unexpected identity/path or a conflicting registration causes no row to be updated; this is a guarded no-op, not an exception. Verify the resulting row and historical count after the approved application rather than assuming migration bookkeeping proves a change occurred.

There is no callable page-4 setup operation in ANA and no custom preview/apply CLI. Runtime tracking remains unchanged. MySQL execution of the standalone SQL has not been verified against production. Local tests exercise page identity/history using fixture-only data setup; they do not claim to execute this MySQL migration.

For a post-commit correction, retain the prior metadata and use a separately reviewed standalone SQL migration. Never delete/reinsert the page or rewrite its historical events.

## Local route and visibility behavior

* `/browse-playlists` uses existing PlaylistPickerPage with `landing=true` and fixed set 11, independent of a query `set` override. Its branding, Home link and scroll presentation are scoped to this mode; old picker URLs keep the original presentation.
* The request adds `marketing=1` to the existing playlist-set endpoint. PLAYLISTS opts into active/public/indexable members with Public or Showcase experience and an existing active/unrevoked REX. Client, Concept, private, inactive, nonindexable, missing and revoked reservations are excluded. No tokens or membership/publication flags are changed.
* `include_private` overrides are not sent by this mode; the public set endpoint already uses public retrieval rather than an admin/private override. Existing set retrieval remains unchanged when marketing mode is absent. Nested set links propagate marketing mode.
* Cards remain React Router Links to existing REX URLs. Color-family search retains its existing implementation and return link. That independent legacy endpoint was not refactored into a new search or REX creation pipeline.
* Ordinary ANA tracking is reused: no extra ANATrack, new event name or attribution change. Live recording requires the reviewed registry update. Known unrelated REX double-counting and stale-referrer concerns remain unchanged.

## Build/deployment order after approval

1. Review the local changes and standalone SQL migration. Make the PLAYLISTS service/endpoint files available before using the new marketing-mode contract; ANA recording needs no repository deployment change.
2. Inspect the registry row and pending migrations against the intended database, review the proposed SQL correction, and obtain approval before applying it through the established SQL migration process. Preserve page #4's before values.
3. Run the established metadata build using that updated registry: `npm run build:prebuilt-home`. The plain `npm run build` check alone does not generate the registered landing HTML. The normal metadata prebuild fetches `/api/v2/ana/pages-public.php`; it must read the intended updated database.
4. Deploy the normal build artifacts, including `browse-playlists/index.html`, shared assets/app shell, updated `.htaccess`, and affected backend files. Deploy/build the SAS bundle through its existing process for the updated Quick Link. No deployment was performed by this task.
5. Check the live clean URL, optional trailing slash, anonymous content and API, initial and rendered title/description/canonical/OG URL, absence of noindex in HTTP/meta, and `/sitemap.xml`. The narrow Apache rule serves generated metadata at the clean URL without DirectorySlash redirect; real Apache behavior is not covered by Vite tests.
6. Verify one ordinary-page ANA row under ID 4 with an isolated/test-mode or controlled visit, while preserving own-device exclusion and avoiding unnecessary production events. Compare historical page-4 events before/after the migration.

Google discovery: initial HTML contains metadata and the normal empty React root; no new card prerenderer was added. Rendered cards remain crawlable anchors; eligible playlist REX URLs also retain their existing sitemap discovery. A real Google rendered/live check is still needed after deployment; no indexing guarantee is claimed.

## Files changed

| File | Purpose |
| --- | --- |
| `.htaccess` | Narrow clean landing URL → generated index rule. |
| `src/Router.jsx` | Public route under existing ANA provider, outside modal layout. |
| `src/pages/PlaylistPickerPage/index.jsx` | Existing picker landing mode, set 11, branding/Home, strict request/nested navigation; legacy defaults retained. |
| `src/pages/PlaylistPickerPage/playlist-picker.css` | Scoped desktop/mobile public-page layout. |
| `app/PLAYLISTS/Endpoints/PlaylistSetGetEndpoint.php` | Delegates optional marketing mode. |
| `app/PLAYLISTS/Services/PlaylistSetService.php` | Strict marketing visibility using existing metadata and REX reads. |
| `database/migrations/2026_11_09_001_browse_playlists_page.sql` (new) | Reviewed one-time page correction outside application repositories; not executed. |
| `scripts/prebuild-homepage-html.mjs` | Canonical-matching OG URL and specific static-home marketing target. |
| `src/helpers/routingHelper.js` | Exact BrowsePlaylistsTile marketing target mapping; preserves other picker URLs/parameters. |
| `src/components/GalleryItems/SearchItem.jsx` | Uses that mapping for the existing homepage tile. |
| `src/pages/LandingPage/index.jsx` | Public unavailable-page Browse Playlists link. |
| `src/pages/PlayerPage/index.jsx` | Public player navigation link. |
| `src/SAS/services/quickSendLinks.js` | Browse Playlists Quick Link; retains `src=sas`. |
| `api/tests/BrowsePlaylistsTest.php` (new) | In-memory page identity/history/sitemap and marketing eligibility regression checks. |
| `scripts/test-browse-playlists.mjs` (new) | Intercepted browser, responsive, navigation, ANA and temporary metadata-build fixtures. |
| `app/ANA/DeptManifest.md` (updated; previously untracked) | Current interfaces, workflows, local completion and pending production steps. |
| `docs/browse-playlists-landing.md` (new) | This delivery and deployment review. |

REX/PUB/root manifests remain unchanged. PLAYLISTS, SAS and GALLERY have no DeptManifest in this tree: those are contract gaps, not permission to invent new manifests in this assignment. REX was inspected; its implementation/eligibility/sitemap contract did not change.

## Verification

* `php api/tests/BrowsePlaylistsTest.php`: 8 current page identity/history, attribution, sitemap and marketing checks passed. Removed the obsolete repository/CLI migration tests.
* Existing `ANAInternalDeviceTest.php`: 13 checks passed; `ANARecordingPolicyTest.php`: 27 checks passed; `RexSitemapTest.php`: passed.
* `node scripts/test-browse-playlists.mjs`: 9 browser/build groups passed using actual main router at desktop and 390px mobile sizes, with intercepted APIs; no production requests. Metadata prebuild used a local fixture registry in a temporary root.
* PHP syntax checks passed for all changed/new PHP files; targeted frontend lint and whitespace checks passed. Main frontend and separate SAS builds passed; existing unrelated CSS `//smaller screens` warning remains.
* Real production schema/registry data, Apache responses, deployment and Google rendering remain unverified. No production mutation, remote send, REX creation or deployment occurred.
