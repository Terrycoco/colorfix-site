UPDATED: 2026-11-21 12:05:44 PST

# PLAYLISTS — Department Manifest

## Ownership and scope

`app/PLAYLISTS`, namespace `App\PLAYLISTS`, owns playlist records, authored slides, playlist sets/membership, player-experience configuration and slide presets, and constructs playback/landing representations. This initial contract documents the verified metadata interfaces and existing collaborators; it is not a complete audit of every legacy playlist path.

Core records include `playlists` and `playlist_items`. The existing `playlists.editorial_type` column is playlist-owned metadata, distinct from the existing `type` field and playback experience keys. Its schema was added separately by the owner; this change neither creates nor alters it. Owner-supplied definition: nullable ENUM('makeover','spotlight','teaching','themed_makeover','palette_ideas','palette_exploration','mistakes_fixes','step_by_step_makeover'), DEFAULT NULL, positioned after type. No SQL is executed by this update.

PLAYLISTS does not own Photo Library images/crops, saved palettes, article content, REX reservations/aliases, project records, SYSTEM authentication, ANA events, or PUB production workflows.

## Metadata interfaces

`Repos/PdoPlaylistRepository.php`, constructed with `PDO`:

- `findArticleSources(int $articleId): array`: read-only lookup of every existing `playlists.article_id` association, returning `playlist_id` and `editorial_type`. ARTICLES uses this to identify artwork context and reject ambiguous implicit slideshow destinations; no new relationship or ownership of article content is introduced.
- `getAdminRowById(int $playlistId): ?array`: playlist metadata with hero photo enrichment; returns `editorial_type` as stored, including null. Missing record returns null.
- `listAdminRows(): array`: non-retired admin playlists, now including `editorial_type`.
- `listAdminRowsByProjectId(int $projectId): array`: non-retired playlists for a project, now including `editorial_type`; invalid project IDs return an empty array.
- `saveAdminRow(int $playlistId, array $row): int`: positive ID updates, otherwise inserts. Existing fields/contracts remain intact. When `editorial_type` is present, binds it in UPDATE/INSERT. When absent, UPDATE preserves the stored value and INSERT omits the column so the database supplies its default. Explicit null is passed through, subject to the database's nullability constraint. Database failures propagate; no schema probing or enum invention is performed.
- Other existing interfaces include `getById(string,string): ?Playlist`, active publishing/public slide readers, admin item reads/writes, landing reads, slug lookup/generation, project/public-state updates and deletion. Playback entities and public landing representations are not extended with editorial metadata by this change.
- `getCoverTitle(int): string` supplies the first nonblank active cover-image title, independent of playback flags, for existing share metadata consumers.

`Managers/PlaylistManager.php`, constructed with `PDO`, exposes `listAdminPlaylists()`, `listAdminPlaylistsByProjectId(int)`, `getAdminPlaylist(int)`, `saveAdminPlaylist(array)`, `saveAdminItems(...)`, `deletePlaylist(...)`, and `setPublic(...)`.

`saveAdminPlaylist(array $payload): array` retains existing title/type validation, project association, slug, publication and hero handling. Optional `editorial_type` accepts string or null; other types throw `InvalidArgumentException`. Values are passed unchanged (no trimming, default or domain enum is imposed). Omission remains omission. Result stays `{playlist_id,project_id,slug}`; the HTTP save response stays `{ok,playlist_id,slug}`. Fetch detail/list to read editorial metadata.

## API boundaries

Existing SYSTEM-authenticated doorbells delegate to departmental Endpoints:

- `/api/v2/admin/playlists/list.php` → `ListEndpoint`, metadata list.
- `/api/v2/admin/playlists/get.php` → `GetEndpoint`, playlist and items.
- `/api/v2/admin/playlists/save.php` → `SaveEndpoint`, POST metadata; invalid input 400, unhandled save failure 500. Existing request/response shapes are unchanged except the optional field and additional read field.
- `/api/v2/admin/playlists/delete.php` → `DeleteEndpoint`.
- `/api/v2/admin/playlists/set-public.php` → `SetPublicEndpoint`.
- `/api/v2/admin/playlist-items/save.php` → `SaveItemsEndpoint`.

The department also contains the existing playlist-set/item, player-experience and slide-preset endpoints. `/api/v2/playlist-sets/get.php` and the compatibility `/api/v2/playlist-instance-sets/get.php` both delegate to `PlaylistSetGetEndpoint`. No URLs or authentication behavior changed here.

## Collaborators and existing behavior

- SYSTEM authenticates admin requests before the endpoint; the repository is not an authorization layer.
- PHOTOS owns image identity, delivery and lifecycle. Existing playlist photo synchronization and hero enrichment remain unchanged.
- PALETTES owns saved palette data; PLAYLISTS references it when composing playback.
- REX owns reservation identity and relationships; playlist-item save coordinates through existing `RexPlaylistExperienceSyncService`. Editorial metadata does not change REX/alias behavior.
- PROJECTS uses project-scoped playlist lists. Existing omitted-project handling remains intact.
- ARTICLES supplies article CTA content through its repository; PUB and other consumers use existing publishing slide readers. No editorial analyzer/creator/dispatch behavior is introduced.
- `Services/PlaylistExperienceService.php`, `PlaylistLandingService.php`, `PlaylistSetService.php`, `SlidePresetService.php` and `PlaylistPhotoLibrarySyncService.php` retain existing responsibilities and rules.

## Compatibility, verification and future work

All databases serving the changed admin readers must already have `playlists.editorial_type`. Older clients may omit the field without erasing it. The playlist record editor exposes these eight values with human-readable labels and a Not set option that saves null. Playback behavior is unchanged. The owner supplied the column definition; the live database was not inspected. Invalid enum values remain database errors.

`scripts/test-playlist-editorial-type.php` exercises the real manager/repository against an isolated SQLite fixture: insert/default, supplied insert, update, omitted update, explicit null, detail/list/project-list reads and invalid input type. It does not alter the application database. PHP syntax checks cover changed PHP files. Production MySQL constraints and authenticated HTTP behavior require environment verification.

Future work (not authorized by this change): record the deployed column definition when available and complete the department-wide legacy caller/table inventory before broader cleanup. Do not introduce alternate metadata stores, recreate retired playlist-instance/palette-set systems, or infer editorial workflow rules from this field.

## Completed changes

2026-11-18: added existing-column `editorial_type` support to admin metadata reads and optional repository/manager writes; added omission/default regression coverage. No schema/data migration, frontend change or deployment.

2026-11-18: added the Editorial Type select to the playlist record editor and its existing save payload; documented the owner-supplied enum definition.

## Article CTA publication eligibility (2026-11-21)

`PlaylistExperienceService::hydrateArticleCtas()` delegates to ARTICLES `ArticleLinkService::hydrate()`. Unpublished article destinations are removed before public title/dek/URL hydration. Valid published overrides and other CTA actions remain unchanged. ARTICLES owns publication policy; no playlist or CTA definitions are modified.
