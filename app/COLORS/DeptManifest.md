**UPDATED:** 2026-11-10 11:30:45 AM PST

# COLORS — Department Manifest

**Department:** `COLORS` · **Location:** `app/COLORS/` · **Namespace:** `App\COLORS`

**Status:** Current-source contract and architecture audit. Recommendations are not approved implementations or transfers of ownership. Only this document was changed by this assignment; no deployment or database inspection/mutation was performed.

**Evidence:** Root `SystemManifest.md`; all 18 COLORS PHP files; API doorbells, shared repositories/services, frontend callers, refresh scripts, archived view SQL and test fixtures. Relevant ARTWORK, PHOTOS and REX boundaries were reviewed. Neither `app/PALETTES/DeptManifest.md` nor a SEARCH department/manifest exists in this checkout. Their missing contracts are not permission to invent a SEARCH department or assign ambiguous responsibilities unilaterally.

## 1. Mission and ownership

COLORS currently implements HCL category classification, raw category assignment replacement, category materialization, cluster category summaries, full color-detail retrieval and six-digit hex matching. It owns the implemented refresh operation for the existing materialized swatch snapshot and the render-oriented `DTO/Swatch` contract consumed by GALLERY.

**Source identities:** A color is a catalog record identified by `colors.id`. A swatch is a presentation of that color, not a separate identity/table; `mv_swatch_view.id` and `Swatch.id` refer to the color ID. Equal hex values or names do not imply equal records. Hex search explicitly retains brands and same-hex twins.

**Current data operations:** COLORS reads catalog `colors` and category definitions; writes `color_category`, `mv_color_categories`, four category-summary columns of `clusters`, and replacement `mv_swatch_view` snapshots. Catalog create/update/delete and category-definition editing still reside outside this umbrella. They are not implemented COLORS CRUD interfaces merely because COLORS is the recommended catalog-domain owner.

**Does not own:** Saved-palette records, members, roles, hashes, deduplication, Palette Viewers or palette permissions (PALETTES); Photo Library images/crops/associations (PHOTOS); artwork metadata and public study composition (ARTWORK); Gallery eligibility, picture/art item selection and editorial result composition (GALLERY); REX tokens; publishing/CHEF production; ANA events; SYSTEM authentication; or the shared browser palette-bar Context/persistence. Color matching inside a palette workflow does not transfer ownership of that workflow to COLORS.

## 2. Core invariants

1. Full-precision color data and display swatches are different contracts. `DTO/Color` retains its input row without rounding; `DTO/Swatch` is a limited display payload with integer-rounded HCL. Do not use display rounding for matching/classification.
2. `ColorClassifier` is pure: finite HCL input, all matching category IDs including overlapping and `calc_only` definitions, no SQL or cached-column canonicalization. Chroma/lightness bounds are lower-inclusive/upper-exclusive with epsilon `1e-9`; normalized hue ranges include their boundaries and support wrapping.
3. The full rebuild owns its transaction. Assignment replacement, category MV and cluster summaries commit together; invalid HCL, count mismatch, cluster references or overlong cluster category strings abort it. Legacy cached category columns on `colors` are deliberately not rewritten.
4. Swatch snapshot publication is a separate, post-category-commit operation. A refresh failure preserves the old swatch table and returns a warning; it does **not** roll back already committed category changes.
5. Refresh is explicit: successful category recalc invokes it, the Colors screen offers a separate Refresh Swatches button, and a CLI exists. Color save/delete, ordinary requests and page navigation do not automatically rebuild it. No repository evidence establishes a database trigger or scheduler.
6. Hex search accepts exactly six hex digits, optional leading `#`, trimmed/case-normalized; all exact active matches precede up to 60 close results. It ignores global brand filters and does not collapse twins. Its Lab candidate pool is capped at 1000, so the close ranking is not an exhaustive Delta-E-2000 scan of every catalog record.
7. Administrative authorization belongs to SYSTEM-authenticated doorbells. Service/repository methods accepting PDO do not themselves authenticate a caller. One-time data corrections do not belong in runtime repositories.

## 3. Complete department inventory

Paths are relative to `app/COLORS/`. These are existing interfaces, not new service proposals.

