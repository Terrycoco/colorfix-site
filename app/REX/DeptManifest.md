UPDATED: 2026-11-21 12:05:44 PST

# REX — Department Manifest

**Department:** `REX`

**Location:** `app/REX/`

**Namespace:** `App\REX`

**Status:** Current implementation inventory; architectural exceptions recorded, not resolved.

**Source reviewed:** Repository PHP, API doorbells, rewrites, frontend routes/callers, related departmental consumers, archived schema, and existing tests. Governing documents: root `SystemManifest.md` and `app/PHOTOS/DeptManifest.md`. This is a source audit, not certification of production deployment or live database constraints.

## 1. Mission and ownership

REX owns stable shareable reservation identities and resolution to an intended destination. Its current implementation generates opaque tokens; registers, finds, updates, revokes, and reactivates reservations; manages aliases, relationship edges, fallbacks, and publication locks; and adapts destination data into render/redirect results.

The department also currently coordinates Playlist/Viewer/Thumbs REX graphs, public `/t/{token}` HTML metadata and bootstrap data, playlist indexing eligibility, and dynamic sitemap composition. These are implemented responsibilities; documenting them does not settle all ownership questions in section 7.

**Does not own:** playlist slides, playback rules, Player Experience configuration, saved palettes or Palette Viewer content, projects/documents/approvals, photographs/crops/files, PUB production or dispatch, SAS sending/recipients, ANA event storage, or SYSTEM authentication. Other departments supply content and domain rules; REX supplies identities and relationship/resolution mechanics. `resource_type` is a destination discriminator, not PHOTOS' `source_type`, PUB's source selection, or the `src` attribution parameter.

## 2. Core invariants

1. **Separate identities:** `rex_reservations.id` identifies the reservation; `token` forms `/t/{token}`; `resource_type` plus `resource_id` identifies its destination. A Palette Viewer REX points to `palette_viewers.palette_viewer_id`, not a saved palette ID unless its resource type explicitly is `saved_palette`.
2. **Stable tokens:** metadata/destination updates, revoke/reactivate, and normal graph synchronization retain the token. Resource destruction can remove reservations through the deletion operations described below; stability is not an unconditional promise that deleted content remains available.
3. **Experience identity:** first-class `experience_key` is preferred. Compatibility reads still accept `context.experience_key`; legacy Viewer matching may use `context.format`, and legacy Public Thumbs may have no experience key. Do not silently remove these fallbacks or store a different experience under an existing token.
4. **No blanket uniqueness assumption:** token generation checks token uniqueness; `reserve()` does not enforce one reservation per destination/experience. Reuse and ambiguity handling differ by caller. Canonical playlist sync serializes its own creation path, not every possible caller.
5. **Resolution is not publication:** indexing/listing flags are separate from direct-link access. Do not convert a noindex rule into an authorization rule, or change destination flags merely to prepare a REX.
6. **Result contracts:** `render` requires nonempty destination data; `redirect` requires a nonblank `destination.url` or `destination.path`. Each resolver produces its own destination shape. Descriptions are administrative summaries, not proof that playback/access succeeds.
7. **Relationship versus content ownership:** REX owns reservation edges (`viewer`, `thumbs`, and generic keys). PLAYLISTS/PALETTES/PROJECTS retain their source associations and content. Rebuilding edges does not authorize deleting source records or permanently issued tokens.
8. **Transaction context:** canonical sync, lock propagation, and guarded single deletion participate in an existing PDO transaction and commit/rollback only when they started it. Do not generalize this guarantee to every older endpoint; exceptions are recorded in section 7.
9. **Public errors remain distinct:** typed missing-resource failures produce 404; internal/configuration/network failures must not be presented as confirmed missing links. Existing downstream exception inconsistencies are documented below.
10. **Preserve attribution and experience:** `src` is request attribution, not token identity. Canonical/OG/sitemap URLs omit tracking state; playback and ANA continue consuming source attribution and the resolved experience.

## 3. Directory and file inventory

Paths in this table are relative to `app/REX/`.

| Location | Responsibility |
|---|---|
| `Contracts/RexReservationRepositoryInterface.php` | Persistence interface for reservations, aliases, graph traversal/mutation, fallback, bulk resource deletion, and locks. Concrete-only additions are distinguished in section 5. |
| `Contracts/RexResolverInterface.php` | Resolver contract: `resolve`, `describe`, `previewDescribe`. |
| `DTO/RexCreateReservationRequest.php`, `RexUpdateDestinationRequest.php`, `RexUpdateMetadataRequest.php`, `RexReservationSearchCriteria.php` | Creation/update/search inputs; metadata update currently contains only reservation ID and label. |
| `DTO/RexReservation.php`, `RexAlias.php`, `RexReservationLink.php`, `RexReservationRelationship.php` | Readonly reservation/alias/edge records; relationship adds edge ID/key/order to the related reservation. Reservation DTO has no lock fields. |
| `DTO/RexResolutionRequest.php`, `RexResolutionResult.php`, `RexResolutionBehavior.php`, `RexShareMetadata.php`, `RexReservationDescriptor.php` | Runtime request/result, `render`/`redirect` enum, nullable share title/description/image, and descriptor title/fields. |
| `Endpoints/RexPublicEntryEndpoint.php`, `RexResolveEndpoint.php` | Public HTML entry and public JSON resolution; generic failure logging and shared JSON payload construction. |
| `Endpoints/RexCreateEndpoint.php`, `RexPreviewEndpoint.php`, `RexReservationsListEndpoint.php`, `RexResourceOptionsEndpoint.php`, `RexQrReservationSaveEndpoint.php` | Administrative create/reuse, describe-before-create, listing, resource options, and QR reservation update. |
| `Endpoints/PlaylistExperiencesEndpoint.php` | Wired GET inspection / POST canonical full-sync adapter. |
| `Endpoints/RexPlaylistExperiencesEndpoint.php` | Separate inspection/sync adapter with a different response envelope; no caller found in the searched repository sources. Not the class wired to `playlist-experiences.php`. |
| `Endpoints/RexRelationshipsEndpoint.php`, `RexPlaylistAuditEndpoint.php`, `RexPlaylistSyncEndpoint.php` | Public-experience graph inspection and older Public-only audit/repair HTTP adapters. These are admin endpoints despite the graph's Public experience. |
| `Repos/PdoRexReservationRepository.php` | Sole REX repository implementation; reservation/alias/edge SQL, resource-option discovery, lock propagation and deletion mechanics. |
| `Resolvers/RexResolverRegistry.php`, `RexResolverRegistryFactory.php` | Resolver registration/lookup and PDO-based composition of the five current resolvers. |
| `Resolvers/PlaylistExperienceResolver.php`, `PlaylistThumbsResolver.php`, `ViewerResolver.php`, `RouteResolver.php`, `DocumentResolver.php` | Destination adapters detailed in section 4. |
| `Resources/RexRouteCatalog.php` | Fixed numbered site-route catalog, currently Home and Digital Business Card. |
| `Services/RexTokenGenerator.php`, `RexReserver.php`, `RexResolver.php`, `RexReservationRelationships.php` | Token allocation, creation validation, central resolution/fallback, graph validation/traversal and relative public URL formatting. |
| `Services/RexPlaylistExperienceSyncService.php` | Canonical multi-experience desired-graph inspection/reconciliation. |
| `Services/RexPlaylistAudit.php`, `RexPlaylistLinker.php` | Older Public-only audit and incremental repair path. |
| `Services/RexPlaylistViewerLinker.php` | Conversion tool's Public playlist-to-viewer linking path. |
| `Services/RexViewerPlaylistLinker.php` | Creation-time viewer-to-existing-playlist backlink insertion. |
| `Services/RexIndexingPolicy.php`, `RexSitemapService.php` | Shared public-playlist index eligibility and current XML sitemap composition. |
| `Exceptions/RexNotFoundException.php` | Missing/inactive reservation or missing supported destination signal; extends `RuntimeException`. |

