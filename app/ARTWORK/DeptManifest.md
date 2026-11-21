# ARTWORK — Department Manifest

**UPDATED:** 2026-11-20 20:10:42 PST

**Department:** `ARTWORK`  
**Location:** `app/ARTWORK/`  
**Namespace:** `App\ARTWORK`  
**Status:** Initial implementation inventory, 2026-11-08  
**Authority:** Repository-root `SystemManifest.md`; follows `app/PHOTOS/DeptManifest.md` section format.  
**Evidence:** ARTWORK repository and backend ZIPs, Photo Library + ArtworkDialog integration ZIP, and the user-supplied phpMyAdmin table-structure screenshot. This describes **supplied code**, not an independent audit of deployed production files.

## 1. Mission and ownership

ARTWORK owns **metadata and interpretation of works of art represented by photographs in the Photo Library**. It records the artist, titles, approximate date, medium, museum/collection, source URL, historical notes, and color analysis. It provides administration operations to create, retrieve, revise, list, and remove those metadata records.

ARTWORK does **not** own the image file, Photo Library row, source-photo crop, photo classification, saved paint palette, or the shared Gallery rendering/query system. Those remain with PHOTOS, PALETTES, and GALLERY. ARTWORK does own the public artwork-only list interface described below; do not duplicate Gallery rendering or search logic in ARTWORK.

## 2. Core invariants

1. **One record per photograph:** `photos_artwork.photo_library_id` is unique; each artwork metadata record references exactly one existing `photo_library` row. The artwork identity is `artwork_id`; the Photo Library identity remains `photo_library_id`.
2. **Photo first:** `ArtworkService` checks that a PHOTOS record exists before creating, reading by photo ID, editing, or deleting artwork metadata.
3. **Metadata only:** Deleting artwork metadata does **not** delete the original photograph, its saved palette, or its crops.
4. **Single owner:** ARTWORK is the authoritative writer of `photos_artwork`; callers use its documented service or API instead of maintaining another metadata table.
5. **Partial edits:** `updateByPhotoId` changes only provided columns. Nullable metadata fields can be cleared with `null`; `circa` is non-null and defaults to `0`.
6. **Source type is separate:** `photo_library.source_type='art'` is a PHOTOS classification used by the current Photo Library UI to expose the Artwork icon. The current ARTWORK service verifies that the photo exists, **not** that its source type equals `art`. Do not silently assume a database or service constraint enforcing that classification.
7. **Reuse existing palettes:** Palette relationships, paint colors, color-chip validation, and viewer presentations are PALETTES/PHOTOS concerns. `color_analysis` is textual artwork commentary, **not** an alternate paint palette store.
8. **API boundaries:** The public API file is a thin authenticated doorbell; the ARTWORK endpoint owns HTTP handling and delegates domain work to the service and repository.
9. **Shared PDO:** Services and repositories use an injected PDO and do not establish competing cross-department transaction boundaries.

## 3. Directory and file inventory

| Location | Responsibility |
|---|---|
| `Repos/PdoArtworkRepository.php` | Direct prepared-statement persistence for `photos_artwork`: lookup, list, create, partial update, delete, metadata field validation. |
| `Services/ArtworkService.php` | Artwork rules: require existing PHOTOS record; reject duplicates; coordinate create/read/update/delete. |
| `Endpoints/ArtworkEndpoint.php` | Admin GET/POST request decoding, action routing, status codes, JSON responses, error mapping. |
| `api/v2/admin/artwork/index.php` *(outside ARTWORK)* | Thin API doorbell: OPTIONS response, shared autoload/database/admin authentication bootstrap, then calls the ARTWORK endpoint. |
| `src/components/ArtworkDialog/ArtworkDialog.jsx` *(frontend)* | Reusable artwork metadata modal; additionally lists/searches/adds/removes linked design photos through PHOTOS admin inspiration endpoint; displays caller-supplied photo URL. |
| `src/components/ArtworkDialog/ArtworkDialog.css` *(frontend)* | Dialog presentation and responsive styling. |
| `AdminPhotoLibraryPage/index.jsx` *(frontend integration)* | Displays Photo Library type dropdown and conditional Artwork icon; opens `ArtworkDialog` with the selected photo. |
| `AdminPhotoLibraryPage/admin-photo-library.css` *(frontend integration)* | Type selector and Artwork icon presentation. |

**Scope note:** The frontend ZIP contains `AdminPhotoLibraryPage/...` paths for its supplied files; verify actual installed page location in the repository before editing. Do not move these files based solely on ZIP paths.