| File | Implemented responsibility / interface |
| --- | --- |
| `DTO/Color.php` | `Color::fromRow(array): self`, `toArray(): array`; requires positive ID, lossless row. No production caller found in this audit. GetColorEndpoint currently returns arrays, not this DTO. |
| `DTO/Swatch.php` | Same factory/output signatures; positive ID, render-ready subset described below. GALLERY invokes it. |
| `Endpoints/GetColorEndpoint.php` | `handle(PDO): void`; full-detail HTTP adapter. |
| `Endpoints/HexSearchEndpoint.php` | `handle(PDO): void`; POST hex-search adapter and Gallery-compatible response metadata. |
| `Endpoints/CompareCategoriesEndpoint.php` | `handle(PDO): void`; bounded read-only category comparison. |
| `Endpoints/RecalculateCategoriesEndpoint.php` | `handle(PDO): void`; confirmed administrative full rebuild. |
| `Endpoints/RefreshSwatchesEndpoint.php` | `handle(PDO): void`; explicit administrative snapshot refresh. |
| `Managers/CategoryRecalcManager.php` | `__construct(PDO)`; `compare(int $batchSize=2000, int $sampleLimit=30): array`; `comparePage(int $afterId=0, int $limit=500, int $sampleLimit=20): array`; `rebuild(int $batchSize=500, ?callable $progress=null, bool $testRollback=false): array`. |
| `Services/ColorClassifier.php` | `classify(float $h, float $c, float $l, array $definitions): array` of category IDs; invalid nonfinite values throw `InvalidArgumentException`. |
| `Services/HexSearchService.php` | Constructor takes `PdoHexSearchRepository`; static `normalize(string): string`; `search(string): array`. Uses shared `App\Lib\ColorCompute` and `ColorDelta`. |
| `Repos/PdoColorRepository.php` | Constructor PDO; `count(): int`, `batchAfterId(int $lastId, int $limit=2000): array`, `findHcl(int $colorId): ?array`; source HCL reads only. |
| `Repos/PdoColorDetailRepository.php` | Constructor PDO; `getById(int): ?array`; shared detail enrichment plus category-MV override. Nonpositive/missing ID yields null; PDO failures propagate. |
| `Repos/PdoCategoryDefinitionRepository.php` | Constructor PDO; `activeByType(): array`, `visibleByTypes(array): array`, `hueSinglesOrder(): array`, `hueDisplayMap(): array`. Last three have no production caller found; they are not exposed web routes. |
| `Repos/PdoColorClassificationRepository.php` | Constructor PDO; `replaceForColor(int $colorId, array $categoryIds): void`, `replaceBatch(array $assignments): void`, `assignmentCount(): int`. Single replacement owns a transaction or uses a savepoint; batch requires caller transaction. Caller verifies color existence. |
| `Repos/PdoMaterializedCategoriesRepository.php` | Constructor PDO; `refresh(): array`, `refreshWithinTransaction(): array`, `rowCount(): int`. Standalone refresh owns a transaction; within-transaction method does not commit. Validates distinct source color count, refuses empty source. |
| `Repos/PdoClusterCategoryRepository.php` | Constructor PDO; `refreshWithinTransaction(): array`; unions category names per cluster from category MV, sorts names, clears empty summaries, enforces 64-byte fields. Does not own cluster matching/reclustering. |
| `Repos/PdoMaterializedSwatchRepository.php` | Constructor PDO; `refresh(): array`; clone/stage/validate/atomic-swap existing InnoDB snapshot. Cannot run inside a caller transaction. |
| `Repos/PdoHexSearchRepository.php` | Constructor PDO; `exact(string $hex): array` of IDs, `candidates(string $hex, array $lab): array`, `swatches(array $ids): array` keyed by ID. Candidate measurements come from `colors`; hydration still uses `swatch_view`. |

Repository methods generally propagate PDO errors. Validation/transaction/lock failures use exceptions; only HTTP endpoints translate them into status/payload contracts. Direct repository reachability is not proof of a published cross-department consumer.

## 4. Public interfaces and callers

All five doorbells bootstrap `api/autoload.php` and `api/db.php`, then delegate to the corresponding endpoint. The three admin files also require `api/v2/admin/auth.php`.