No REX Manager or Controller directory is present. Do not invent an extra layer or an implemented capability to fill the template.

## 4. Supported resolvers and destinations

`RexResolverRegistryFactory::build(PDO $pdo): RexResolverRegistry` registers exactly the following keys. `register(string $resolverKey, RexResolverInterface $resolver): void` replaces that key's entry; `get(string $resolverKey): RexResolverInterface` throws `InvalidArgumentException` for unknown keys.

| Resolver key | Current destination and behavior |
|---|---|
| `playlist_experience` | Playlist ID plus reservation experience; returns `render` with `destination.playback_plan` from `App\PLAYLISTS\Services\PlaylistExperienceService::buildPlaybackPlanFromPlaylistExperience`. Runtime resolution requires a positive ID/experience but does not independently validate `resource_type`; preview requires `playlist`. |
| `playlist_thumbs` | Requires `resource_type=playlist`, exactly one `thumbs` parent that is an active playlist-experience REX for the same playlist, and current eligible Viewer children. Returns `render` with `destination.collection`: parent URL/ID, experience, and ordered deduplicated PV/photo/swatches/viewer URLs. Empty collections throw `RuntimeException`, enabling configured fallback. One remaining viewer is valid. |
| `viewer` | Supports `palette_viewer`, `saved_palette`, and `color_plan` through current PALETTES `PVService` and transitional shared `ViewerService`. Returns `render` with `destination.viewer`, experience/format, and CTA information. Actual PV content comes from `PVService::getPV`; formats/descriptions still consult shared code. |
| `route` | Resolves catalog resource ID 1 to `/`, 2 to `/card`. Returns `redirect` with `destination.path`; it does not validate a particular resource-type string. `/t/` deliberately handles this through React to record ANA before navigation. Not an arbitrary external-URL redirect registry. |
| `document` | Requires `resource_type=doc`; reads `project_documents` by ID and project client name. Returns `render` with `destination.document`, including content HTML, status and approval information. Does not itself require a logged-in client or restrict document status. PROJECTS owns approval actions. |

**Experience limits differ today.** Canonical sync knows Public (`site` slide flag), Concept, Showcase and Client profiles; selected custom experiences are read from PLAYLISTS configuration. Client project sync uses FINAL project palettes and omits the separately shared project-wide Painter destination. Playlist preview accepts only Public/Concept/Showcase/Client. Thumbs resolution also limits itself to those four; shared Viewer format validation accepts Public/Concept/Client/Painter, not Showcase. The PLAYLISTS project playback branch has its own fixed experience list. Custom registration is therefore not proof that every resolver/preview path can play that configuration.

## 5. Public PHP entry points

### Public routes and HTTP contracts

| Route / file | Implemented adapter and contract |
|---|---|
| `/t/{token}` → `.htaccess` → `t.php` | Rewrite preserves query parameters. Thin bootstrap calls `RexPublicEntryEndpoint::handle(PDO $pdo, string $indexPath): void` with root `rex-shell.html`. No admin guard; resolves before emitting HTML or redirect. Missing/empty token or unhandled typed missing destination: 404 HTML. Other failures: 503 HTML, `Retry-After: 60`. Errors are noindex. |
| `/api/v2/rex/resolve.php` | Thin bootstrap calls `RexResolveEndpoint::handle(PDO $pdo): void`. Public GET `token`, optional `src`, `return_to`, `origin_playlist`; other methods 405, blank token 400, confirmed missing 404, other exceptions 500. JSON/no-store. Does not expose exception messages publicly. |
| `/sitemap.xml` → `sitemap.php` | Public bootstrap calls `RexSitemapService::urls()` and static `xml(array $urls): string`; XML/no-store. Failure: 503 with `Retry-After: 300`. `public/robots.txt` references this URL; robots.txt is a static public asset, not a REX endpoint class. |

`RexResolveEndpoint::payload(RexResolutionResult $result, RexReservation $reservation): array` returns `{ok:true,data:{resolver_key,resource_type,resource_id,behavior,destination,share_metadata,analytics_metadata,rex}}`. `share_metadata` has `title`, `description`, `image_url`. `rex` has original reservation ID, resolver/resource identity and experience; the resolved result/destination can instead identify a fallback. No token is included in that `rex` object. `logFailure(Throwable $error): void` logs exception class/file/line, without exception messages, credentials, cookies, or request parameters.

### Administrative API doorbells

All URLs below are under `/api/v2/admin/rex/`. Every routable file listed includes `api/v2/admin/auth.php`, which delegates to `App\SYSTEM\Http\AdminGuard::requireForEndpoint`. Several handle OPTIONS before auth. Endpoint classes expect an injected PDO; their existence does not independently authorize callers.