## 4. Artwork metadata fields

`photos_artwork` schema as shown in phpMyAdmin:

| Column | Meaning / constraints |
|---|---|
| `artwork_id` | Unsigned integer, auto-increment primary key. |
| `photo_library_id` | Unsigned integer, required; unique key `uq_artwork_photo`. References the photograph; confirm foreign-key details in production schema if modifying relationships. |
| `artist` | Nullable `varchar(255)`; enter names in natural order, e.g. `Jean-Auguste-Dominique Ingres`. |
| `artist_last` | Existing owner-added field for the authored surname, including compound surnames. Repository reads/create/partial updates support it; public gallery items expose it. Gallery sorting and tooltips use it without splitting the name, falling back to the complete `artist` when blank. No schema change is performed. |
| `official_title` | Nullable `text`; official artwork title. |
| `display_title` | Nullable `varchar(255)`; optional user-friendly title. |
| `year` | Nullable unsigned `smallint`; year if known. |
| `circa` | Non-null `tinyint`, default `0`; approximate-date indicator. |
| `medium` | Nullable `varchar(150)`. |
| `museum` | Nullable `varchar(255)`; museum or collection. |
| `source_url` | Nullable `varchar(2048)`; provenance/reference URL. |
| `historical_notes` | Nullable `text`. |
| `color_analysis` | Nullable `text`; study of the artist's color relationships. |
| `created_at` | Non-null `datetime`, default current timestamp. |
| `updated_at` | Non-null `datetime`, default current timestamp with automatic update. |

The screenshot establishes column/index structure, not all referential constraints. Do not invent migrations or additional tables from this manifest.

## 5. Public PHP entry points

### HTTP endpoint and doorbell

**Doorbell:** `api/v2/admin/artwork/index.php`. Requires the existing autoloader, DB connection, and admin auth, then calls `App\ARTWORK\Endpoints\ArtworkEndpoint::handle($pdo)`. Endpoint code must **not** be exposed without the doorbell's authorization context.

**URL:** `/api/v2/admin/artwork/index.php` (the frontend builds it through its `API_FOLDER` configuration).

| Method / action | Request | Response / behavior |
|---|---|---|
| GET `action=list` | Optional `limit` (default 100, max 500), `offset` (default 0) | `{ok:true, items:[...]}` ordered by descending `artwork_id`. |
| GET `action=get` | `artwork_id` **or** `photo_library_id` | `{ok:true, artwork:row}`; by photo ID, `artwork` can be `null` when the photo exists but has no metadata record. |
| POST JSON `action=create` | `photo_library_id`, `fields` object | Creates metadata; `201`, `{ok:true, artwork:row}`. |
| POST JSON `action=update` | `photo_library_id`, `fields` object | Updates metadata; `{ok:true, artwork:row}`. |
| POST JSON `action=delete` | `photo_library_id` | Removes metadata only; `{ok:true, deleted:true, photo_library_id:id}`. |

`GET` only allows `list/get`; `POST` only allows `create/update/delete`. The endpoint returns JSON `400` for invalid input, `404` for missing photo/artwork, `409` for duplicate artwork, and `500` for unexpected/database errors. Unsupported HTTP methods return `405`.

### Service interface for PHP callers

`new ArtworkService(PDO $pdo)` provides:

- `findById(int $artworkId): array`
- `findByPhotoId(int $photoLibraryId): ?array`
- `list(int $limit = 100, int $offset = 0): array`
- `create(int $photoLibraryId, array $fields): array`
- `update(int $photoLibraryId, array $fields): array`
- `delete(int $photoLibraryId): void`

**Repository interface:** `new PdoArtworkRepository(PDO $pdo)` provides `findById`, `findByPhotoId`, `list`, `create`, `updateByPhotoId`, and `deleteByPhotoId`. Other departments should prefer the service, which enforces existence and duplicate checks.

## 6. Persistence and data relationships

**ARTWORK-owned table:** `photos_artwork`, with one metadata row per `photo_library_id`.

**Related but externally owned tables:**

- `photo_library` (**PHOTOS**): artwork's photograph; the service reads it through `App\PHOTOS\Repos\PdoPhotoLibraryRepository::findById`. ARTWORK does not write or delete Photo Library rows or files.
- `photo_crops` (**PHOTOS**): selected non-destructive crop geometries, unchanged by artwork metadata edits.
- Saved palette records (**PALETTES**, with the current photo-to-palette association represented in PHOTOS): not joined or written by the current ARTWORK code.
- Gallery display and art-swatch rendering (**GALLERY**): GALLERY owns the visual presentation and mixed-gallery queries. ARTWORK supplies a public, artwork-only list of display-ready art-swatch rows through `ArtworkPageService::listGallery()`.

