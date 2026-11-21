UPDATED: 2026-11-20 18:10:23 PST

# ARTICLES — Department Manifest

## Ownership and scope

`app/ARTICLES/`, namespace `App\ARTICLES`, owns article creation, editing, retrieval, deletion, publication state, section composition/order, article tags and tag assignments. This is the existing implementation relocated with namespaces/imports updated, not a new content system. The owner authorized this department migration on 2026-11-17.

Owned tables: `articles`, `article_sections`, `article_tags`, `article_tag_map`. Existing schema and data are unchanged. Historical definitions are in `database/archive/2026_10_10_baseline/2026_02_15_001_create_articles.sql` and subsequent article alterations; these are not proof of live schema state.

Does not own photos/files/crops, CTA library definitions, playlist playback, REX identities/aliases, SYSTEM authentication, ANA event records, or PUB production. No Article Analyzer, Creator, Package or Dispatch is implemented by this migration.

## Implementation and interfaces

- `Controllers/ArticleController.php`: `ArticleController(ArticleService $service)` delegates `list(array $filters, int $limit, int $offset): array`, `get(int $id): ?array`, `save(array $payload): int`, `listTags(): array`, and `delete(int $id): void`.
- `Services/ArticleService.php`: `ArticleService(PdoArticleRepository $repo)` exposes `listArticles(array $filters, int $limit=100, int $offset=0): array`, `getArticle(int $id): ?array`, `saveArticle(array $payload): int`, `listTags(): array`, and `deleteArticle(int $id): void`. Get adds `sections` and `tags` to the article row, or returns null. Save returns the article ID.
- `Repos/PdoArticleRepository.php`: `PdoArticleRepository(PDO $pdo)` owns existing SQL. Read methods: `getArticleById(int): ?array`, `getArticleBySlug(string): ?array`, `listArticles(array=[], int=100, int=0): array`, `getFeaturedOrLatest(?string=null): ?array`, `listSections(int): array`, `listTags(): array`, `getArticleTags(int): array`. Mutations: `createArticle(array): int`, `updateArticle(int,array): void`, `deleteArticle(int): void`, `setExclusiveFeatured(int): void`, `createSection(int,array): int`, `updateSection(int,array): void`, `deleteSection(int): void`, `moveSectionSortOrdersOutOfTheWay(int): void`, `upsertTag(string,string): int`, `setArticleTags(int,array): void`. Existing transaction helpers: `beginTransaction()`, `commit()`, `rollBack()` (void).