| Doorbell | Request | Response / errors | Verified consumer |
| --- | --- | --- | --- |
| `/api/v2/get-color.php` | Normally GET `id`, validated positive integer; endpoint has no explicit method gate. | `{ok:true,color:array}`; invalid 400, missing 404, unexpected 500 with generic error and server logging. | `src/data/fetchColorDetail.js`, called by ColorDetailPage and ColorForm/AdminColorEditPage. |
| `/api/v2/hex-search.php` | POST JSON `{params:{hex:string}}`. | `{success:true,meta,results,inserts:[],rowCount}`; `meta_id:18`, `search_mode:hex`, `preserve_match_order:true`, `reference_color`. Invalid 400, method 405, unexpected 500. | `src/pages/GalleryPage/index.jsx`, for query 18 with explicit hex; homepage `NameSearchItem` uses `src/helpers/colorSearch.js` to navigate into this flow. |
| `/api/v2/admin/colors/compare.php` | POST JSON `after_id` default 0, `limit` default 500/clamped 1–2000, `sample_limit` default 20/clamped 0–50. | `{ok:true,result:{mode,after_id,next_after_id,processed,different_colors,sample_differences,complete}}`; invalid 400, method 405, unexpected 500. Does not write classifications. | Doorbell verified; no frontend caller found. Do not invent a compare UI. |
| `/api/v2/admin/colors/recalculate.php` | POST JSON, `confirm:"rebuild_categories"`, optional integer `batch` 1–2000/default 500. Checks supplied Origin host/port and JSON content type. | `{status:"success" or "warning",message,summary}` with processed/materialized/clusters/swatches/seconds and `legacy_color_caches_updated:false`. 400/403/405/415; category-lock conflict 409; unexpected 500. | `src/pages/CategoryEditPage/RecalcCatsButton.jsx`, imported by CategoryEditPage. |
| `/api/v2/admin/colors/refresh-swatches.php` | POST with JSON content type, supplied Origin host/port checked; UI sends `{}`. No extra confirmation body is parsed. | `{ok:true,summary:{rows_before,rows_after,includes_is_inactive,...}}`; optional `retained_backup_table`. 403/405/415 or generic 500; lock conflict currently also maps to 500. | `src/pages/AdminColorEditPage/index.jsx`, independent Refresh Swatches button. |

**Payload boundaries:** `HexSearchService::search()` returns `{hex,reference_color,results}`. Reference swatch includes computed hex6/RGB/HCL and `item_type:"reference-swatch"`, no paint identity/name/brand. Results retain hydrated fields plus `item_type:"swatch"`, `hex_match_type` and `match_delta_e` in rank order.

`DTO/Swatch::toArray()` returns `id,name,brand,brand_name,hex,r,g,b,hcl_h,hcl_c,hcl_l,hue_cats,chip_num,is_stain`. HCL is rounded; hex has its leading `#` removed. It omits code, Lab, category-order and detail fields. GALLERY merges it over its original row, so those additional row fields can remain in Gallery results. Do not assume this DTO is the full `get-swatch.php` or palette-member response.

**Operational scripts:** `scripts/colors-rebuild.php` calls manager `rebuild()` after engine checks; `--confirm-atomic-rebuild` writes, `--test-rollback` performs real writes then intentionally rolls back and checks fingerprints. It is **not** a read-only command. `scripts/colors-rebuild-preflight.php` checks counts/engines/HCL/cluster category sizes without domain writes. `scripts/colors-refresh-mv.php --confirm-mv-refresh` refreshes only category MV, not clusters/swatches. `scripts/swatches-refresh-mv.php --confirm-mv-refresh` invokes the swatch repository. These are implemented CLI interfaces, not evidence that cron runs them; none was executed for this audit.

## 5. Database dependencies and materialized-view contract