Artwork records are **not** a replacement for `photo_library.source_type`. The `source_type` dropdown writes classification through the Photo Library's existing update workflow. The `photos_artwork` record stores the painting's metadata.

## 7. Dependencies and boundaries

- **Backend:** PHP PDO; `App\PHOTOS\Repos\PdoPhotoLibraryRepository` for photo existence; shared autoload/DB/admin auth bootstrap in the public doorbell.
- **Frontend:** React, `createPortal`, shared `API_FOLDER` config, browser `fetch`; reusable `ArtworkDialog` takes `open`, `photoLibraryId`, `imageUrl`, `onClose`, `onSaved`, and `onDeleted` props.
- **Photo Library:** The upload Type list already offers `Art`. Detail Type is editable, and its Artwork icon is conditional on the current selected `source_type === 'art'`, immediately below Type. Saving the row persists classification via the PHOTOS update API; the ArtworkDialog saves metadata separately via ARTWORK.
- **PALETTES:** Holds authoritative real-paint approximations and saved palettes. Never create a second color/palette database in ARTWORK.
- **GALLERY:** Owns mixed-gallery queries, `art-swatch` rendering (including frames), and artwork detail navigation. ARTWORK supplies artwork-only discovery records through its public list interface; it does not duplicate the Gallery component.

**Known interface gap:** `ArtworkService` directly instantiates the PHOTOS repository for existence checks. This works as written but ties ARTWORK to a lower-level PHOTOS implementation instead of a published PHOTOS service. Evaluate against PHOTOS' public service contracts before changing; do not silently replace it with new cross-department SQL.

## 8. Artwork metadata workflow

1. A photograph is uploaded or already exists in Photo Library; PHOTOS assigns its `photo_library_id`.
2. A user selects `Art` in Photo Library's Type dropdown. The Artwork icon appears under Type. The user's classification change is a **separate row-save operation** from ARTWORK metadata saving.
3. Clicking the icon opens `ArtworkDialog` with the photo ID and an image URL. The dialog issues `GET action=get` to load existing artwork metadata, or shows empty fields if none exists.
4. The user enters artist (natural name order), title, year/circa, medium, collection, source, and notes. The dialog converts blank year to `null` and `circa` to `0` or `1`.
5. Saving posts `action=create` if no record exists, otherwise `action=update`. The service verifies that PHOTOS contains the photograph; the repository writes only `photos_artwork`.
6. Opening the dialog later loads the saved row for editing. Deleting requires a second confirmation click and calls `action=delete`, leaving the photo/palette/crops untouched.

**Ownership limitation:** ARTWORK metadata editing does not own photo uploading or saved-palette editing. Its public list endpoint supplies artwork data to the shared Gallery renderer; publication/visibility rules belong in the public data access path. Metadata save remains distinct from the Photo Library row-save.

## 9. Security and deletion rules

- Every HTTP request must run through the existing authenticated admin API doorbell. `ArtworkEndpoint::handle` itself does not authenticate a user.
- The repository uses prepared statements and an allowlist of ten writable metadata fields. Primary keys, timestamps, and photo identity cannot be supplied as arbitrary editable fields.
- Create rejects duplicate photo associations. The DB unique key provides the final concurrency safeguard.
- User-provided text fields and URLs are metadata, not trusted markup or executable instructions. Escape them in any future public study pages. `source_url` is a reference field, **not** permission to fetch remote content or publish copyrighted photographs.
- Deleting metadata must never call PHOTOS file deletion or PALETTES deletion. Conversely, any future photo deletion workflow should check artwork usages and preserve referential integrity under PHOTOS' guarded lifecycle rules.
- A visible `Art` classification or Artwork button does not, by itself, establish public publication rights, Gallery visibility, or metadata completeness.

## 10. Changes, tests, and documentation maintenance

Before modifying ARTWORK:

