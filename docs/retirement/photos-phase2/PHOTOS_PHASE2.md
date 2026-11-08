# PHOTOS Phase 2 — final code retirement and deployment review

**Later editor fix:** the prepared archive predates the correction for the false Palette Viewer “Saved Palette not found” error. The deployment manifest is marked superseded. Preserve the archive as a snapshot; regenerate the release before deployment. The Phase 2 verification below describes that earlier snapshot.

Verification date: **2026-11-08, Pacific Time**. Production snapshot timestamps are recorded in the attached JSON. **This session did not deploy, delete Palette 133, modify production business records, execute destructive SQL, or remove production files.** Crop support and Department Manifests were not started. The root SystemManifest is absent; the existing `src/SystemManifest.md` was followed and left unchanged. No department ownership was reassigned.

## Readiness

**The code-only Phase 2 changes are validated; the full bundle is prepared for review, not unconditional deployment.** There is no remaining metadata migration blocker for #133/#154. A concurrent, non-PHOTOS Palette Viewer search edit is included in the working-tree build and needs its owner's review before that full bundle ships. Database structure retirement and physical-file disposal remain separately approved future actions. Real Apache/live smoke verification must follow an approved deployment; local checks cannot establish that unshipped code is live.

The whole repository is not lint/syntax clean: the broad PHP scan found one unchanged, pre-existing truncated color maintenance script, and frontend lint has pre-existing failures. Neither is introduced by this release. Do not use broad mirroring scripts to package unrelated files.

## Palette decisions and current references