| Source | Purpose and current use |
| --- | --- |
| `colors` | Authoritative catalog measurements/identity, flags, Lab/HCL/RGB, brand/code/name. COLORS reads original precision for classification and hex matching. Catalog writers remain shared/transitional. |
| `category_definitions`, `color_category`, `hue_display` | Rule definitions, raw many-to-many assignments, display/order lookups. COLORS writes assignments; definitions/display CRUD remain outside the umbrella. |
| `mv_color_categories` | Materialized table with `color_id,hue_cats,neutral_cats,lightness_cats,chroma_cats`. Rebuilt from raw assignments plus definitions; full-detail category overrides and cluster aggregation use it. Distinct from swatch snapshot. |
| `clusters` | Existing cluster identities and four category-summary fields. COLORS updates only category summaries; shared matching/reclustering code retains broader responsibility. |
| `swatch_view` | Expensive/live view used to build the snapshot and still used for many result hydrations. Archived definition joins `colors`, `company`, `category_definitions`, excludes `is_inactive != 0`, and reads legacy category caches on `colors`. That archive is not proof of the current deployed view definition. |
| `mv_swatch_view` | Existing **physical InnoDB snapshot table**, despite its name. Built on demand from `swatch_view` plus `colors.is_inactive`. Current fuzzy matching reads it without joining base catalog/brand/category tables. |
| `swatch_enriched` | Separate legacy matching source used in `api/functions/colorMatch.php` and `api/match-controller.php`; callers require Lab fields. No authoritative current DDL was found. |
| `vw_gallery` | GALLERY-owned composition source used by migrated stored queries. A local fixture models it from `mv_swatch_view` plus photo associations; production view SQL was not inspected. Its eligibility, crops and art/photo rendering do not belong to COLORS. |

**Snapshot projection, verified from `PdoMaterializedSwatchRepository::COLUMNS`:** `id,name,code,brand,chip_num,r,g,b,hex6,hex,hcl_l,hcl_c,hcl_h,brand_name,hue_cats,hue_cat_order,neutral_cats,is_stain,cluster_id,light_cat_id,light_cat_name,light_cat_order,int_only`, plus actual stored `colors.is_inactive`. It does **not** project Lab, LRV, full detail/descriptions or all usage flags. The builder clones existing columns/indexes; this projection is not a complete production `SHOW CREATE TABLE` or index inventory.

Refresh acquires a database-scoped advisory lock, clones the existing snapshot into a random staging table, adds `is_inactive` to that replacement if missing, populates and checks counts inside a transaction, commits, then atomically renames staging/live/backup. Empty source is refused. Prepublication failures roll back/clean staging; backup-cleanup failure after publication reports the retained backup name. The initial table must already exist: this code is not an initial schema creator. Actual storage engine/indexes, live view definitions and deployed refresh dates remain unverified.

**Freshness limits:** Snapshot reads are intentionally as-of-last-refresh. New source writes do not appear automatically. The builder copies only rows exposed by `swatch_view`; adding `is_inactive` to the snapshot does not guarantee that inactive catalog records are present. Admin/inactive-record retrieval must be verified separately. Refresh cannot repair a stale `swatch_view` definition: verify whether the deployed view reads canonical `mv_color_categories` or old `colors` caches before asserting category consistency. The only located archived `swatch_view` DDL is `database/archive/2026_10_10_baseline/2026_02_05_005_add_int_only_to_swatch_view.sql`.

## 6. Cross-department relationships

* **GALLERY — implemented:** `app/GALLERY/Endpoints/GalleryEndpoint.php` uses COLORS' Swatch DTO. Both Gallery endpoints execute configured `sql_queries` and compose results/photos themselves. Filter helper understands `mv_swatch_view`/`vw_gallery`. COLORS does not own query-page layouts, editorial inserts or photo eligibility. Executed source depends on stored query SQL, not just route name; no live query-source inventory was performed.
* **PALETTES — implemented:** The departmental `PdoSavedPaletteRepository::getMembersForPalette()` enriches members through `mv_swatch_view` with `is_inactive=0` in its LEFT JOIN; remaining editor/PV/inventory/shared enrichment and family filtering still use `swatch_view`. `SavedPaletteManager` supplies ARTWORK paint approximations. This does not route through a COLORS retrieval service today. Read-only joins are allowed by SystemManifest; repeated base-swatch projections are consolidation candidates, not automatic ownership violations. PALETTES must retain member order/roles, saved identities and private/inactive access policy.
* **PROJECTS — implemented:** Project palette/color-plan repositories join `swatch_view` for domain-owned outputs and validate catalog IDs against `colors`. Preserve those relationships/access rules; do not transfer project records to COLORS.
* **ARTWORK — implemented:** `ArtworkPageService::get()` obtains its saved paint palette through PALETTES' `SavedPaletteManager`, not direct COLORS SQL. Shared palette-bar Context and artwork's saved paint palette are independent. ARTWORK owns interpretation/metadata, not catalog colors or matching rules.
* **SEARCH — unresolved boundary:** No department/manifest exists here. Frontend search and `api/v2/fuzzy-search.php`, advanced search and matching span GALLERY, shared code and COLORS. Recommended catalog-search ownership is COLORS, with GALLERY retaining composition; the owner must approve any broader SEARCH boundary. Do not create a department from this recommendation.
* **Shared infrastructure — implemented:** PDO/autoload, SYSTEM admin authentication, `App\Lib\ColorCompute`/`ColorDelta`, shared `App\Repos\PdoColorDetailRepository`. Legacy matching also uses `MatchingService`, `FindBestPerBrand`, `ScoreCandidates` and cluster repositories outside COLORS. These are not yet COLORS service interfaces.