| File | Method, adapter and current inputs/output |
|---|---|
| `create.php` | POST → `RexCreateEndpoint::handle(PDO): void`. Required label/resolver/resource/positive ID; optional note/context/experience/QR key and `reuse_existing`. Returns `ok,item,reused`. Reuse compares active identity/experience and recursively key-sorted context, preferring oldest ID; links qualifying PVs to playlist parents through `RexViewerPlaylistLinker`. No mandatory server-side preview or destination-existence validation. |
| `preview.php` | POST → `RexPreviewEndpoint::handle(PDO): void`. Resolver/type/ID/context → `ok,descriptor:{title,fields}`. Describes without reserving. Argument/domain/runtime errors 400; other exceptions 500. |
| `list.php` | GET → `RexReservationsListEndpoint::handle(PDO): void`. `ids`, `qr_only`, or required `resource_type` with optional resource ID/experience. Returns `ok,resource_types,items`, up to 500 search records, including descriptors or per-record descriptor errors. `thumbs` is an administrative grouping of stored playlist resources. |
| `resource-options.php` | GET → `RexResourceOptionsEndpoint::handle(PDO): void`. `resource_type` → `ok,items:[{id,title}]`. Whitelisted table/column introspection; supported option types differ from resolver resource types. |
| `qr-save.php` | POST → `RexQrReservationSaveEndpoint::handle(PDO): void`. Reservation ID, QR key, label, destination identity/context/optional experience → `ok,item`. Transactionally updates an existing token's metadata/QR key/destination. |
| `playlist-experiences.php` | GET/POST → **`PlaylistExperiencesEndpoint::handle(PDO): void`**. GET `playlist_id` inspects; POST JSON `playlist_id` invokes full canonical sync, returning `ok,item`. Invalid ID 422; other thrown failures 500. POST does not limit sync to the experience clicked in the UI. |
| `relationships.php` | GET → `RexRelationshipsEndpoint::handle(PDO): void`. Requires `resource_type=playlist`, positive resource ID; inspects oldest active first-class Public parent, its parents/children/fallback. Returns `has_rex=false` with empty graph when absent. |
| `audit-playlist.php`, `sync-playlist.php` | GET audit / POST repair, `playlist_id` → `RexPlaylistAuditEndpoint::handle(PDO): void` (`ok,data`) / `RexPlaylistSyncEndpoint::handle(PDO): void` (`ok,result`). Older Public-only path; no calling frontend found in repository route searches, but authenticated HTTP routes still exist. |
| `playlist-url.php` | GET playlist ID and optional experience (Public default). Inline canonical selection; if missing, invokes `syncPlaylist(id,experience)` then looks again. Prefers active/unrevoked matches with more Viewer children, then oldest ID. Returns `ok,experience_key,item` or `item:null,reason`. **GET can create reservations/edges.** Used by desktop Admin Play and SAS. |
| `playlist-summary.php` | GET; inline composition of active playlist-experience reservations with PLAYLISTS admin metadata → `ok,items`. Conversion tooling consumes it. |
| `playlist-palettes.php` | GET playlist ID; inline selection of Public playlist REX and Viewer children resolved through shared ViewerService → `ok,playlist_id,playlist_rex,items`. Consumed through `src/helpers/rexHelpers.js`. |
| `link-playlist-viewers.php` | POST optional playlist ID; inline composition invokes `RexPlaylistViewerLinker::linkAll` → `ok,result`. Conversion page consumer. |
| `route-catalog.php` | GET; inline `RexRouteCatalog::all()` projection → `ok,items:[{resource_id,title,path}]`. Reservation Admin consumer. |
| `create-alias.php` | POST reservation ID/alias; inline existence/alias-use checks and repository insertion → `ok,item`. Reservation dialog consumer. |
| `update.php` | POST ID, label, resolver/resource/type/ID/context; inline metadata and destination updates → `ok,item`. No transaction enclosing both updates; no explicit first-class experience input in this wrapper. |
| `revoke.php`, `reactivate.php` | POST ID; inline lookup/state mutation → `ok,item`; unknown reservation 404. |
| `create-relationship.php`, `remove-relationship.php` | POST parent/child IDs/key, plus nonnegative order for create; inline adapter to relationship service. Create returns `ok,item` with edge identity/IDs/key/order/timestamps; remove returns `ok,removed`. |
| `list-parents.php`, `list-children.php` | GET child or parent reservation ID and optional relationship key; inline graph traversal → `ok,items`. |

`_helpers.php` is an included validation/serialization helper, not a published route. The inline files are existing architectural exceptions, not templates for new endpoints. Unless a row says otherwise, admin validation errors generally use 400, unsupported methods 405, and unexpected errors 500; many admin responses expose exception messages. `playlist-palettes.php` throws for a bad method and its generic catch returns 500 rather than the usual 405. Resource-options additionally exposes exception class/file/line to authenticated admins. Payload serializers differ: create and `_helpers` omit first-class experience fields that list/QR payloads expose. Preserve real envelopes rather than inventing one universal admin response contract.

No repository frontend caller was established for the REX update/revoke/reactivate, generic graph mutation/traversal, or older audit/sync URLs. Similarly named endpoints in other departments are not evidence of REX callers. Existence plus SYSTEM guard establishes reachability, not usage or permission to remove them.

### Service-level interfaces for internal callers

Types below are in `App\REX\DTO` unless qualified otherwise. These implemented interfaces are callable internally; an HTTP route is published only where mapped above.

- `RexTokenGenerator::generate(): string`: 32 random bytes encoded as unpadded base64url (43 characters).
- `RexReserver::reserve(RexCreateReservationRequest $request): RexReservation`: nonblank label/resolver/type, positive ID, status exactly `active` or `revoked`; invalid input throws `InvalidArgumentException`. Tries up to eight prechecked tokens, then `RuntimeException`. Does not validate resolver registration/destination content or retry a database insert collision automatically.
- `RexResolver::resolveToken(string $token, array $requestMetadata=[]): RexResolutionResult`, `resolveAlias(string $alias, array $requestMetadata=[]): RexResolutionResult`, `describeReservation(RexReservation $reservation): RexReservationDescriptor`, `previewDescribe(string $resolverKey,string $resourceType,int $resourceId,array $context=[]): RexReservationDescriptor`. Construct with repository interface and registry. Blank lookup throws `RuntimeException`; unknown lookup throws `RexNotFoundException`; resolver errors otherwise propagate subject to section 8. Alias resolution exists internally/tests; no public alias-resolution URL was found.
- Every `RexResolverInterface` implementation provides `resolve(RexResolutionRequest $request): RexResolutionResult`, `describe(RexReservation $reservation): RexReservationDescriptor`, and `previewDescribe(string $resourceType,int $resourceId,array $context): RexReservationDescriptor`.
- `RexReservationRelationships::create(int $parentReservationId,int $childReservationId,string $relationshipKey,int $sortOrder=0): RexReservationLink`, `remove(int,int,string): bool`; `children(int,?string=null): array`, `parents(int,?string=null): array` return reservations; `childRelationships(int,?string=null): array`, `parentRelationships(int,?string=null): array` return relationship DTOs. `childrenForResourceExperience(string $resolverKey,string $resourceType,int $resourceId,string $experienceKey,?string $relationshipKey=null): array` returns the selected parent's relationship DTOs, or empty. `publicUrl(RexReservation $reservation): string` returns relative `/t/` plus token. Creation rejects invalid/nonexistent/self/duplicate edges and negative order via `InvalidArgumentException`; it does not reject longer graph cycles or impose a relationship-key vocabulary.
- `RexPlaylistExperienceSyncService(PDO)`: `inspectPlaylist(int $playlistId): array` is read-only; `syncPlaylist(int $playlistId,?string $selectedExperience=null): array` returns playlist identity, overall `ok`, issue/warning counts, and per-experience graph/change reports; `syncProjectPlaylistsForSavedPalette(int $savedPaletteId): void` syncs active project playlists referencing that saved palette. The latter mutates despite an outdated nearby read-only comment. Invalid IDs/configured custom experience throw `InvalidArgumentException`; database/runtime failures propagate. A valid HTTP envelope does not imply the nested sync report is issue-free.
- `RexPlaylistAudit::audit(int $playlistId): array` is read-only; `RexPlaylistLinker::sync(int $playlistId): array` repairs its Public audit results; `RexPlaylistViewerLinker::linkAll(?int $playlistId=null): array` reports created/existing/skipped links; `RexViewerPlaylistLinker(PDO)::link(RexReservation $reservation): void` adds backlinks for qualifying active Public/Concept/Client PVs. The first three use injected repositories/services as declared in their constructors, not PDO-only factory signatures.
- `RexIndexingPolicy::allows(PDO $pdo,RexResolutionResult $result): bool`; `RexSitemapService(PDO)::siteUrls(): array`, `candidateTokens(): array`, `urls(): array`, static `xml(array $urls): string`; `RexRouteCatalog::get(int $resourceId): ?array`, static `all(): array`.

