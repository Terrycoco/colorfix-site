# ARTWORK — Department Manifest

**Department:** `ARTWORK`  
**Location:** `app/ARTWORK/`  
**Namespace:** `App\ARTWORK`  
**Status:** Initial implementation inventory, 2026-11-08  
**Authority:** Repository-root `SystemManifest.md`; follows `app/PHOTOS/DeptManifest.md` section format.  
**Evidence:** ARTWORK repository and backend ZIPs, Photo Library + ArtworkDialog integration ZIP, and the user-supplied phpMyAdmin table-structure screenshot. This describes **supplied code**, not an independent audit of deployed production files.

## 1. Mission and ownership

ARTWORK owns **metadata and interpretation of works of art represented by photographs in the Photo Library**. It records the artist, titles, approximate date, medium, museum/collection, source URL, historical notes, and color analysis. It provides administration operations to create, retrieve, revise, list, and remove those metadata records.

ARTWORK does **not** own the image file, Photo Library row, source-photo crop, photo classification, saved paint palette, or Gallery results. Those remain with their respective departments: PHOTOS, PALETTES, and GALLERY. Do not add duplicate upload, image-cropping, palette-editing, or Gallery-search systems to ARTWORK.

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
| `src/components/ArtworkDialog/ArtworkDialog.jsx` *(frontend)* | Reusable modal for adding, viewing, editing, and deleting artwork metadata; displays caller-supplied photo URL. |
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
- Gallery eligibility and item composition (**GALLERY**): planned `art-swatch` presentation is a Gallery concern, not implemented by this ARTWORK backend.

Artwork records are **not** a replacement for `photo_library.source_type`. The `source_type` dropdown writes classification through the Photo Library's existing update workflow. The `photos_artwork` record stores the painting's metadata.

## 7. Dependencies and boundaries

- **Backend:** PHP PDO; `App\PHOTOS\Repos\PdoPhotoLibraryRepository` for photo existence; shared autoload/DB/admin auth bootstrap in the public doorbell.
- **Frontend:** React, `createPortal`, shared `API_FOLDER` config, browser `fetch`; reusable `ArtworkDialog` takes `open`, `photoLibraryId`, `imageUrl`, `onClose`, `onSaved`, and `onDeleted` props.
- **Photo Library:** The upload Type list already offers `Art`. Detail Type is editable, and its Artwork icon is conditional on the current selected `source_type === 'art'`, immediately below Type. Saving the row persists classification via the PHOTOS update API; the ArtworkDialog saves metadata separately via ARTWORK.
- **PALETTES:** Holds authoritative real-paint approximations and saved palettes. Never create a second color/palette database in ARTWORK.
- **GALLERY:** Owns whether a photo appears as a `picture-swatch` or `art-swatch`, plus search and display. An art study viewer and framed swatches are **planned**, not established by these files.

**Known interface gap:** `ArtworkService` directly instantiates the PHOTOS repository for existence checks. This works as written but ties ARTWORK to a lower-level PHOTOS implementation instead of a published PHOTOS service. Evaluate against PHOTOS' public service contracts before changing; do not silently replace it with new cross-department SQL.

## 8. Artwork metadata workflow

1. A photograph is uploaded or already exists in Photo Library; PHOTOS assigns its `photo_library_id`.
2. A user selects `Art` in Photo Library's Type dropdown. The Artwork icon appears under Type. The user's classification change is a **separate row-save operation** from ARTWORK metadata saving.
3. Clicking the icon opens `ArtworkDialog` with the photo ID and an image URL. The dialog issues `GET action=get` to load existing artwork metadata, or shows empty fields if none exists.
4. The user enters artist (natural name order), title, year/circa, medium, collection, source, and notes. The dialog converts blank year to `null` and `circa` to `0` or `1`.
5. Saving posts `action=create` if no record exists, otherwise `action=update`. The service verifies that PHOTOS contains the photograph; the repository writes only `photos_artwork`.
6. Opening the dialog later loads the saved row for editing. Deleting requires a second confirmation click and calls `action=delete`, leaving the photo/palette/crops untouched.

**Current limitations:** The UI shows an image passed in by its caller, but does not own a separate image picker or upload. There is no art study publication workflow, `art-swatch` rendering, artwork-specific search, or linked palette editing in these supplied ARTWORK files. Metadata save is distinct from the Photo Library row-save; do not assume that selecting Art has already persisted before opening the dialog.

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
6. Update this manifest in the same change whenever files, interfaces, ownership, schema, workflow, or dependencies change. Record the revision below.

### Change log

| Date | Change |
|---|---|
| 2026-11-08 | Initial ARTWORK DeptManifest drafted from supplied repository/backend/frontend artifacts and screenshot of `photos_artwork` schema. Notes unverified deployed integration and planned Art Study/Gallery features. |

---

## Template rule for other departments

Keep the **same numbered structure and evidence standard** as PHOTOS and ARTWORK. Inventory real files and method signatures; document ownership, interfaces, related tables, callers, workflows, security, tests, and a change log. Mark unknown or future capabilities explicitly; never claim a feature is implemented solely because it is planned in `SystemManifest.md`. Do not alter application code during a documentation-only assignment.