Autoloading uses the existing `App\` root mapping in `api/autoload.php`. No new bootstrap or compatibility aliases are required. Legacy controller/service/repository files were removed, not retained as duplicate implementations.

## Existing behavior and lifecycle

- Save requires nonblank `title` and `slug`, throwing `InvalidArgumentException` otherwise. Positive `id` updates; otherwise creates. Default type is `colorfix`, default status is `draft`. Existing fields include `dek`, `meta_description`, hero IDs, `cta_overrides`, `featured`, and `published_at`.
- Draft/published state is persisted by save. There is no separate publish operation; the existing admin UI supplies publication timestamps. Public get allows unpublished content only for a verified SYSTEM admin session. Repository/service getters themselves are not authorization gates.
- Delete rejects missing or published articles with `InvalidArgumentException`. Repository deletion issues the existing article-row DELETE and relies on existing database relationships for dependent records; it does not delete photo files.
- Listing supports type/status, title-or-slug search, tag IDs/slugs, limit and offset. Tag matches use OR; order is published-at then created-at descending. Featured selection reads only published articles, optionally by type; it falls back to the latest published record. Setting featured clears the flag on other articles globally.
- Save accepts tag IDs or slug/name objects. It upserts supplied tag definitions and replaces article tag assignments. Section synchronization normalizes supplied numeric order to contiguous indexes, preserves input order for ties, updates/creates sections, and removes omitted existing sections. Only this section-sync phase is transactional; the whole save is not atomic.
- Existing section kinds include text, image, image_text, header, list, embed, cta and palette_link. Heading level, body, caption, photo reference and palette reference fields retain existing handling. No new section validation or HTML policy is introduced.
- Duplication remains an admin-frontend operation using get and save; there is no clone API. Its existing payload semantics, including section IDs, are unchanged.

## HTTP boundaries (unchanged URLs/contracts)

Admin endpoints require `api/v2/admin/auth.php` (SYSTEM), then existing API autoload/database bootstrap.

| Endpoint | Contract |
|---|---|
| `/api/v2/admin/articles/list.php` | GET type/status/q/tags/tag_ids/limit/offset; `{ok:true,items:[...]}`. Tags/IDs accept comma or pipe separators. |
| `/api/v2/admin/articles/get.php` | GET positive id; `{ok:true,item:articleWithSectionsAndTags}`. Enriches section assets from PHOTOS. |
| `/api/v2/admin/articles/save.php` | POST JSON article payload; `{ok:true,article_id:id}`. |
| `/api/v2/admin/articles/delete.php` | POST JSON positive id; `{ok:true}`. |
| `/api/v2/admin/articles/tags.php` | GET; `{ok:true,items:[...]}`. |
| `/api/v2/articles/get.php` | GET positive id; `{ok:true,item:{article,tags,hero,sections,ctas}}`. Missing article 404; unpublished/non-admin 403. |
| `/api/v2/articles/featured.php` | GET optional type; `{ok:true,item:{article,hero,hero_mobile}}`; no published match 404. |

Existing method guards, OPTIONS handling, no-store JSON headers, error payloads and exception mapping remain unchanged. Invalid admin save/delete input is 400; database/unhandled errors are 500. Missing admin get is 404. SQL errors propagate through the repository/service to the existing endpoint handlers.

`/api/v2/admin/article-problems.php` is a SYSTEM-authenticated read-only diagnostic for photo references; it does not call these classes and remains unchanged.

Public `/articles/:id`, admin `/admin/articles` and `/admin/articles/:id`, and preview `/articles/{id}?admin=1` are unchanged. The preview query is not an authorization grant. There is no added slug routing. `getArticleBySlug()` remains available but has no located production caller. Article save does not create REX aliases; REX owns its existing alias infrastructure and no alias behavior changes here.

## Dependencies and callers

- The article editor selects reusable CTA Pages from `/api/v2/admin/cta-groups/list.php`, storing the chosen group ID as `_cta_group_id` in existing `cta_overrides` JSON. Positive IDs resolve live through `PdoCtaRepository::getByGroupId()`; zero means None. This selection takes precedence over legacy `_cta_ids`. Automatic removes both selection keys and retains existing article-default behavior. Untouched legacy selections and all per-CTA overrides remain intact; choosing a page replaces only the selection keys. No new database column or CTA table is required.
- Legacy article `playlist_link` assignments containing only `playlist_instance_id` (or `playlistInstanceId`) are resolved through existing `App\Repos\PdoPlaylistInstanceRepository::getById()` to the instance's `playlistId`, then through the same active public REX lookup. Explicit URL and explicit `playlist_id` retain precedence. Missing instances or missing public REX remain disabled; there is no fallback to a guessed source, legacy player URL, or instance experience. This is read-only compatibility for saved CTA configuration; instance records and CTA definitions are not migrated or changed.
- PHOTOS owns Photo Library rows and files. Despite legacy names, `hero_asset_id`, `hero_mobile_asset_id` and section `asset_id` are used as Photo Library IDs by current APIs/UI. Admin get and public get/featured perform existing read-only photo joins. PHOTOS lifecycle code also checks/rewires article photo references in its established coordinated operations.
- CTA configuration stays in article `cta_overrides` JSON (selected `_cta_ids` plus per-CTA overrides). `PublicArticleService::resolveCtas()` preserves explicit selections, including an empty selection. Otherwise, articles linked to a playlist with `editorial_type=artwork` use the existing `artwork-article` CTA group (production ID 12), resolved by key through `PdoCtaRepository`. PLAYLISTS supplies all source associations through `findArticleSources()`; only exactly one association can supply an implicit destination. For destination-free `playlist_link` actions, the active public REX becomes `data.reserved_playlist_url`. Explicit URLs win; explicit playlist IDs resolve independently and never fall back to a different article source. Missing/ambiguous sources or unavailable public REX disable the destination-free slideshow; other CTAs remain available. No definitions, associations, or REX records are written. The existing article page renders playlist CTAs in its hero and all available CTAs after Final Thoughts through shared CTA components/actions. Params retain their JSON-string response representation. CTA definitions and REX identities are not owned here.
- `app/PLAYLISTS/Services/PlaylistExperienceService.php` and `app/POD/Managers/PODManager.php` use the repository for article CTA metadata/links. Only their imports changed.
- `app/ANA/Services/ANAReportService.php`, `app/ANA/Endpoints/ReportEndpoint.php`, legacy `app/Analytics/Services/AnalyticsService.php`, and `api/v2/admin/analytics/counts.php` depend on the repository for article titles. All imports now use ARTICLES. ANA's manifest records the existing dormant ReportEndpoint/bootstrap issue; this migration does not activate or repair it.
- Frontend consumers remain `AdminArticlesPage`, `ArticlePage`, `FeaturedArticleItem`, `ArticleLinkCta`, shared CTA actions and playlist pickers. `api/tools/prebuild-front-page.php` consumes the public featured API. No frontend files changed.

## Transitional exceptions and future improvements (not authorized here)

1. API files still contain response assembly, read-only photo SQL, CTA merging and public-state checks. A later approved move into ARTICLES endpoint/service boundaries should preserve their exact contracts; this migration only moves the three requested classes and updates callers.
2. Whole-save atomicity, duplicate section-ID handling, section ownership validation and tag upsert ID behavior warrant focused compatibility review before any behavioral changes. No fixes are bundled here.
3. The mobile hero is persisted and returned by featured retrieval, but public detail currently only assembles/uses the primary hero. This remains unchanged.
4. Any future cleanup must inventory public/API/analytics/POD/PLAYLISTS/PHOTOS consumers first. Do not add alternate repositories or an article alias system.

## Verification and completed migration

2026-11-17: owner-approved relocation of the three classes and all located PHP imports. No schema/data, frontend, route, REX/alias logic or deployment changes. `scripts/test-articles-department.php` checks actual autoloading and API imports without connecting to application databases. Relevant existing PHOTOS usage/lifecycle tests cover article image dependencies. Validate syntax for every changed PHP file and compare pre/post source after normalizing namespace changes. Production HTTP authentication, live MySQL behavior and rendered UI require a running application and were not certified by this migration.

Validation: 16 migrated/consumer PHP files plus the smoke-test script pass syntax checks. Namespace-normalized pre/post comparison is byte-for-byte equal for all 16 implementation files. All seven article API import sets autoload successfully. The 26 existing PHOTOS replacement/usage/lifecycle tests pass using isolated fixtures. No live database was accessed.