**Repository interface and concrete additions.** Construct `PdoRexReservationRepository(PDO $pdo)`. The interface publishes create/find/search; aliases; `createLink`/`removeLink`; parent/child reservation and relationship reads; destination/label updates; QR key, fallback and status setters; token existence; active grouped lookups; `deleteByResource(string,int): int`; `setREXLock(int,bool,?string=null): array`. `findById`, `findByToken`, `findByAlias` return nullable reservation DTOs; create/update/setters return a reservation or throw if reread fails. SQL errors propagate as PDO exceptions. Search criteria are nullable query/resolver/type/resource ID/status, limit (default 200, clamped 1–500), nullable experience/QR key and `qrOnly=false`; results are newest-created/ID first. Query text searches label/token/type/resolver/experience/alias with LIKE and ID/resource ID exactly.

`findActiveByResourceIds(string,string,array): array` groups **lists** of reservations by resource ID, ordered oldest ID first. `findActiveByResourceIdsAndExperience(string,string,array,string): array` returns **one oldest** reservation per resource ID. Both filter `status='active'`, but do not additionally require `revoked_at IS NULL`; do not substitute them for full resolution/access checks.

Concrete-only operations: `findByResource(string,int,int $limit=100,bool $forUpdate=false): array` adds MySQL current-read locking beyond the interface; `deleteREX(int $reservationId): array` is the guarded single-delete used by current owning managers; `countActiveByResources(string,array): array` returns ID-to-count; static `supports(string): bool`, `listOptions(string): array`, `listResourceTypes(): array` support admin discovery. The interface omits `deleteREX` although it is a current cross-department dependency.

**Input/result records.** `RexCreateReservationRequest(label,resolverKey,resourceType,resourceId,?adminNote=null,context=[],status='active',?experienceKey=null,?qrKey=null)`; `RexUpdateDestinationRequest(reservationId,resolverKey,resourceType,resourceId,context=[],?experienceKey=null)`; `RexUpdateMetadataRequest(reservationId,label)`. Resolution requests carry reservation, matched-by mode, original lookup value, destination identity/context and request metadata. Results carry resolver/resource identity, behavior, share metadata, destination array and analytics metadata. Result constructors reject invalid redirect/render shapes with `InvalidArgumentException`.

## 6. Persistence and data relationships

**REX-owned tables visible in current repository code:**

- `rex_reservations`: `id`, opaque `token`, label, administrative note, optional QR key, resolver/resource identity, first-class experience, JSON context, status/revocation/timestamps, optional fallback reservation ID, lock flag/reason. Lock fields are read directly by guarded deletion rather than represented on `RexReservation`.
- `rex_aliases`: alias identity, reservation ID, alias text and timestamps. Aliases point to reservations, not directly to destination content.
- `rex_reservation_links`: edge ID, parent/child reservation IDs, relationship key, sort order and timestamps. Traversal preserves edge order. Graph edges are independent from fallback links.

REX reads PLAYLISTS tables (`playlists`, items, item-palette references and Player Experiences), PALETTES tables (`palette_viewers`, saved palettes/members and related viewer/photo data through dependencies), PROJECTS tables (`projects`, `project_palettes`, `project_documents` and color plans through services), and `ana_pages` for sitemap paths. Reading these does not transfer their ownership to REX. Canonical synchronization locks a PLAYLISTS row for serialization but does not update publication flags, slides or source content.

**Schema evidence and limits:** archived `database/archive/2026_10_10_baseline/2026_08_10_001_create_rex_reservations.sql` establishes a unique, case-sensitive ASCII token, unique alias, and alias-to-reservation cascading FK. It predates current note/QR/experience/fallback/lock fields and the relationship model. It is not a complete current schema. Do not assert that destination FKs, composite resource/experience uniqueness, link uniqueness, or current production constraints exist without inspecting authoritative schema. No schema change or production introspection was performed for this manifest.

**Admin option vocabulary is not a resolver registry.** `RESOURCE_MAP` in the REX repository covers playlist/playlists, palette/project_palette, document/project_document and project tables. It omits runtime types such as `doc`, `saved_palette`, `palette_viewer`, and `color_plan`. Listing also advertises UI groupings such as `page`/`thumbs`. These mismatches are transitional; a selectable type is not proof of a valid resolver/resource pair.

## 7. Dependencies and boundaries

### Verified consumers and collaborators

| Consumer / dependency | Current cooperation |
|---|---|
| Public frontend: `src/Router.jsx`, `src/pages/REX/RexPublicPage/index.jsx`, `src/helpers/rexResolution.js`, `src/RX/` | `/t/:token` consumes eligible embedded data or GET resolver JSON, dispatches to Player/PV/Thumbs/Document and records ANA. Route destinations record a visit before navigating. PLAYER metadata implementation is in `src/components/PlayerExperience/Index.jsx`. |
| REX Admin: `src/routes/AdminRoutes.jsx`, `src/REX/Reservations/index.jsx`, `src/REX/Relationships/index.jsx`, `src/components/REX/` | `/admin/rex`, `/admin/rexrelationships`; create/preview/options/list/catalog/graph inspection and canonical sync. Dialogs are consumers of REX APIs, not independent token stores. |
| Conversion/QR/project/publishing Admin | `src/pages/AdminRexConversionPage/index.jsx` uses summary/link-viewers/playlist URL/create/preview/alias; `AdminQrSheetsPage` uses list/create/QR-save; `AdminProjectPage/ProjectDocuments.jsx` uses REX list; `AdminPubPage/PubVideoAssetEditor.jsx` consumes experience inspection. |
| PLAYLISTS | `Managers/PlaylistManager.php` saves source items then calls canonical sync in its transaction; deletion checks each playlist reservation through `deleteREX`. `Services/PlaylistExperienceService.php` supplies playback plans and reads REX graph navigation. `Services/PlaylistSetService.php` reads reservations for current homepage playlist collections; Playlist Sets here are not retired Palette Sets. |
| PALETTES | `Services/PaletteViewerAccessService.php` prepares/reuses Viewer REX via REX repository/reserver/relationships; `Services/PublicSavedPaletteService.php` discovers Public Viewer links. `Endpoints/AdminPVCreateEndpoint.php` triggers project-playlist sync. `Managers/PVManager.php` uses guarded REX deletion. `Repos/PdoPVRepository.php`, `PdoPVThumbRepository.php` read graph data; `PV/PVService.php` supplies current viewer content. |
| PROJECTS | `Services/ProjectViewerRexService.php` prepares/reuses color-plan Viewer reservations and links matching configured playlist parents, returning warnings/ambiguity rather than inventing a parent. `Endpoints/ProjectDocumentApproveEndpoint.php` separately checks original reservation active/unrevoked, resolves it, and verifies the document identity before PROJECTS approval. `Repos/PdoProjectPhotoRepository.php` uses graph joins in project-photo context checks. |
| PUB | `Analyze/Sources/PlaylistSourcePreparer.php` obtains Public Viewer children through `childrenForResourceExperience`; `Analyze/Pinterest/Support/PlaylistPaletteResolver.php` has its own find/create policy using REX services; `Package/Pinterest/PinterestImagePackager.php` consumes REX destinations. `Dispatch/DispatchManager.php` chooses when to lock the Public playlist tree as dispatched/published or clear temporary locks. REX performs propagation; PUB retains production/dispatch ownership, including current CHEF recipes. |
| SAS | `app/SAS/Repos/PdoRexPlaylistRepository.php` read-only LEFT JOIN lists all saved playlists, including those without REX. `RexPlaylistSearchService.php` adds configured experience choices without creating reservations. `src/SAS/services/playlistsApi.js` calls the same `playlist-url.php` as desktop Admin Play only when preparing the selected experience; adds `src=sas`. Sending/QR/Quick Play reuse the prepared URL. |
| GALLERY | Existing `app/GALLERY/Endpoints/GalleryEndpoint.php`, `GalleryResultsEndpoint.php` and transitional `GalleryRunQueryEndpoint.php` read REX reservations for picture-swatch Viewer links. REX does not choose Gallery photos or their eligibility/crops. These separately authorized GALLERY changes are outside this manifest's change. |
| ANA / SYSTEM / bootstrap | Public components use `src/ANA/` provider/tracking/client. Shared `app/Analytics/Services/AnalyticsService.php` uses the route catalog. REX emits metadata, not event rows. `api/v2/admin/auth.php` uses SYSTEM guard; `api/autoload.php` registers departmental namespaces; `api/db.php` supplies PDO/timezone/logging. |

