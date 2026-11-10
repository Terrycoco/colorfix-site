# PHOTOS — Department Manifest

**Department:** `PHOTOS`  
**Location:** `app/PHOTOS/`  
**Namespace:** `App\PHOTOS`  
**UPDATED:** 2026-11-10 12:31:46 PM PST
**Status:** Source inventory with PHOTOS inspiration-link service added  
**Source reviewed:** `PHOTOS(4).zip` (the department's PHP files only). API doorbells, React pages, migrations, and the shared `SystemManifest.md` were **not** in this archive; their paths and behavior require separate verification.

## 1. Mission and ownership

PHOTOS owns Photo Library records and source-photo lifecycle: uploads, Photo Library metadata and palette links, usage/dependency checks, duplicate discovery and safe merging, replacement, guarded deletion and managed-file disposal. It also owns **non-destructive crop definitions** for individual photos.

Other departments may *reference* Photo Library IDs, resolve photos for display, and own their own business records, but must not independently delete Photo Library source files, bypass usage checks, or create a second canonical crop store.

## 2. Core invariants

1. **Canonical identity:** `photo_library.photo_library_id` is the PHOTOS identity. Retired per-feature photo-row IDs are not interchangeable with it.
2. **Photo-to-palette association:** the current direct association is `photo_library.palette_id`. The Photo Library association service enforces one Main photograph per saved palette when attaching; BEFORE/ZOOM associations belong to the Palette Viewer editor, not legacy Palette Sets.
**Artwork inspiration:** A design photo may reference one artwork through nullable `photo_library.inspiration_photo_id`; many designs may reference the same artwork. PHOTOS exclusively writes the field. The target must exist with `source_type='art'`; an art photo cannot be used as an inspired design. Reassignment replaces the previous link; removal clears only this field, never the palette association. Artwork-side and design-side editors use the same service.

**Art classifications:** `art` identifies the original artwork; `art_inspired` identifies inspired rooms/designs. Both are available in Photo Library upload, editable type, and source filtering. Upload validation and series discovery accept `art_inspired`; its files use the existing `/photos/progressions/{series}/` storage branch. The classification alone does not set `inspiration_photo_id`, assign/copy a palette, create artwork metadata, or change Gallery eligibility. Existing direct `palette_id` and inspiration links remain independent and use their existing operations. The live `source_type` column is `varchar(40)`; no schema change is required.

3. **Originals remain originals:** crop operations store geometry in `photo_crops`; no image bytes are rewritten.
4. **Crop keys:** `(photo_library_id, crop_type)` identifies one optional manual crop. **No row means a computed centered crop** using the named preset. Reset deletes that crop row.
5. **Crop geometry:** `crop_x`, `crop_y`, `crop_width`, and `crop_height` are normalized fractions of the **original image** dimensions, not thumbnails. Geometry must lie within the original image and satisfy the preset aspect ratio in pixel space.
6. **Crop formats are centrally defined:** do not hardcode or independently define aspect ratios in a caller. The PHP preset registry is `Config/PhotoCropPresets.php`; consuming UI retrieves presets through the crop endpoint.
7. **Safe deletion:** do not delete photos that still have usages. Database deletion commits before source-file disposal; shared/unsafe/unmanaged files are retained. Palette unlinking retains Photo Library assets.
8. **Safe merge:** duplicate merges check conflicts, client access contexts, and permission statuses; the default `apply=false` is a dry run. Merging rewires references rather than blindly deleting photo files.
9. **API boundary:** API files are thin authenticated doorbells; business logic lives in PHOTOS endpoints/services/repos. Do not introduce parallel implementations in API files or other departments.
10. **Shared transactions:** repository methods use injected PDO and do not unilaterally commit. Transaction-owning services coordinate cross-record changes.

## 3. Directory and file inventory

| Location | Responsibility |
|---|---|
| `Config/PhotoCropPresets.php` | Canonical crop types, labels, aspect ratios, and default. |
| `Entities/PhotoEntity.php` | Typed representation and `toArray()` for uploaded extra photographs. |
| `Endpoints/PhotoInspirationEndpoint.php` | Admin list/search/set/remove request handling; authentication belongs to the thin API doorbell. |
| `Endpoints/PhotoCropEndpoint.php` | HTTP adapter: crop preset lookup, get, save, reset. |
| `Endpoints/PhotoLibraryListEndpoint.php` | Admin HTTP list/search adapter delegating to the canonical PHOTOS search service. |
| `Endpoints/PhotoLibraryUploadEndpoint.php` | Multipart Photo Library upload HTTP adapter. |
| `Endpoints/PhotoLifecycleEndpoint.php` | Delete, delete-inactive, merge, duplicate-candidates HTTP adapter. |
| `Endpoints/PhotoPaletteEndpoint.php` | Compatibility HTTP adapter for direct palette links, attach, detach. |
| `Endpoints/UploadEndpoint.php` | Tagged `extra_photo` upload HTTP adapter. |
| `Repos/PdoPhotoCropRepository.php` | Persisted custom crop lookup, list, upsert, deletion. |
| `Repos/PdoPhotoLibraryRepository.php` | Photo metadata, palette and inspiration links, usage inventory, row lookup/mutation, reference reassignment. |
| `Repos/PdoPhotoLifecycleRepository.php` | Supplemental dependency checks, reference reassignment, merge conflicts, file references. |
| `Services/PhotoCropService.php` | Crop validation, default calculation, save, reset. |
| `Services/PhotoDuplicateService.php` | Duplicate candidate reporting and data deduplication helpers. |
| `Services/PhotoLibraryListService.php` | Canonical Photo Library search/filter/pagination and response projection, including title/tags/path/client/ID queries; reused by artwork picker. |
| `Services/PhotoLibraryUploadService.php` | Current Photo Library multipart upload and metadata/asset integration. |
| `Services/PhotoLibraryUsageService.php` | Usage summary facade. |
| `Services/PhotoInspirationService.php` | Validates and writes/clears design-to-artwork links; reverse lookup for ARTWORK/admin consumers. |
| `Services/PhotoLifecycleService.php` | Guarded delete, inactive cleanup, merge, safe post-commit file disposal. |
| `Services/PhotoPaletteAssociationService.php` | Transactional attachment of Main photo to saved palette. |
| `Services/PhotoReplacementService.php` | File replacement, Photo Library row/asset updates, playlist reference adjustments, cache cleanup; safe local-path resolution. |
| `Services/PhotoUploadService.php` | Single-photo `extra_photo` upload with required tags. |

Do not resurrect retired masks, base/variant rendering, Palette Sets, or retired `saved_palette_photos` / `saved_palette_set_photos` relationships as new sources of truth.

## 4. Supported crop presets

| Key | Display label | Aspect ratio |
|---|---|---|
| `swatch` | Gallery Swatch | 5:4 |
| `yt_landscape` | YouTube Landscape | 16:9 |
| `yt_portrait` | YouTube Portrait | 9:16 |
| `square` | Square | 1:1 |
| `pinterest` | Pinterest | 2:3 |

If type is omitted, the service defaults to `yt_landscape`. Callers needing a Gallery swatch **must pass `swatch` explicitly**. A format is a fixed aspect ratio; the user may still drag and resize the selected rectangle freely *while preserving that ratio*. No arbitrary user-defined ratio table is part of the current design.

## 5. Public PHP entry points

### HTTP endpoint classes

All endpoint classes expect an existing PDO supplied by an authenticated API doorbell. **An endpoint class is not itself an authorization check.** Verify exact URL mappings against the actual `api/v2/` files before editing them.

| Class / method | Inputs and actions |
|---|---|
| `PhotoCropEndpoint::handle(PDO $pdo, string $documentRoot): void` | GET `action=presets`; GET `action=get&photo_library_id=&crop_type=`; POST JSON `action=save` plus normalized rectangle; POST JSON `action=reset`. Uses server-verified image dimensions. |
| `PhotoLibraryUploadEndpoint::handle(PDO $pdo): void` | POST multipart fields, delegates to `PhotoLibraryUploadService::upload`. |
| `PhotoLifecycleEndpoint::handle(PDO $pdo, string $operation): void` | `delete`, `delete-inactive`, `merge`, `duplicate-candidates`; only duplicate candidates uses GET. |
| `PhotoPaletteEndpoint::handle(PDO $pdo, string $action): void` | `links` (GET), `attach` and `detach` (POST). |
| `UploadEndpoint::handle(PDO $pdo): void` | POST multipart `photo`, mandatory `tags`; creates `extra_photo`. |

### Service-level interfaces for internal callers

- `PhotoCropService`: `presets()`, `preset(string)`, `getCrop(photoId, originalWidth, originalHeight, ?type)`, `centerCrop(originalWidth, originalHeight, ?type)`, `saveCrop(photoId, type, crop, originalWidth, originalHeight)`, `resetCrop(photoId, type)`.
- `PhotoLibraryUsageService`: `getUsageSummary(photoId)`.
- `PhotoInspirationService(PDO)`: `setInspiration(photoId, artworkPhotoId): array`, `removeInspiration(photoId): array`, `getInspiredDesigns(artworkPhotoId): array`. Validates source type and identity, writes under the caller transaction when present. Reverse lookup is **admin-level**; public artwork pages must apply visibility/permission checks before returning linked photos.
- `PhotoLifecycleService`: `delete(photoId)`, `deleteInactive()`, `merge(fromId, toId, apply=false, retire=true)`, `assertNotLibraryFile(path)`, `disposeFile(relPath)`, `cleanupDetachedPalettePhotos(rows)`.
- `PhotoPaletteAssociationService`: `attach(photoId, paletteId, replace=false, ?gallery=null)`.
- `PhotoReplacementService`: `replace(photoId, sourcePath, metadata=[])`, `localPath(relPath)`.
- `PhotoLibraryUploadService`: `upload(post, uploads, documentRoot)`.
- `PhotoUploadService`: `fromPdo(PDO)`, `upload(file, metadata=[])`.
- `PhotoDuplicateService`: `candidates()`, `dedupeSavedPaletteRows(apply=false)`, `dedupeAllRelPathRows(apply=false)`.

Internal repositories provide lower-level persistence. Other departments should prefer a relevant service method over directly manipulating PHOTOS-owned records. If a missing capability is required, add it under PHOTOS and document the new interface here.

## 6. Persistence and data relationships

**PHOTOS-owned principal tables visible in code**

- `photo_library`: canonical photo identity, metadata, source type/path, palette association, client/asset references, state, permission and gallery flags; `inspiration_photo_id` (nullable, indexed self-reference to an art photo ID, database field added by owner on 2026-11-09).
- `photo_crops`: optional per-photo/per-preset geometry. Unique `(photo_library_id, crop_type)`; its row is deleted when resetting a crop. The existing schema stores `crop_x`, `crop_y`, `crop_width`, `crop_height` as decimals. The fixed preset ratios do **not** require `aspect_width` or `aspect_height` columns.

**Related tables referenced but not owned by PHOTOS** include `asset_library`, `saved_palettes`, `clients`, `playlist_items`, `playlists`, `playlist_sets`, `project_photos`, `projects`, `palette_viewer_photos`, `palette_viewers`, `project_palettes`, `playlist_instance_set_items`, `playlist_instance_sets`, `playlist_instances`, `articles`, `article_sections`, `photo_group_items`, and `photo_groups`. PHOTOS may read these to identify usages and may perform carefully coordinated reassignment/cleanup operations; the owner department remains responsible for its own business semantics.

Some compatibility/retired table names appear in lifecycle introspection, including `saved_palette_photos` and `saved_palette_set_photos`. Their appearance in guard code does **not** authorize reinstating those models.

**Schema verification:** This archive contains PHP, not authoritative CREATE TABLE statements; column definitions other than the crop schema already established in this project should be validated against migrations/production schema before schema work.

## 7. Dependencies and boundaries

- PHP runtime: PDO, filesystem/image inspection (`getimagesize`), standard HTTP request globals.
- Existing shared infrastructure used by PHOTOS: `App\Repos\PdoAssetLibraryRepository`, `App\Repos\PdoClientRepository`, `App\Repos\PdoSavedPaletteRepository`, `App\Services\AssetLibraryService`, `App\Services\ClientService`, `App\Services\PhotoAltTextQueueService`, `App\Services\PhotoLibraryService`. These are external dependencies present in imports or instantiation; their internal contracts were not audited here.
- Main clients: Photo Library Admin, Palette Viewer / PALETTES, playlists and publishing consumers, Gallery, and future ARTWORK. Client-side components are **consumers**, not a second crop backend.
- Reusable crop UI: `PhotoCropDialog` is a standalone frontend component, integrated into the Photo Library form; the component files were **not** in the supplied PHOTOS archive. Its wiring and exact frontend import paths must be confirmed in frontend source, not inferred from this PHP folder.

## 8. Photo crop workflow

1. A caller selects an existing `photo_library_id` and a supported preset.
2. The crop endpoint retrieves that Photo Library record and resolves the local original safely via `PhotoReplacementService::localPath`.
3. The server reads real image dimensions. `PhotoCropService::getCrop` returns either the saved rectangle (`is_custom=true`) or the computed center default (`is_custom=false`).
4. The reusable UI renders the original picture and a movable, corner-resizable ratio-locked frame. It sends normalized coordinates for a save.
5. `PhotoCropService::saveCrop` validates bounds and pixel-space aspect ratio, then the repository upserts one crop row.
6. Reset calls `resetCrop`, which removes the row. The next get returns the computed center default, with no default row written.

**Important limitations visible in code:** the current crop endpoint resolves only **local readable original files**. It returns a conflict for an original that cannot be resolved/read. It does not generate or serve cropped derivative images itself. Gallery/PUB consumers must explicitly apply the crop geometry when rendering; this PHP archive does not prove those downstream consumers are wired.

## 9. Security and deletion rules

- Admin API doorbells must perform authorization and use the established bootstrap. `PhotoCropEndpoint` states this explicitly; no PHP endpoint should be exposed publicly merely because it exists under `app/PHOTOS/Endpoints/`.
- Do not accept browser-supplied original image dimensions for crop validation.
- Keep directory traversal and untrusted file paths away from filesystem operations. Existing `localPath` uses `realpath` and a document-root containment check; disposal is restricted further to managed `/photos/` paths.
- Never unlink original files before database commit. If a reference is present, or safety cannot be established, retain the file.
- Never bypass `PhotoLifecycleService` to perform Photo Library deletion, and never silently treat palette unlinking as photo deletion.
- Do not cross client or permission contexts during duplicate merges without explicit resolution.

## 10. Changes, tests, and documentation maintenance

Before modifying this department:

1. Read root `SystemManifest.md` and this `DeptManifest.md`. Confirm relevant neighboring department manifests before changing shared interfaces.
2. Identify the owner: configuration, Endpoint, Service, Repo, Entity, or frontend consumer. Keep API doorbells thin.
3. Inspect callers and affected tables. Do not invent method signatures, auth bootstraps, file paths, or schemas.
4. Keep backward compatibility unless an intentional migration is approved. Retired capabilities must remain retired.
5. Test PHP syntax; endpoint auth and error cases; crop defaults/save/reopen/reset for all five formats; normalized bounds and source-pixel ratios; upload and palette links; dependency-protected delete, merges, and file sharing.
6. Update this file when department ownership, published interfaces, significant workflows, dependencies, or database relationships change; do not log ordinary UI changes. Record the change under the log below.

### Inspiration editor interface

`api/v2/admin/photo-library/list.php` is now an authenticated thin doorbell to `PhotoLibraryListEndpoint`; `PhotoLibraryListService::list(array $queryParams, array $options = [])` preserves existing `q`, source/client/palette/status/id filters, sort/pagination, tags and response fields. The ARTWORK picker reuses this search via PHOTOS with admin-only exclude-art/active-only options.

`api/v2/admin/photos/inspiration.php` is an authenticated thin doorbell delegating to `PhotoInspirationEndpoint::handle(PDO)`. GET `action=list&artwork_photo_id` returns `{ok:true,items:[...]}`; GET `action=search&q` reuses canonical Photo Library tag/title/path/client/ID search, constrained to non-art active photos for admin assignment, returning `{ok:true,items:[...]}` (at least two characters, limited to 40 results); POST JSON `action=set` requires `photo_id` and `artwork_photo_id`; POST `action=remove` requires `photo_id`. Writes call `PhotoInspirationService`; the API never writes `photo_library` itself. Search and list are admin-only; do not expose them publicly without permission checks. ARTWORK's editor is a consumer, not an owner of this field.

### Canonical Photo Library search contract

Photo selection consumers must reuse the PHOTOS-owned `PhotoLibraryListService` and authenticated `photo-library/list.php` search (`q` supports tags, titles, photo IDs, paths, and client data), rather than implementing competing SQL/search semantics. Consumer-specific eligibility rules remain enforced by PHOTOS. Reuse shared frontend photo-selection components where appropriate; frontend control styling and interaction details are not department interfaces.


### Future improvements and integration work

- Wire Photo Library’s **Inspired By** selector through the PHOTOS-owned service. ARTWORK can consume the PHOTOS admin inspiration interface; test integration against the deployed database.
- Public artwork pages must filter inspired designs by approved Gallery/public visibility and permission policy. The service's reverse lookup deliberately returns all linked records for administrative use, including unpublished designs.
- Check artwork deletion, merge and duplicate-resolution paths for incoming `inspiration_photo_id` references. Decide and test whether to block artwork deletion with dependents or safely clear/reassign links. Do not assume a database foreign-key constraint exists merely because the column and index were added.
- Validate live schema type and index/FK definitions independently; SQL changes were performed by the owner outside this ZIP.

### Completed improvements

- **2026-11-10 (local):** Added `art_inspired` classification to the existing upload/type/filter and series workflow. Real multipart upload, bytes, retrieval, filtering and reclassification passed isolated SQLite/HTTP tests; 1280px and 390px browser checks passed. Original Art, existing photo types, palette save/copy/link flows and 24 contextual artwork CTA checks remain passing. No production data, schema, or deployment change.

- 2026-11-09: Added authenticated inspiration admin doorbell/PHOTOS Endpoint and admin candidate search.
- 2026-11-09: Moved Photo Library list/search from the administrative PHP doorbell into PHOTOS Endpoint/Service, preserving the route and response. Reused canonical title/tag/ID search in inspiration picker; no separate picker SQL. Syntax checked; no live integration tests.

- 2026-11-09: Added a single PHOTOS-owned inspiration-link service and repository methods. PHP syntax-checked; no database integration tests or deployment performed in this package.

### Change log

| Date | Change |
|---|---|
| 2026-11-08 | Initial PHOTOS DeptManifest authored from `PHOTOS(2).zip`, documenting current PHP implementation and explicitly marking missing API/frontend/schema evidence. |
| 2026-11-09 | Added PHOTOS inspiration-link service, repository operations, and noted integration/deletion follow-ups. |
| 2026-11-09 | Added PHOTOS inspiration admin endpoint consumed by ARTWORK. |
| 2026-11-09 | Extracted the canonical Photo Library search from `list.php` into PHOTOS Endpoint/Service, and reused it for the inspiration picker including tags. |


---

## Template rule for all other departments

The bot should create `app/<DEPARTMENT>/DeptManifest.md` using **the same numbered sections and evidence standard**, adapting details to that department. Document the **real** source files, real methods, actual ownership and boundaries, real related tables, API contracts, invariants, workflows, security, testing, and change log. Do not copy PHOTOS-specific facts, guess absent files, invent architectural conventions, or claim unverified integrations are complete. Keep each manifest concise enough to maintain, but specific enough for a new developer or bot to make changes without recreating functionality or violating ownership.


### Public ArtworkPage read capabilities (2026-11-09)

`PdoPhotoLibraryRepository::findPublicArtworkPhoto(int $photoId): ?array` returns an art photo only when currently Gallery-listed, active, and non-retired. `listPublicInspiredDesigns(int $artworkPhotoId): array` returns linked non-art photos meeting the same gates and **excludes client-associated photos** pending a reviewed public permission policy. Both are read-only; they do not change the existing admin-level `listInspiredDesigns` or inspiration writer. ARTWORK's public page consumes these PHOTOS-owned filters; never expose admin reverse lookup directly. These are conservative privacy filters, not blanket rules for the entire Photo Library or Gallery. Test Gallery and client permissions before relaxing them. No new schema change in this update.