## 7. Swatch operation audit — facts versus proposed ownership

Proposed owners below require review. “MV candidate” means display hydration may be suitable **after** field, freshness and visibility checks; it does not authorize a blanket table-name replacement.

| Swatch operation | Current owner | SQL source | Proposed owner | Concern |
| --- | --- | --- | --- | --- |
| Single shared-bar lookup | `api/get-swatch.php`; `src/helpers/swatchHelper.js` → existing AppStateContext | Direct `SELECT * FROM swatch_view` | COLORS retrieval; browser Context stays frontend | SQL in API doorbell; duplicated ID lookup; MV candidate. Context now reuses complete objects, but genuine lookups still use this source. |
| Match-page source lookup | `api/get-swatch-by-id.php`; MatchResultsPage | Direct `swatch_view` | COLORS retrieval | Duplicates single lookup; different status/error contract, exposes exception text; MV candidate. |
| Batch lookup | `api/get-swatch-batch.php` | Direct `swatch_view` | COLORS retrieval | Another hydration implementation. No production caller found by repository search; do not delete without checking external consumers. |
| Fuzzy name/code/hex-prefix lookup | `api/v2/fuzzy-search.php` → `app/repos/PdoSwatchRepository::fuzzySearchByNameCode()`; FuzzySearchColorSelect | `mv_swatch_view`, `is_inactive=0` | COLORS catalog search, pending boundary decision | Already uses snapshot, but repository is shared and doorbell handles orchestration plus `search_log` SQL. Errors can be HTTP 200 empty results with `_err`. |
| Alternate fuzzy substring lookup | `api/search-colors-fuzzy.php`; MobileSearchPage source | Direct `swatch_view` plus `search_log` | COLORS catalog search | Duplicate algorithm/response and uncapped DB read before truncation. MobileSearchPage has no Router reference found; runtime/external use unverified. |
| Exact/near hex search | COLORS `PdoHexSearchRepository` / `HexSearchService` | Candidate precision from `colors`; hydration `swatch_view` | COLORS (already implemented) | Hydration is MV candidate; candidate Lab is absent from snapshot and cannot be switched blindly. |
| Full detail | COLORS detail repository → shared detail repository | `colors`, `company`, definitions, raw assignments; overlay `mv_color_categories` | COLORS detail | Real transitional dependency; intentionally not a display-MV query. Legacy description/category-ID fields can differ from overridden category labels. |
| Cross-brand translation/matching | `api/v2/translate.php`, `closest-by-brand.php`, shared matching services/repos | `colors`/cluster tables for precision; `PdoSwatchRepository::getByIds()` uses `swatch_view`; v1 matching uses `swatch_enriched` | COLORS catalog matching proposed; palette workflow remains PALETTES | Shared algorithms and API business logic overlap. MV hydration may help, but Lab/twin/near-white/brand semantics must survive. `closest-by-brand` has a verified dev-tool caller, not a proven current production UI caller. |
| Saved palette/editor/PV member enrichment | PALETTES repos; shared saved-palette repo still used by `api/v2/palette-viewer.php` | Departmental saved-member read uses `mv_swatch_view`; other joins use `swatch_view` | PALETTES composition; COLORS reusable swatch projection proposed | Legitimate read-only cross-domain joins. Saved-member enrichment now avoids the live-view cost; other duplicated projections remain. Preserve missing/inactive member and access semantics and explicit snapshot-refresh behavior. |
| Project color-plan/spec enrichment | PROJECTS repos; `api/v2/project-painter-specs.php` | `swatch_view` joins | PROJECTS composition; COLORS reusable projection proposed | Not project ownership transfer; API SQL and repeated hydration need separate review. |
| HCL/advanced/similar/opposite searches | `get-colors-by-hcl.php`, advanced-search APIs, similar/opposite-hue APIs | `swatch_view`, and `colors` for measurements/flags | COLORS color selection proposed; GALLERY composition retained | Some display selections bypass snapshot; advanced searches require fields outside its projection. HCL endpoint has no production caller found; v2 advanced search and similar/opposite actions have frontend callers. |
| Gallery browsing | GALLERY endpoints, `GalleryFilterHelper`, configured `sql_queries` | Stored SQL may use `vw_gallery`, `mv_swatch_view` or other sources | GALLERY composition, using COLORS DTO/capabilities | SQL remains in endpoints (layering debt). Joins/DTO reads are not themselves violations; cannot certify every query uses snapshot without live stored SQL. |
| Catalog save/delete and category writes | `app/services/ColorSaveService`, shared color/category repos, `api/v2/admin/color-save.php`, older APIs/scripts | Writes to `colors`, assignments, definitions/caches; save hydrates `swatch_view` | COLORS catalog lifecycle proposed | Domain logic outside umbrella; snapshot deliberately stale until explicit refresh. Deletion dependency checks and competing category writers require review; no automatic refresh added by this manifest. |