Transitional shared dependencies include `App\Services\ViewerService`, `PaletteViewerAdminService`, `PaletteViewerService`, and `App\Repos\PdoPaletteViewerRepository`, `PdoSavedPaletteRepository`, plus project/player repositories used by shared viewer code. `api/v2/admin/palette-viewers.php` still constructs shared admin services using REX dependencies. Their presence does not approve expanding departmental logic outside its owner.

### Architectural exceptions — current implementation, not approved future design

| Finding / evidence | Consequence and proposed follow-up for owner review |
|---|---|
| Inline REX orchestration/validation/serialization in the administrative doorbells listed in section 5 and `_helpers.php`. | Violates thin-doorbell layering. Proposed separate cleanup: retain URLs/envelopes and move owning logic into existing REX layers after caller tests. No move is authorized by this manifest. |
| Four overlapping link/sync services, plus creation/reuse policy in PALETTES, PROJECTS, PUB and `src/helpers/rexHelpers.js`. | Different scopes and canonical-selection rules can disagree. PUB's Pinterest helper still identifies Public experience from context, which can miss newer first-class-only records. Recommend reviewing the canonical REX preparation capability; owner decides consolidation, not this audit. Do not remove reachable conversion/repair paths on naming alone. |
| Two playlist-experiences Endpoint classes with different `item` versus `data/sync` envelopes; only `PlaylistExperiencesEndpoint` is wired. | Document actual route/class association. Alternate class is implemented but no caller was found in inspected app/API/frontend/scripts; external jobs are unverified. Proposed retirement requires broader usage evidence. |
| `ViewerResolver` uses current PALETTES PV content but shared ViewerService for format/description and other resource types; older audit/linker still treats departmental PV lookup as an object with swatches, while `PdoPVRepository::findById` returns an array. | Transitional split ownership and apparent contract drift. Canonical sync already validates members through a saved-palette repository. Do not present older repair as equivalent or tested healthy; review PALETTES ownership/interfaces before fixing it. |
| REX → PLAYLISTS playback/sources and PLAYLISTS → REX navigation/sync; PALETTES/PROJECTS also both supply content and consume REX. | Bidirectional dependency at department level, though resolution need not recursively call itself. Record both sides in their manifests; a future split of content retrieval from graph/navigation requires an architectural decision. |
| Admin resource options, preview/runtime experience lists, DTO/payload exposure and concrete/interface methods disagree. | Clients cannot assume every option works with every resolver, or every repository method is in the interface. Proposed future contract alignment must preserve existing consumers and be tested separately. |
| QR-save owns an unconditional transaction; ordinary update performs two separate mutations; older linkers lack canonical sync's transaction/serialization boundary. | Do not call these as transaction-safe substitutes for canonical internal services. Transaction ownership and concurrency need explicit review before reuse. |
| Indexing policy and sitemap composition currently live in REX, including a direct read of ANA page configuration. | Actual dependency, not a decision that REX owns ANA configuration or all site SEO. Any relocation/ownership change requires owner approval. |

Publication-lock and access/fallback concerns are detailed in section 9. No architectural concern in this document has been fixed, waived or silently converted into a new ownership rule.

## 8. Registration, resolution and synchronization workflows

### Registration and canonical playlist preparation

1. Administrative creation supplies a create DTO; `RexReserver` validates minimal identity fields and allocates a random token. Repository creation normalizes experience, stores JSON context and returns the persisted record. General creation does not guarantee destination validity or global resource uniqueness.
2. `RexCreateEndpoint` optionally reuses a matching active reservation/context and then runs creation-time PV backlink insertion. That reuse precheck is not the same concurrency boundary as playlist sync.
3. Desktop Admin Play and SAS use `playlist-url.php`. Its initial lookup can return an existing active/unrevoked token. When missing, it invokes selected-experience canonical sync, then repeats lookup. No playlist publication flag is changed.
4. Canonical sync locks the playlist row (`FOR UPDATE` on MySQL), uses current reservation reads for playlist resources within its transaction, derives slide/palette/PV requirements and chooses existing permanent identities. It creates missing identities and rebuilds only managed `viewer`/`thumbs` edges; it does not delete reservations. Missing PVs are warnings; conflicts/invalid or revoked required identities can block an experience while valid sibling experiences proceed.
5. Full sync covers the four standard profiles. Selected sync can ensure a parent even for an empty experience; otherwise a never-issued empty experience is not applicable. Multiple primary viewers require a Thumbs identity with parent fallback. Zero/one current viewers can remove its graph edge while retaining the token/fallback. Existing tokens survive graph changes. Inspection uses standard profiles and is not a full custom-experience catalogue.

### Public resolution

1. Core token lookup trims the value and finds a reservation. Alias lookup uses the same resolution pipeline internally. At each hop the resolver checks `status='active'` and `revokedAt===null`, gets the resolver from the registry and delegates with resource/context/request metadata.
2. A `RuntimeException`, including `RexNotFoundException` from an inactive reservation, may follow `fallback_rex_id`. Every hop repeats checks; visited reservation IDs detect cycles. A missing fallback row is a runtime failure. `DomainException`, `InvalidArgumentException` and other non-runtime Throwables do not trigger this fallback catch.
3. Public adapters pass `entry_point`, `src`, request URI, navigation values, IP and user agent as request metadata. Playlist source is trimmed/lowercased and accepted only by `/^[a-z0-9_-]{1,80}$/`; REX does not itself insert an ANA event. Resolver support for `start`/`start_target` exists for internal requests, but these public adapters do not map GET start parameters into that metadata.
4. `/t/` computes indexing eligibility on the resolved result. Eligible playlist HTML embeds `colorfix-rex-bootstrap`, safely JSON-escaped, so React reuses the already-resolved plan. Other render destinations retain the resolver API flow. A route result enters React for analytics before navigation; other redirect results would use HTTP 302.
5. React reads bootstrap only for the matching token, applies browser source attribution, or fetches resolver JSON. `readRexResponse` treats only 404 JSON with `ok:false,error:'Link not found.'` as missing. Non-JSON challenges, other errors and invalid destination data produce the retryable unavailable state. Missing route token is locally shown as missing.
6. Viewer CTA precedence: request `origin_playlist` beginning `/t/`, then an active matching-experience playlist parent, then `/`. Despite a "trusted metadata" comment, the first value originates in GET and receives a prefix check, not parent relationship verification. Browser `return_to` handles viewer Back navigation independently; the public browser fetch forwards token/source, not all server navigation metadata.

