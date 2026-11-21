# ARTICLES publication and listing implementation

Implemented locally; no deployment, production data changes, or schema changes.

## Architecture and behavior

- ARTICLES owns the single transactional, repeat-safe ArticlePublicationService. First publication gets a UTC timestamp; edits, unpublish, repeat calls and republish retain it. Ordinary saves cannot change publication status or dates.
- AdminArticlesPage explicitly publishes the saved version. This is the authenticated editor's release approval. Unsaved form content is retained. PUB delegates to the same service after locking and verifying its existing approved, created asset and matching article receipt. PUB approval and article publication are separate states; no fake shipment or media package is created.
- Initial `/articles/{id}` HTML uses article-specific escaped metadata and the existing React shell. Anonymous draft/missing pages return 404/noindex; authenticated previews are noindex. Client navigation receives the same metadata projection. Existing article body layouts and CTA actions are preserved.
- Published IDs feed the existing sitemap. Stale ANA article-detail registrations cannot reintroduce drafts. Featured cards fetch live state; frozen static featured cards/payloads are no longer generated. PLAYLISTS, POD and ARTICLES reuse ArticleLinkService for public article CTA metadata and eligibility.
- ArticleByline uses the retained publication date. ANA tracks only successfully loaded published articles outside preview mode, retaining existing internal-device/admin policy.
- Reviewed the other session's Articles index: its published-only service was sound, but the public doorbell had overwritten the admin path and used incorrect relative bootstrap paths. Public `/api/v2/articles/list.php` and authenticated admin listing now have separate thin doorbells and ARTICLES endpoint adapters. The public service reuses the existing ARTICLES repository and PHOTOS bulk lookup/delivery interfaces. `/articles` is registered and tested at desktop, tablet and phone sizes.

## Validation

- 41 isolated SQLite checks: transitions, repeated calls, original-date preservation, ordinary saves, rollback/coordinator ownership, PUB approval/stale receipts, public/private retrieval, metadata escaping, CTA visibility, featured selection, listing, and sitemap.
- Local HTTP checks using a temporary database and real SYSTEM sessions: anonymous publication denied, authenticated preview allowed/noindex, draft HTML returns 404, initial published HTML metadata, repeat transitions, original dates, public-only listing and authenticated draft listing.
- Browser checks with mocked APIs: desktop/tablet/phone list-to-article navigation, editorial content/byline, route metadata, public ANA recording, failed-load/preview exclusions; admin Save excludes status/date and explicit Publish/Unpublish calls the publication endpoint.
- Existing tests pass: ArticleCtaTest (33), ArtworkCtaTest (24), RexSitemapTest, ANARecordingPolicyTest (27), ANAInternalDeviceTest (13), ArtworkPlaylistTest (10), and test-articles-department.php (10 endpoint reference sets).
- PHP syntax checks pass for changed/new PHP files. Frontend build and git diff --check pass.

## Remaining verification and deployment considerations

- MySQL row-lock concurrency and actual Apache rewrite handling were not exercised against production; validate on staging. No production writes or deployment occurred.
- Deploy backend and frontend together, including `.htaccess`, `article.php`, and the normal generated app shell. Regenerate the homepage/public payload through the existing deployment build so previously frozen featured cards are removed. No SQL migration is needed.
- Historical original publication dates already erased cannot be reconstructed automatically.
- PUB approval is the existing asset-level flag, not a new versioned approval of each later editorial change.
- Social images use the established PHOTOS signing configuration. Missing authorized delivery URLs are omitted rather than exposing a raw private original.
- POD has no department manifest in this checkout; its existing article CTA consumer now delegates to ARTICLES, documented in the ARTICLES contract.
- Existing unrelated artwork/artist_last edits were preserved and are not part of this publication change list. Existing AdminArticlesPage and Router edits were preserved while integrating this work.

## Files added or modified for this task

- `.htaccess`
- `api/tests/ArticlePublicationHttpFixture.php`
- `api/tests/ArticlePublicationTest.php`
- `api/tools/prebuild-front-page.php`
- `api/v2/admin/articles/list.php`
- `api/v2/admin/articles/publication.php`
- `api/v2/admin/pub/article-publication.php`
- `api/v2/articles/featured.php`
- `api/v2/articles/list.php`
- `app/ANA/DeptManifest.md`
- `app/ARTICLES/DeptManifest.md`
- `app/ARTICLES/Endpoints/AdminArticleListEndpoint.php`
- `app/ARTICLES/Endpoints/ArticleListEndpoint.php`
- `app/ARTICLES/Endpoints/ArticlePageEndpoint.php`
- `app/ARTICLES/Endpoints/ArticlePublicationEndpoint.php`
- `app/ARTICLES/Repos/PdoArticleRepository.php`
- `app/ARTICLES/Services/ArticleHtmlService.php`
- `app/ARTICLES/Services/ArticleLinkService.php`
- `app/ARTICLES/Services/ArticleListingService.php`
- `app/ARTICLES/Services/ArticlePublicationService.php`
- `app/ARTICLES/Services/ArticleService.php`
- `app/ARTICLES/Services/PublicArticleService.php`
- `app/PLAYLISTS/DeptManifest.md`
- `app/PLAYLISTS/Services/PlaylistExperienceService.php`
- `app/POD/Managers/PODManager.php`
- `app/PUB/Contracts/PubContract.php`
- `app/PUB/DeptManifest.md`
- `app/PUB/Endpoints/ArticlePublicationEndpoint.php`
- `app/PUB/Repos/PdoPubAssetRepository.php`
- `app/PUB/Services/ArticlePublicationManager.php`
- `app/REX/DeptManifest.md`
- `app/REX/Services/RexSitemapService.php`
- `article.php`
- `scripts/prebuild-homepage-html.mjs`
- `scripts/test-article-publication-browser.mjs`
- `scripts/test-article-publication-http.mjs`
- `scripts/test-articles-department.php`
- `src/Router.jsx`
- `src/components/GalleryItems/FeaturedArticleItem.jsx`
- `src/pages/AdminArticlesPage/index.jsx`
- `src/pages/AdminPubPage/PubAssetCopyEditor.jsx`
- `src/pages/AdminPubPage/PubAssetsTable.jsx`
- `src/pages/ArticlePage/ArticleByline.jsx`
- `src/pages/ArticlePage/ArticleContainer.css`
- `src/pages/ArticlePage/index.jsx`
- `src/pages/ArticlesPage/ArticlesPage.css`
- `src/pages/ArticlesPage/index.jsx`
- `docs/ARTICLES-publication-report.md` (this report)