1. Read root `SystemManifest.md`, this document, and relevant PHOTOS/PALETTES/GALLERY manifests.
2. Confirm whether the change is metadata (ARTWORK), original photo/type/crop (PHOTOS), color-chip palette (PALETTES), or discovery/rendering (GALLERY).
3. Inspect callers and real database schema; keep `api/v2/admin/artwork/index.php` a thin doorbell.
4. Preserve current API request/response contracts unless a change and migration are approved.
5. Validate PHP syntax and run live authenticated API tests: get missing metadata (`null`), create, reopen, edit, duplicate rejection, delete, and confirm photo/crop/palette survival. Validate source-type dropdown persistence separately. Test invalid IDs, unknown fields, unauthenticated requests, and all error status codes.
6. Update this manifest only when departmental responsibilities, published interfaces, data relationships, significant workflows, or dependencies change. Record the revision below.

### Change log

| Date | Change |
|---|---|
| 2026-11-08 | Initial ARTWORK DeptManifest drafted from supplied repository/backend/frontend artifacts and screenshot of `photos_artwork` schema. Notes unverified deployed integration and planned Art Study/Gallery features. |

---

## Template rule for other departments

Keep the **same numbered structure and evidence standard** as PHOTOS and ARTWORK. Inventory real files and method signatures; document ownership, interfaces, related tables, callers, workflows, security, tests, and a change log. Mark unknown or future capabilities explicitly; never claim a feature is implemented solely because it is planned in `SystemManifest.md`. Do not alter application code during a documentation-only assignment.

## Public ArtworkPage and Art Gallery — departmental interfaces

- **Contextual Art Study CTA:** `ArtworkService::publicStudyUrlForPhotos(array $photoIds): ?string` accepts explicit Photo Library IDs and returns `/inspired-by/{artwork_id}` only for one unambiguous, publicly available artwork, otherwise `null`. `PdoArtworkRepository::findArtworkCandidatesForPhotos(array $photoIds): array` performs one parameterized, read-only batch lookup through direct Art photos or design `inspiration_photo_id` links; missing metadata is retained as an unresolved candidate so conflicting links cannot be silently ignored. PHOTOS' `findPublicArtworkPhoto()` remains authoritative for destination visibility. No records, tokens, flags, or associations are created or changed.
- **PLAYLISTS consumer:** `PlaylistExperienceService` resolves `{artwork_id}` only in opted-in existing `navigate` CTA URL parameters, during both public and project playback-plan assembly. Context includes the current experience's selected source slides (including its cover), not unrelated playlists or title/tag guesses. Missing, ambiguous, or unavailable artwork omits that CTA. Other CTA parameters, order, analytics identity, source tracking, and remaining player placeholders are preserved. Configure one reusable Navigate CTA in `/admin/ctas`, then add it to the appropriate existing CTA Pages; Public REX's existing public/default page selection and other experiences' page assignments remain unchanged. This extends the existing CTA pipeline without a new action, API, or frontend resolver.
- **Verification:** `php api/tests/ArtworkCtaTest.php` uses in-memory SQLite fixtures for direct/inspired links, another artwork, ambiguity including missing metadata, unavailable public destinations, unchanged normal CTAs and tracking parameters, and read-only behavior. This contract is local until deployed; CTA records/page memberships are configured separately through existing Admin interfaces.
- `app/ARTWORK/Endpoints/ArtworkPageEndpoint.php`: public GET JSON endpoint invoked through the thin `api/v2/artwork/page.php` doorbell. Accepts `artwork_id` or `photo_library_id`; responds `{ok:true,data:{artwork,photo,paint_approximations,inspired_designs}}`. Invalid input returns 400, unavailable artwork 404, unexpected failures 503 without disclosing exception details.
- `app/ARTWORK/Services/ArtworkPageService.php`: read-only composition of ARTWORK metadata, PHOTOS public artwork/inspired-design retrieval, and PALETTES saved-palette retrieval. ARTWORK does not own image files, photo-to-artwork inspiration links, or paint-color records.
- **Reverse playlist navigation:** The existing public `ArtworkPageService::get()` response includes additive `playlists: [{playlist_id:int,title:string,rex_url:string}]`, empty when none qualifies. It reads the active Public Player Experience through `PdoPlayerExperienceRepository::getByExperienceKey('public')`. PLAYLISTS' `PdoPlaylistRepository::findPublicForArtworkPhoto(int $artworkPhotoId, string $slideFlag): array` returns candidates and all selected photo IDs for active/public/indexable/non-retired playlists with an active, experience-selected slide explicitly referencing this art photo or an inspired design. ARTWORK reuses `ArtworkService::publicStudyUrlForPhotos()` to exclude mixed/ambiguous artwork contexts. REX's existing `findActiveByResourceIds('playlist_experience','playlist', $ids)` supplies reservations, including normalized legacy context experience keys; only non-revoked Public reservations with nonblank tokens qualify, choosing the oldest active qualifying reservation. No tokens, aliases, links, flags or associations are created/modified. This is public marketing discovery, not a change to private/client/unlisted direct-link access. The current PLAYLISTS department has no manifest; this read capability is documented here as a dependency until its contract is established.
- **ArtworkPage consumer:** An optional Watch button above inspired-design photographs uses the returned stable `/t/{token}` URL through the existing Router and `withSourceParam()` helper. One playlist uses “Watch the Art-Inspired Palette Playlist”; multiple results show individual titles; empty results render no navigation. React escapes titles. The interface does not guess from titles/tags, create a new playlist API, select Client/Concept/Showcase links, or change player behavior.
- **Public artwork discovery:** `GET /api/v2/artwork/page.php?action=list` returns `{ok:true,items:[...]}` without admin authentication. `ArtworkPageEndpoint` dispatches to `ArtworkPageService::listGallery()`, which uses `PdoArtworkRepository::listPublicGallery()`. Rows are `art-swatch` items containing artwork/photo identifiers, titles, artist, safe local photo URL, and crop geometry for the existing Gallery renderer. Public query filters must prevent exposure of non-public photos.
- **Frontend consumer:** `/art-gallery` uses `src/pages/ArtGalleryPage/index.jsx`, which consumes the public list response and passes its rows to the existing Gallery component. The Gallery/ArtSwatch component owns gold-frame presentation, hover treatment, and links to `/inspired-by/:artworkId`. This is not a new Gallery query engine. The implementation was prepared locally; deployment and browser behavior are not established by this manifest.
- PHOTOS must enforce suitable public visibility and permission checks on artwork and inspired-design images. Public interfaces must not use the unrestricted administrative inspiration reverse lookup.