### Metadata, indexing and sitemap

`RexIndexingPolicy::allows` requires a rendered `playlist_experience`/`playlist` result, playback-plan experience exactly Public or Showcase, and actual stored playlist `is_active=1`, `is_public=1`, `indexable=1` for the **resolved** resource ID. It does not test `is_retired`. All other REX result types are excluded. The public adapter emits matching HTTP `X-Robots-Tag` and HTML robots metadata (`index, follow` or `noindex, noarchive`). Errors remain excluded.

Initial HTML uses nonblank plan `page_title`/`meta_description`, otherwise share title/description supplied by PLAYLISTS. Social metadata uses share metadata. If those values are also empty, the applicable shell title/description can remain. Canonical and `og:url` identify clean `/t/{token}` on the request origin, omitting query/fragment state. The playlist player keeps that REX canonical in the rendered DOM; it does not substitute `/playlist/{id}`. The server derives origin from forwarded protocol/HTTPS and HTTP host; proxy/host trust is a hosting assumption, not an origin allowlist.

Sitemap generation runs on each request: flagged `ana_pages.path` values (`include_in_sitemap=1`), parsed to leading-slash internal paths without query/hash, plus active/unrevoked first-class Public/Showcase playlist tokens joined to the same three stored eligibility flags. Each token is resolved and rechecked by policy; failures are logged/skipped. URLs are deduplicated and XML-escaped using fixed origin `https://colorfix.terrymarr.com`; no lastmod is emitted. Legacy context-only experiences are not discovered by the candidate SQL. A changed database flag needs no frontend rebuild.

## 9. Security and deletion rules

**Current access behavior:** administrative mutation/discovery routes use SYSTEM authentication; public token endpoints do not. Token possession is the public entry mechanism, with destination-specific checks rather than a uniform client login requirement. Noindex is not access control.

- PLAYLISTS public/project playback loads `getById`/`getPlaylistMeta` without applying playlist `is_active`, `is_public`, `indexable`, or `is_retired` filters in that lookup. Player Experience existence/activity and project attachment have separate checks. These flags must not be described as universal direct-REX gates.
- Current PV service rejects absent/inactive PVs and missing saved palettes, but throws ordinary `RuntimeException`; without fallback this becomes a 500 API / 503 HTML unavailable response, not typed 404. Thumbs explicitly filters current active matching-format PVs and requires a valid parent graph; it does not apply playlist marketing eligibility.
- Document resolution reads any found document row, returns status/content, and imposes no document-status/client-login gate. PROJECTS approval separately requires an active original reservation and verifies the resolved document; public rendering and approval authorization are not the same operation.
- Reservation status/revocation checks can follow fallback, so revoking an original token does **not** necessarily disable all access through it. Whether revocation should forbid fallback is an unresolved owner decision. Inconsistent destination checks similarly require review rather than an invented universal access rule.

**Current lock/deletion behavior:** `setREXLock(int $reservationId,bool $locked,?string $reason=null): array` walks the selected reservation and all current descendants, with cycle protection. Returns `ok,reason,message,affected_ids`; invalid/missing IDs/reasons return a failure array, SQL errors throw. Dispatched locks preserve already-published rows; publication promotes the current tree; unlocking preserves published rows and clears other locks. It does not continuously lock descendants added later; PUB invokes reconciliation again for subsequent dispatch attempts.

`deleteREX(int $reservationId): array` checks the actual row lock, refuses locked reservations with a clear failure array, and transactionally clears incoming fallback references, incident edges and aliases before deleting only that unlocked reservation. Related parent/child reservations and destination content are retained. Current PLAYLISTS and PALETTES managers coordinate this operation in their outer deletion transactions; a refusal aborts their source deletion.

**Enforcement gaps, not approved exceptions to publication safety:** `deleteByResource(string,int): int` removes matching reservations/aliases/edges and clears fallback references without lock checks or its own transaction. No current application caller was found in the repository search; the method remains on the interface and must not be assumed safe. `updateDestination`, `updateMetadata`, `setQrKey`, `setFallbackRexId`, `revoke`, `reactivate`, and relationship create/remove do not enforce publication locks. Normal sync may rebuild relationships involving locked identities. Locks therefore protect guarded deletion and selected dispatch workflows, not complete immutability or availability. Do not claim broader enforcement; a consistent policy requires owner approval and separate tests.

Relationship creation prevents self/duplicate edges but not multi-hop cycles. Fallback cycles are detected at resolution, not prevented by `setFallbackRexId`. Tokens are unguessable random values, not destination authorization checks. Administrative discovery can expose private destination metadata after admin auth; do not expose these APIs publicly to reuse their data.

## 10. Changes, tests, and documentation maintenance

Before changing REX:

1. Read root `SystemManifest.md`, this document, and relevant collaborator manifests. Verify the actual caller and destination owner before changing a contract.
2. Preserve public tokens, source attribution, experience selection, admin envelopes, current PUB/CHEF behavior and current access restrictions. Do not revive retired Palette Sets, masking or base/variant rendering.
3. Keep new API work thin and REX-owned; do not expand the transitional exceptions above. Owner approval is required to decide ownership conflicts, revocation/fallback semantics, publication-lock policy, or linker consolidation.
4. For a separately authorized code change, run relevant PHP syntax/regression checks and frontend integration checks. Test unknown/blank/revoked/fallback/cycle/error cases; existing-token reuse and concurrent sync; graph changes/empty experiences; Public/Showcase versus client indexing; metadata/canonical agreement; transaction ownership; lock/deletion refusal; SAS/ANA/PUB consumers. Use isolated fixtures for mutations.
5. Update this manifest in the same approved change as an interface/ownership/dependency/lifecycle change, with actual Pacific timestamp and change-log entry. Do not edit the system-level contract without approval.

**Existing verification sources (inspected, not executed for this documentation change):** `api/tests/RexCoreTest.php`, `RexFailureHandlingTest.php`, `RexIndexingTest.php`, `RexSitemapTest.php`, `RexViewerPlaylistLinkerTest.php`, `PlaylistThumbsResolverTest.php`, `PainterViewerRexTest.php`, `SASPlaylistTest.php`, and fixture `RexMetadataFixture.php`; browser scripts `scripts/test-rex-loader.mjs`, `test-rex-failures.mjs`, `test-rex-public-render.mjs`, `test-rex-canonical.mjs`, `test-rex-metadata.mjs`, `test-sas-playlists.mjs`. Test presence is not a claim that every current path passes; older fixtures/interfaces may also drift.

**Documentation validation:** verified the numbered PHOTOS structure adapted to REX, source paths/classes/methods and actual URL mappings/callers; reviewed the listed architectural exceptions against code. A before/after source-file hash comparison isolates this manifest from pre-existing GALLERY and other working-tree changes. Only `app/REX/DeptManifest.md` is authorized in this change. No application code, SQL, database data, API behavior, production files or deployment were changed. Runtime builds/tests are not required to validate a Markdown-only change and were not run here.