Other inspected duplicates include `api/get-palette-details.php` (PaletteInspector caller), `api/palette-get.php` (caller not established), `api/cluster-find.php` (caller not established), and friend/neutral selection helpers. Existing files are not declared obsolete merely from sparse caller evidence. Legacy recipe helpers are not PUB/CHEF rendering; no rendering retirement is authorized here.

## 8. Architectural and security rules

Apply the root constitution: thin `api/` doorbells; HTTP parsing/status in departmental endpoints; workflow/validation in owning services/managers; SQL in repositories; shared supplied PDO with explicit transaction ownership. Direct PHP calls are preferred over sibling HTTP calls. Read-only cross-domain joins remain permitted.

**Verified exceptions:** `CategoryRecalcManager::fetchStoredForIds()` contains SQL outside a repository. GALLERY endpoints also contain SQL; their existence does not justify moving Gallery into COLORS. Fuzzy/translation/advanced/legacy APIs contain business logic and SQL. Shared catalog save/retrieval/classification remains scattered. COLORS detail deliberately wraps a shared repository. No COLORS-owned fuzzy/batch/single-swatch retrieval endpoint exists beyond the exact five doorbells inventoried above.

**Conflicting writers:** `api/assign-categories.php` calls populate/finalize scripts and updates cluster caches; an older `CategoryRecalcButton.jsx` references it, but current CategoryEditPage imports `RecalcCatsButton.jsx` instead. Shared `CategoriesService`/`PdoCategoryRepository` still implement another classifier/cache writer, with test callers found. External/maintenance use remains unverified. The current ColorForm exposes a no-op `delete` imperative method; the existing `api/delete-color.php` is not proven to be called by that toolbar. Likewise, `src/data/saveColor.js` targets `api/save-color.php`, which actually edits `color_search_presets`; no current importing caller was found. Do not confuse either leftover helper with the verified ColorForm → v2 admin color-save path. Manager comments claiming rebuild is CLI-only/not wired are stale: the current admin endpoint demonstrably invokes it. Its advisory lock does not serialize those older writers or arbitrary catalog edits. CLI engine checks are not repeated by the web endpoint/manager; real engine constraints need verification before treating rollback guarantees as unconditional.

Generic public error handling in current COLORS endpoints avoids exposing SQL exceptions. This is not true of all legacy APIs: `get-swatch-by-id.php` and several older adapters expose exception details or return success statuses for failure. Preserve access boundaries and response compatibility in any approved cleanup. Public detail currently has no active-only filter in its base `colors` query; fuzzy/exact-hex selection explicitly excludes inactive rows. A future common repository must not silently unify these distinct policies.

## 9. Verification and limits

This assignment performed source/file/caller and signature cross-checks, schema-projection comparison, Markdown validation and working-tree scope verification. No PHP endpoint, refresh CLI, production query, schema change or application test was executed. No application build is needed for this document-only change.