- **Frontend routes:** `src/Router.jsx` renders `/inspired-by/:artworkId`. The separately bootstrapped `src/routes/AdminRoutes.jsx` also renders the same ArtworkPage at `/admin/inspired-by/:artworkId` inside its existing authenticated `AdminPublicPage` wrapper. Both use the existing public artwork API and its visibility checks; the admin route is not a private-artwork preview API. Shared navigation/layout remains owned by the app shells.

- **Artwork display image delivery:** `ArtworkPage.jsx` uses the existing `photoThumbUrl()` / `/api/v2/image-thumb.php` path for 960px hero and 480px design images. PHOTOS now renders an explicit saved swatch crop when present; absent a saved override, it preserves original aspect ratio. Preview images contain the complete result instead of applying a second CSS center crop; designs retain lazy loading. A failed thumbnail falls back to the original URL. API `photo.image_url` / design `image_url` values and lightbox enlargement remain original-file URLs. No new image endpoint, crop or asset store is introduced. Named production-media crops remain independent.
- **Palette read dependency:** The existing PALETTES repository now enriches saved-palette members from `mv_swatch_view` with `is_inactive=0` in the LEFT JOIN. Membership/order/roles/notes remain PALETTES-owned; missing/inactive display rows do not remove memberships. Snapshot freshness depends on the existing explicit COLORS refresh workflow. This optimization is local; the measured live SQL comparison is not a claim that the Artwork API has been deployed.

## Completed Improvements

- **2026-11-10 — Artwork-to-playlist navigation (local):** Existing artwork detail composition now exposes eligible, unambiguous Public REX links via PLAYLISTS/REX-owned readers. `api/tests/ArtworkPlaylistTest.php` covers direct/inspired relationships, publication gates, inactive/revoked/private/missing/duplicate reservations, legacy experience context, ambiguity, experience-specific slide selection, empty results and read-only behavior; the artwork route browser fixture checks single/multiple/absent controls, escaped titles and source attribution at desktop/mobile widths. No production data change or deployment.
- **2026-11-10 — Contextual Art Study destination contract (local):** ARTWORK supplies one read-only public destination from explicit slide-photo/inspiration relationships; PLAYLISTS reuses it in the existing Navigate CTA preparation. Verified by 24 SQLite fixture checks, PHP syntax validation, existing artwork composition/visibility tests, REX failure-response tests, and the client viewer-target regression. No production CTA configuration or deployment was performed.

### Art Study navigation interface

`ArtworkService::studyUrl(int $artworkId): string` returns the existing `/inspired-by/{artwork_id}` route, rejecting nonpositive IDs. PALETTES resolves metadata with `findByPhotoId()` after PHOTOS validates the public inspiration pair, then uses this route for its Art Inspiration inset. ARTWORK does not create PV records, palettes or REX tokens.