**Remaining verification limits / questions for approval:** live schema/index/FK state and deployed file versions; external callers/cron jobs not visible in the repository; intended owner of transitional Viewer preparation and overlapping linking policy; whether publication locks protect destination/status/topology changes as well as deletion; whether revoked/inactive reservations may fall back; exact destination access rules; alignment of configured experiences, preview/options and current viewer formats. These are documented unresolved issues, not blockers to mapping the existing implementation accurately. No new planned callable capability is declared. A comment proposing future `syncExperience(...)` is not an implemented interface or an approved commitment.

### Change log

| Date | Change |
|---|---|
| 2026-11-08 | Initial repository-based REX manifest; documents current routing/interfaces, consumers, persistence, metadata/indexing, lifecycle and unresolved architectural concerns. Documentation only. |
| 2026-11-08 | Added prioritized Future Improvements and initially empty Completed Improvements sections; checked repository Markdown formatting. No functional improvements implemented. |

## 11. Future Improvements

**Status: proposed, unimplemented, and subject to owner approval.** This section consolidates the concerns in sections 7 and 9; it does not replace the current technical inventory or publish new callable interfaces. Proposed ownership below is a recommendation, not a resolution of an ownership conflict. Priority reflects the risk of existing behavior and the value of correction. Dependencies determine implementation order; priority alone is not authorization to implement or retire anything.

### 11.1 Priority 1 — Define and enforce publication-lock policy

- **Problem:** Locks protect guarded single deletion and selected PUB workflows, but destination/status/fallback/topology mutations and `deleteByResource` can bypass that protection. Descendants added after locking do not automatically inherit protection. Published-link continuity has high value; changing enforcement has high compatibility risk.
- **Affected components:** `PdoRexReservationRepository`, `RexReservationRelationships`, canonical sync and older linkers, administrative mutation endpoints, PLAYLISTS/PV deletion managers, and PUB `DispatchManager`.
- **Proposed department ownership:** REX owns lock mechanics and enforcement; PUB owns dispatch/publication decisions; PLAYLISTS and PALETTES own their source lifecycles. The owner must first decide which changes a dispatched or published reservation may permit.
- **Intended outcome:** Consistent enforcement of the approved policy across every mutation path, without silently breaking stable URLs or preventing legitimate content maintenance. Any bulk operation would follow the same approved safety boundary as individual operations.
- **Dependencies:** Approved mutation/lock matrix, current database constraints, inventory of existing published graphs and callers, and agreed transaction/concurrency behavior. Do not assume the current lock means complete immutability.
- **Verification requirements:** Isolated tests for every mutation under unlocked/dispatched/published states; bulk and single deletion; descendants added later; dispatch success/failure/retry; rollback and concurrent mutation; unchanged permitted playback, token identity and PUB/CHEF production.

### 11.2 Priority 1 — Decide destination access and inactive/revoked fallback semantics

- **Problem:** Revoked/inactive reservations can follow fallback; destinations apply different availability/access checks; some missing/inactive PV failures become generic unavailable responses. Prefix-only `origin_playlist` validation and request-origin trust also need explicit review. Access correctness has high value, but imposing new gates could break intended client/direct links.
- **Affected components:** `RexResolver`, all destination resolvers, public HTML/JSON endpoints, shared Viewer code, PLAYLISTS playback, PALETTES PV services, PROJECTS document rendering/approval, and browser REX error/navigation handling.
- **Proposed department ownership:** REX owns token/fallback orchestration and public response mapping. PLAYLISTS, PALETTES and PROJECTS should own their destination eligibility/access rules; SYSTEM supplies authentication where an approved rule requires it. Hosting supplies trusted proxy/host configuration. The owner decides the policy; this proposal does not impose universal login or publication gates.
- **Intended outcome:** Explicit rules for whether revocation terminates access, which destination failures permit fallback, and how missing, denied and unavailable responses differ. Preserve the separation between direct access, public listings and search indexing, and validate navigation/origin inputs according to their intended trust boundary.
- **Dependencies:** Approved access/fallback matrix for each experience/resource; inventory of client links and document approvals; trusted hosting-origin assumptions; agreed domain error contracts. Coordinate with 11.1 before changing status semantics.
- **Verification requirements:** Missing/inactive/revoked original and fallback combinations; cycles and missing fallback rows; public/client/admin cases; document approval identity checks; no unintended private-content access; intended unlisted/direct links; non-JSON/network/5xx handling; navigation attribution and canonical/robots behavior.

### 11.3 Priority 2 — Consolidate overlapping REX preparation and linker policies

- **Problem:** Four linker/sync implementations and preparation policies in other departments select/reuse reservations differently. Some consumers inspect legacy context instead of first-class experience data. This can produce duplicate identities, inconsistent topology or token selection; consolidation offers high correctness and maintenance value with substantial migration risk.
- **Affected components:** `RexPlaylistExperienceSyncService`, `RexPlaylistAudit`, `RexPlaylistLinker`, `RexPlaylistViewerLinker`, `RexViewerPlaylistLinker`, `playlist-url.php`, frontend `rexHelpers.js`, and PALETTES/PROJECTS/PUB preparation callers.
- **Proposed department ownership:** Recommend REX as the authoritative owner of reservation preparation/reuse and graph mechanics; source departments retain their eligibility and association semantics. The owner must approve how overlapping operations converge before any implementation is retired.
- **Intended outcome:** One documented policy for canonical selection, experience identity, conflict reporting and concurrency, reused by desktop Admin and SAS as appropriate. Keep existing tokens and distinguish read-only search/inspection from reservation preparation.
- **Dependencies:** Complete caller inventory including external/maintenance clients; approved behavior from 11.1–11.2; destination interfaces from 11.4–11.5; comparison fixtures for every currently used conversion/repair path. No automatic deduplication or record migration is approved here.
- **Verification requirements:** Existing-token reuse, simultaneous preparation, custom/empty experiences, legacy context rows, duplicate/revoked/conflicting records, child ordering/fallbacks, source flags unchanged, SAS sending/Quick Play, PUB consumers, and source-deletion rollback.

### 11.4 Priority 2 — Establish departmental interfaces for transitional Viewer services

- **Problem:** Current PV rendering coexists with shared Viewer format/description/admin services and shared repositories. Older audit/repair code expects an object/swatches where the departmental repository now returns an array. This creates contract drift and unclear responsibility; remediation has high reliability value and high cross-consumer risk.
- **Affected components:** `ViewerResolver`, `PlaylistThumbsResolver`, older audit/linker code, shared `ViewerService`, `PaletteViewerService`, `PaletteViewerAdminService`, shared palette/viewer repositories, PALETTES `PVService`, PROJECTS color-plan integration, and their API/PUB consumers.
- **Proposed department ownership:** Recommend PALETTES own saved-palette/PV content and presentation data contracts, PROJECTS own project/color-plan semantics, and REX retain destination adaptation and token navigation. The owner decides the placement of mixed shared responsibilities; do not simply move shared classes wholesale.
- **Intended outcome:** Documented owner-provided interfaces for content and descriptors, with consistent data shapes and less bidirectional coupling. Preserve current Full/Before/Zoom presentation and project/client behavior; do not recreate retired rendering or Palette Sets.
- **Dependencies:** Approved responsibility map, current caller/data-shape inventory, fixtures for shared and departmental paths, and a compatibility sequence coordinated with 11.3 and 11.5.
- **Verification requirements:** Saved palette/PV/color-plan resolution and administrative descriptions; absent/inactive resources; each supported format; photo selection and colors; project permission contexts; Viewer CTAs/backlinks; PUB source preparation; unchanged current frontend payloads.