- Palette 154: owner confirmed its current presentation is correct and obsolete Concept content row 131 needs no migration. Current Public viewer 74 and its current photograph associations remain intact. No Concept copy, REX or playlist was created or changed.
- Adams Makeover (#43) cover item 541: `palette_hash=NULL`, `saved_palette_id=NULL`; the user's clearing was saved. The obsolete `saved_palette_set_id=157` remains stored. Current playlist reads publish a NULL compatibility alias and never use that old column. It is not a live palette association and will not be rewritten by this task.
- Adams remains `is_active=0`, `is_public=0`, `indexable=1`, `is_retired=0`. Palette 135 remains on its intended ColorFixed slide; the separate 136 option is unchanged.
- There are **zero operational playlist references** to #133 by hash or current saved palette ID, zero direct Photo Library/Project/Project Photo/Article references, and no hash or explicit palette-ID matches in 98 other current tables searched. History, logs and migration maps were not treated as operational content or deleted.
- #133 itself remains stored. Its inactive current viewer 56 still references it; that viewer retains Before photo link 181 to Photo Library 664 and unlocked active REX 64. Therefore #133 is **not completely unreferenced**, and this report does not authorize deleting it. Those current records use current tables, so preserving them does not require the obsolete viewer-content row 62 or Palette Sets.
- The owner's decision that Old California is no longer needed removes the need to copy its obsolete presentation. Current inactive viewer state is preserved and now cannot be bypassed by the saved-hash fallback.

## Application systems eliminated

`removed-code.json` lists **77 exact removed paths**, including the shared viewer stylesheet's old name. Categories:

- Mask editors, overlay/blend tools/history, alpha conversion/export tools, mask training endpoints/services and the old prepared-photo uploader/admin pages.
- Applied Palette entity, legacy photo controller/repository/uploader/renderer, render/debug/search endpoints and old share-card generator.
- Palette Set admin editor and mutations, set-specific metadata/photo fallbacks, deprecated repair/backfill tools, associated routes/imports, obsolete CLI and renderer-specific tests.
- Final scan additionally found `api/tools/tag-from-category.php`, a CLI-only writer to `photos`/`photos_tags`. Its only source caller was its own invocation comment; it has been removed locally and added to the 410 rules. No replacement was created.

The frontend shares the existing viewer CSS under `paletteviewer.css`; bytes match the prior `appliedpaletteviewer.css`. Current `apv-*` CSS class names are presentation identifiers, not an Applied Palette system. No current viewer styling was discarded.

## Ownership and active dependencies retained

- PHOTOS owns current direct Main association operations, upload orchestration, duplicate/deletion safety, usage checks and file disposal. Preserved public URLs delegate through the existing PHOTOS endpoints/services. `PhotoLibraryUploadEndpoint`, `PhotoPaletteEndpoint`, `PhotoLibraryUploadService`, and `PhotoPaletteAssociationService` were new during the original Phase 2 implementation and are included in the deployment inventory; they are now tracked.
- PALETTES still owns saved palette/member data; PHOTOS owns `photo_library.palette_id`. Current Full/Before/Zoom presentation remains in Palette Viewers and their photo records. Current Projects keep their own photo selection/role relationships. Independent Save as New/hash behavior and Gallery flags remain unchanged.
- Playlist palette identity uses current palette IDs/hashes. Photo Library supplies image/alt metadata. Current Playlist Sets and Playlist Instance Sets are unrelated to retired Palette Sets and remain supported.
- PUB/CHEF Pinterest/YouTube recipe classes and media workers are retained. Old `render_projects`, `render_project_masks`, `render_project_roles` belong to the retired image renderer. Current `pub_render_jobs` history is conservatively retained/protected; no PUB table is proposed for deletion. Current thumbnails, image delivery/versioning, Asset Library, Articles, SAS/POD/REX and artwork photo types/records are retained.
- Empty/null `ap_id` and `saved_palette_set_id` DTO/response aliases remain where active clients/contracts require them; they are not lookups into retired systems. Historical source labels such as `photo_base` or `applied_palette` on retained Photo Library records are provenance, not permission to remove their images.
- PHOTOS retains **read-only safety interlocks** for old `saved_palette_set_photos`/`saved_palette_photos` rows until separately approved database retirement. Deletion/merge is refused rather than silently cascading through those rows. With those tables absent, the same current lifecycle tests pass.

## Final safety fixes in this session

1. `app/services/PaletteViewerService.php`: a matching inactive current viewer can no longer be bypassed through a generated saved-palette fallback. A second active matching viewer still works; an absent Concept viewer still uses the existing current-data fallback. Regression added to `api/tests/PhotoRetirementTest.php`.
2. `api/tools/tag-from-category.php`: removed the remaining exclusive legacy writer. `.htaccess` and the legacy dependency test cover its retired URL.
3. `scripts/test-photo-retirement.mjs`: expanded scanning beyond `api/v2` to active API/CLI/root PHP paths, excluding third-party/vendor code and disposable fixtures. It checks the old mask-training tables and retains CHEF/current thumbnail endpoints.
4. Removed five introduced unused bindings/functions left by old renderer/set UI removal and one introduced hook warning. Fixed Phase 2 whitespace only, including extra EOF whitespace in `assetImage.js`. Existing independent AdminSearchField/slide-label edits were preserved.

## Verification

| Check | Result |
|---|---|
| `php scripts/test-photo-lifecycle.php` | **70 passed, 0 failed**, disposable SQLite/generated-image fixtures only |
| `node scripts/test-photo-lifecycle-http.mjs` | Passed real preserved API doorbells against fixture DB/auth; JSON, methods, usage/delete/bulk/merge/dry-run/error contracts |
| `node scripts/test-art-repertoire.mjs` | Passed multipart upload/storage/read/filter, types, direct Main link/unlink/replacement conflicts, unchanged Gallery defaults, Save as New, browser at 1280/390px, with retired tables absent |
| `node scripts/test-photo-retirement.mjs` | Passed expanded runtime dependency scan, old-route guards, current photo references/versioning and CHEF/thumbnail preservation |
| `node scripts/test-sas-playlists.mjs` | Passed 1280/390/320px catalogue, experiences, reuse/create, Quick Play/no dispatch, copy/QR/Share/Text/retry; injected 503 errors are expected fixture cases |
| `node scripts/test-rex-loader.mjs` | Result recorded in verification-results.json; local failure/missing/retry fixture, not a production browser visit |
| `npm run build` | Passed final production frontend build |
| `npm run build:sas` | Passed final SAS build, after main build |
| Scoped PHP syntax | **70 deployment/test PHP files passed** |
| Broad PHP syntax | 1,037 checked; one pre-existing failure: `api/update-hcl-rgb-lookup.php` (unclosed array, unchanged from both HEAD and initial Phase 2 backup). No active caller found; excluded from deployment payload |
| Frontend lint comparison | 27 files checked; **0 introduced errors/warnings** against the initial Phase 2 backup; 27 current errors are pre-existing. Exact rule/message details in `lint-results.json` |
| Review artifacts | Valid JSON; 77 removed paths absent locally; 14 proposed table drops in FK-safe child-first order; no active destructive SQL; current artwork/table protections checked |
| Whitespace | `git diff --check` passes |

The PHP fixtures cover unused/in-use deletion, mixed bulk outcomes, shared files/aliases, artwork conflicts/protection, project/PV/playlist/artwork merge references, association conflicts, rollback/filesystem failure, current set raw URLs, retained PUB inputs, memberships, viewer/REX and current project selections. No destructive tests ran against production.

No full PUB video export, actual production replacement/deletion, real SYSTEM login cycle or post-deployment Apache response test was performed. Such claims are deliberately not inferred from fixture tests. Current recipe source code and production tables remain untouched.

## Database retirement proposal and FK analysis

`cleanup-proposal.sql` contains read-only preflights and **commented, unexecuted DDL** for fourteen legacy tables and two mask views. Exact current definitions/counts, all FKs, view definitions, and account cron evidence are in `production-preflight.json`. The late-discovered `render_project_roles` child must be dropped before `render_projects`; `tester_colors` (six rows) is used only by the removed mask-training service.

All incoming FKs to proposed retired tables are internal to that retired set. Retired tables also have outgoing FKs to protected current parents (`photo_library`, `saved_palettes`, `master_roles`); dropping the child does not authorize deleting those parents. Preserve `photos_artwork` and its direct Photo Library relationship. Preserve current Gallery views/materialized views and all PUB tables.

Five old set rows have no Photo Library ID; seven old Main associations disagree with authoritative current direct links. They are reported for archive/review, not migrated or used to overwrite current associations. Only old content rows 62/#133 and 131/#154 lack active matching current viewers; both are owner-resolved obsolete presentations. If those preflight results change before approval, stop and review.

Optional later retirement of `playlist_items.ap_id` and `saved_palette_set_id` is separately listed. **Keep `playlist_items.saved_palette_id`**, which current code uses. No schema migration is required for this code-only release.

## Physical files

`file-retirement-inventory.json` preserves the November 7 inventory: 246 old-provenance paths, 237 protected/currently referenced and nine already absent. **Zero existing image paths are approved for removal.** It is a historical snapshot, not a deletion manifest; re-scan current data/aliases/PUB ingredients before any future proposal. No entire old photograph directory is safe to remove. Do not confuse old variants with current thumbnail caches or media outputs.

## Deployment inventory and remaining issues

- `deployment-manifest.json` lists exact document-root destinations, payload hashes/sizes, files differing from the live read-only hash snapshot, all removed code paths, current untracked files and review-only files. `deployment-source-files.json` identifies source inputs and Phase 1 prerequisites.
- The full frontend bundle necessarily includes earlier authorized uncommitted AdminSearchField/slide-label work. `slideTypes.js` and the three related untracked browser-test scripts are explicitly disclosed; the tests are review files, not web endpoints. Client/Property search backend prerequisites are included. Do not ship a partial source tree or mislabel this bundle as containing only PHOTOS UI changes.
- During this session an additional `src/pages/AdminPaletteViewersPage/index.jsx` search change appeared. It was not authored or modified by this cleanup. The build contains its new search text, but its new viewer/palette-ID matching behavior was not acceptance-tested here. Review that independent change before deploying the full bundle. The unrelated untracked `src/pages/AdminPlaylistsPage.zip` archive is excluded and is not required to rebuild.
- Live file audit found three retired code files still present: `api/tools/tag-from-category.php` and the two old `src/pages/AdminPalettePhotosPage` files. They are on the exact future code-removal list. No production files were deleted.
- Existing upload/replacement paths, photo/palette associations, active/public/indexable flags, current PUB renderer, analytics and authentication behavior were not repurposed. No crop implementation, schema changes or new manifests were undertaken.
- **Separate PALETTES deletion-safety issue remains:** `PaletteManager::deletePaletteCombo()` permanently deletes a palette and its viewers/REX after confirmation and locked-REX gating, but does not reject playlist ID/hash references. This can leave dangling references, including in inactive content. It must get a separate owner-reviewed dependency guard. PHOTOS Phase 2 does not invoke it or delete #133, and this code-only retirement does not depend on fixing it.
- Account-level scheduled targets and database events were inspected read-only. The current `schedule-clock.php` PUB scheduler remains. Host/root/cPanel jobs outside account visibility and external consumers of intentionally retired APIs cannot be exhaustively certified; recheck before destructive DB retirement.

See `ROLLBACK_AND_DEPLOYMENT.md` for staging, approval gates, exact-bundle rollback, database backup/restore requirements and required post-deployment checks. **Do not run the broad `--delete` deploy scripts or execute the commented DDL as part of preparation.**
