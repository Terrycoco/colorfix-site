# ARTICLES department migration

Implemented locally; not deployed. No frontend, schema/data, routes, REX/alias behavior or business logic changes.

## Moved (legacy files removed)

- `app/controllers/ArticleController.php` → `app/ARTICLES/Controllers/ArticleController.php`
- `app/services/ArticleService.php` → `app/ARTICLES/Services/ArticleService.php`
- `app/repos/PdoArticleRepository.php` → `app/ARTICLES/Repos/PdoArticleRepository.php`

## Added

- `app/ARTICLES/DeptManifest.md`
- `scripts/test-articles-department.php`

## Modified

- `api/v2/admin/analytics/counts.php` — article import changes only.
- `api/v2/admin/articles/delete.php` — article import changes only.
- `api/v2/admin/articles/get.php` — article import changes only.
- `api/v2/admin/articles/list.php` — article import changes only.
- `api/v2/admin/articles/save.php` — article import changes only.
- `api/v2/admin/articles/tags.php` — article import changes only.
- `api/v2/articles/featured.php` — article import changes only.
- `api/v2/articles/get.php` — article import changes only.
- `app/ANA/Endpoints/ReportEndpoint.php` — article import changes only.
- `app/ANA/Services/ANAReportService.php` — article import changes only.
- `app/Analytics/Services/AnalyticsService.php` — article import changes only.
- `app/PLAYLISTS/Services/PlaylistExperienceService.php` — article import changes only.
- `app/POD/Managers/PODManager.php` — article import changes only.

- `SystemManifest.md` — authorized ARTICLES ownership/directory entry and timestamp.
- `app/ANA/DeptManifest.md` — updated article repository dependency and timestamp.

`api/autoload.php` is unchanged: its existing App namespace root loads the new exact-case paths.

## Department structure

```
app/ARTICLES/
  DeptManifest.md
  Controllers/ArticleController.php
  Services/ArticleService.php
  Repos/PdoArticleRepository.php
```

## Interfaces

Controller: list/get/save/listTags/delete. Service: listArticles/getArticle/saveArticle/listTags/deleteArticle. Repository: existing article, ordered-section and tag reads/writes, featured selection and transaction helpers. Signatures and behavior unchanged. All classes now live under App\ARTICLES. The manifest documents exact methods, HTTP contracts, ownership, callers and lifecycle.

The five admin article endpoints and two public endpoints keep their existing URLs and response shapes. Outside callers in ANA, legacy Analytics, POD and PLAYLISTS now import the migrated repository. No compatibility wrappers or duplicate implementations remain.

## Validation

- All 16 moved/modified implementation PHP files and the new smoke-test script pass PHP syntax checks. Existing harmless `use InvalidArgumentException` warnings in save/delete remain unchanged.
- Normalizing only namespace/import changes produces byte-for-byte equality with all 16 pre-migration implementation files; no queries, business logic or HTTP handling changed.
- `php scripts/test-articles-department.php` passes: three actual class autoloads and construction, required-field validation, all seven article API import sets, report-service parameter types, and absence of legacy classes/files. This verifies API dependencies without executing authenticated HTTP requests or database bootstrap.
- 26 existing PHOTOS replacement/usage/lifecycle tests pass using isolated temporary fixtures, including article reference protection and reassignment.
- Searches found no obsolete article fully-qualified imports in app/api/src/scripts. `git diff --check` passes.

## Limits and preserved risks

No live MySQL or running application was used: end-to-end authenticated admin saves, public draft visibility, publication, featured retrieval, rendering and production bootstrap remain deployment-environment checks. Existing API response assembly, whole-save transaction limitations, duplicate section-ID semantics, and mobile-hero detail behavior were deliberately preserved. The ANA manifest's dormant ReportEndpoint/bootstrap issue remains unchanged.

When deployment is separately authorized, the relocated classes and every updated caller must be released together and the three old files removed. A ZIP alone cannot express those removals. No deployment was performed. The accompanying ZIP contains changed/new source, manifests and smoke test only, with no SQL or frontend build.