### 11.5 Priority 2 — Align contracts, experiences and transaction guarantees

- **Problem:** Resource options and preview/runtime format lists differ; repository interfaces omit current concrete operations; serializers expose inconsistent fields; QR-save and older workflows do not share canonical sync's transaction/serialization guarantees. Contract alignment offers medium-to-high value, but changing envelopes or experience handling carries compatibility risk.
- **Affected components:** REX contracts/DTOs/repository, create/preview/list/options/update/QR endpoints, both playlist-experiences adapters, PLAYLISTS experience configuration and project playback, Viewer/Thumbs format handling, and frontend consumers.
- **Proposed department ownership:** REX owns its reservation interfaces and HTTP adapters; PLAYLISTS owns Player Experience configuration/playback rules; PALETTES owns Viewer formats. The transaction coordinator owns commit/rollback. Cross-department vocabulary and interface changes require joint review and owner approval.
- **Intended outcome:** Accurate advertised capabilities, explicit supported resolver/resource/experience combinations, stable response contracts, and atomic operations that preserve an enclosing transaction. Decide whether the unreferenced alternate endpoint should remain only after caller verification.
- **Dependencies:** Approved experience support matrix, consumer compatibility requirements, authoritative schema, and the ownership decisions in 11.3–11.4. Do not infer that every configured experience is already supported everywhere.
- **Verification requirements:** Standard/custom/legacy experiences; options-to-preview-to-resolution round trips; required fields and error envelopes; rollback after partial failure; nested transaction participation; current-read concurrency; external caller checks before adapter retirement.

### 11.6 Priority 3 — Finish thin-doorbell cleanup

- **Problem:** Administrative REX API files still contain validation, canonical selection, multi-step mutation and serialization logic. Moving that existing logic to its owner has high architectural value, but behavior must first be captured to avoid accidental policy changes.
- **Affected components:** Inline administrative files and `_helpers.php` listed in section 5, especially `playlist-url.php`, update/status/alias/relationship operations, playlist summary/palettes, route catalog and conversion linking.
- **Proposed department ownership:** REX Endpoints handle HTTP adaptation; appropriate existing REX services/repository own reservation rules and persistence. SYSTEM continues supplying authentication. No new parallel API or unnecessary wrapper layer is proposed.
- **Intended outcome:** Stable public API URLs with bootstrap/authentication/delegation-only doorbells and one owner-controlled implementation of each operation.
- **Dependencies:** Caller and response characterization; decisions from higher-priority items where a move would otherwise embed conflicting rules. Separate mechanical relocation from any approved behavior change.
- **Verification requirements:** Before/after HTTP contract comparisons, all methods including OPTIONS, SYSTEM authorization, existing GET preparation behavior until explicitly changed, payload/status/header compatibility, internal service callers, and unchanged frontend/SAS workflows.

### 11.7 Priority 3 — Clarify sitemap/indexing boundaries and operational evidence

- **Problem:** REX currently composes site-wide sitemap paths from ANA configuration as well as REX URLs. Legacy context-only reservations are not sitemap candidates, and archived schema does not establish current constraints. Ownership and deployment assumptions remain unclear; clarification has medium value without requiring a redesign.
- **Affected components:** `RexIndexingPolicy`, `RexSitemapService`, `sitemap.php`, `ana_pages` configuration, reservation schema/deployment documentation, and collaborating department manifests.
- **Proposed department ownership:** Keep current REX behavior documented pending a decision about site-wide SEO composition. ANA retains its configuration data; PLAYLISTS retains stored publication fields. The owner decides any responsibility change or legacy-discovery policy; this manifest does not assign a new department owner.
- **Intended outcome:** Explicit ownership/dependency contracts and verified schema/deployment evidence, with one agreed eligibility policy and no unintended exposure of private tokens or tracking URLs.
- **Dependencies:** Owner decision on sitemap composition, current read-only schema/deployment inspection, legacy reservation inventory, and access/indexing decisions from 11.2 and 11.5. No schema mutation, token publication or migration is implied.
- **Verification requirements:** Dynamic flag changes, Public/Showcase and excluded resources, fallback destinations, legacy rows, XML escaping/deduplication, query-free URLs, initial/rendered canonical and robots agreement, and production endpoint checks after any separately approved deployment.

## 12. Completed Improvements

**No improvements have been implemented under this manifest.** The inventory, architectural findings, prioritized recommendations and Markdown checks are documentation work only. All items in Future Improvements remain proposed and unimplemented; none is authorized for implementation by this document.

After a separately approved improvement is implemented and verified, record its scope, affected files/interfaces, verification evidence, completion date, and local/deployed status here, and update the corresponding current inventory and future item. Do not mark an improvement complete merely because it has been documented, proposed or approved.

## Photo-specific public PV context

The existing `viewer` resolver and `saved_palette` resource type now support `context={format:"public",art_inspired_photo_id:<room Photo Library ID>}`, with first-class `experience_key=public`. Palette ID plus this context identifies the room experience; different rooms sharing a palette have independent stable tokens. No new resource type, tables or token generator is introduced. `ViewerResolver` obtains the normal saved-palette payload and delegates the room/inspiration presentation and visibility checks to PALETTES `ArtInspiredPhotoService` before returning viewer/share metadata. Ordinary reservations without this context are unchanged.

`RexReserver::findReusable(RexCreateReservationRequest): ?RexReservation` exposes the existing admin create path's active, exact destination/experience/recursively-key-normalized-context matching, choosing the oldest unrevoked match. Both the admin endpoint and PALETTES photo entry use it; token creation remains `reserve()`. Callers must serialize check-and-create (the public photo entry locks the owning saved-palette row). A saturated 500-row lookup with no match fails closed instead of creating a possible duplicate.

### Playlist share-title precedence

Playlist Experience and Playlist Thumbs share metadata use PLAYLISTS' `PdoPlaylistRepository::getCoverTitle(int $playlistId): string`: the first nonblank active `cover-image` slide title in authored order, independent of playback inclusion flags. Empty/missing cover titles retain each resolver's existing fallback. Public entry uses the resolved share title for both `og:title` and `twitter:title`; it does not rewrite playlist records or reservation identities.

## Published article sitemap composition (2026-11-21)

`RexSitemapService::urls()` now includes ARTICLES-owned `PdoArticleRepository::publishedIds()` as canonical `/articles/{id}` URLs. `siteUrls()` discards ANA-derived article-detail paths so stale registrations cannot reintroduce drafts. REX token eligibility remains unchanged. Publication state is queried on each sitemap request; ARTICLES owns transitions. Verified with isolated publication and existing RexSitemap tests. No production changes.