Existing coverage inspected (not rerun): `api/tests/HexSearchTest.php` and `HexSearchFixture.php` cover validation, exact-first/twin/all-brand ranking, inactive/no-Lab behavior and reference HCL using SQLite; `FuzzyMaterializedSwatchTest.php` deliberately has no catalog/brand/category tables and exercises MV-only reads, inactive/null-flag exclusion, codes/hex/order/caps; `MaterializedSwatchRefreshTest.php` uses an isolated PDO fixture to cover staging/swap, lock/insert/count/rename failures, category commit ordering, rollback and refresh warnings. `scripts/test-swatch-refresh-controls.mjs` covers the explicit UI control. Shared color-save, matching, category and swatch-contract tests exist, but some use a supplied database context and must be reviewed before running.

Unverified: live view DDL/indexes/FKs/engines, snapshot freshness, current stored Gallery SQL, external/cron clients, deployed versions, and performance of replacement queries. Fixture DDL is evidence of tests, not production schema. Missing PALETTES/SEARCH manifests and catalog/search/matching ownership conflicts require owner review.

## 10. Future Improvements

Proposals only, prioritized by risk/value; do not implement or resolve these decisions under this manifest.

| Priority | Problem / affected components | Proposed ownership and outcome | Dependencies / risks / verification |
| --- | --- | --- | --- |
| 1 | Catalog writes, deletes, category writers and snapshot freshness are split across shared services, APIs, legacy scripts and COLORS. | Owner-approved COLORS catalog lifecycle/classification authority; retain explicit refresh UX and domain access rules. | Inventory maintenance/external users and deletion references; verify live view DDL/engines; compare category labels/descriptions, lock concurrency and rollback. Do not silently retire callers or add automatic refresh. |
| 1 | No single implemented display-swatch retrieval contract; root ID/batch APIs and shared repository duplicate work. | Propose COLORS-owned retrieval behind existing URLs, using existing snapshot where projection/policy permits. | Compare every response field, precision, inactive behavior, ordering and error status; measure plans/latency and test shared-bar restoration, matching and palette consumers. No new endpoint is assumed necessary. |
| 2 | Fuzzy search, matching, translation and advanced search overlap outside departments. | Propose COLORS catalog-search/matching owner; keep PALETTES workflow and GALLERY composition separate. Owner decides any SEARCH boundary. | Preserve name modes, code matching, reference hex, all-brand/twin/near-white semantics and Lab precision; reuse current algorithms after contract tests, not a parallel engine. |
| 2 | Detail repository wraps shared enrichment; cached description/ID fields may disagree with category-MV labels. | Complete COLORS-owned full detail after schema/consumer review; preserve lossless precision. | Verify category descriptions/IDs, `cats[]`, inactive lookup, missing-company behavior and existing page/edit contracts. Do not substitute display DTO/MV for full detail. |
| 2 | PALETTES/PROJECTS duplicate swatch enrichment; Gallery stored SQL source varies. | Keep domain joins/composition with their owners; approve a reusable COLORS projection where useful. | Read-only joins are allowed; benchmark before forcing per-row service calls. Verify saved/private/inactive content, PV/project documents and art/picture swatches. |
| 3 | Manager SQL, thick older doorbells, misleading comments and unused-looking paths obscure contracts. | Owning departments retain logic; move SQL behind their repositories only after approval; document or retire proven-unused adapters. | Preserve public URLs and error contracts; trace external/CLI/test callers before deletion. Keep PUB/CHEF and unrelated legacy-render retirement out of scope. |

## 11. Completed Improvements

### Materialized saved-palette member retrieval — 2026-11-10 (local only)

The approved Artwork performance fix changes the existing PALETTES member-enrichment query to `mv_swatch_view`, with active display-row filtering in the LEFT JOIN. It preserves the PALETTES response aliases, original HCL precision, membership, role/note fields and order; no COLORS API, repository or ownership transfer was introduced. Other swatch-retrieval consolidation proposals remain unimplemented.

Isolated PHP tests cover four palette types, missing/inactive display rows, precise fields and actual Artwork composition/visibility. A read-only live comparison for palette #163 returned identical rows: original query 1818.8 ms, replacement 0.5 ms on that run. This benchmark did not deploy code or certify future snapshot freshness. The existing manual swatch refresh policy is unchanged.
