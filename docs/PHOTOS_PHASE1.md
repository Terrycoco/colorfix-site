# PHOTOS Phase 1 — lifecycle ownership and safety

Status: **local implementation; not deployed**. No production records or photographs were deleted, no schema was changed, and no legacy files were removed. Production access was limited to reading schema/reference metadata. Destructive verification used generated PNGs and temporary SQLite databases only.

## Result

PHOTOS now owns Photo Library dependency checks, duplicate candidates, duplicate merging/reassignment, single/inactive deletion, and disposal of managed photo files. Existing API URLs and compatibility class/method names remain available.

- Single and bulk deletion use the same transaction and dependency checks. Inactive consumers are conservatively retained too. Artwork, direct palette associations, projects, viewers, playlists, articles, assets, groups, pending/completed alt-text jobs, compatibility photo rows, and filed PUB inputs are checked.
- Artwork metadata prevents deletion; its cascading FK is never used to silently remove artwork. A sole artwork record transfers during merge; two artwork records cause an explicit conflict.
- Merge updates supported current references, encoded `photo:ID|path` references, cached image paths, and Asset Library photo references. Direct palette/asset associations transfer only when the keeper lacks one. Conflicting palettes, artwork, assets, project/group associations, client contexts, permission statuses, and inactive/retired keepers are rejected. Existing project role/order flags and Gallery flags are preserved. As before, successful merges mark the source inactive and retain its row and bytes.
- Compatibility dedupe operations now merge/mark inactive rather than hard-delete duplicate rows. Their existing `deleted` count remains present and is zero; an additive `retired` count reports retained duplicates. Subsequent calls skip inactive duplicates. Candidate detection and dry runs do not write.
- Physical disposal follows the committed database deletion. It checks shared files and path variants, local symlink aliases, URL/path consumers and production inputs. Only files inside managed `/photos/` storage are eligible. Missing files, protected files, dependency-check failures and filesystem failures produce explicit outcomes. A failed row transaction never removes bytes. No background cleanup is scheduled.
- Palette workflows still own their relationship rows. They commit their unlink/delete transaction before PHOTOS evaluates detached assets. Independently uploaded/Art assets are retained. Protected assets return explanations in additive `photo_cleanup` response data.
- ASSETS delegates Photo Library detachment and disposal of `/photos/` files to PHOTOS. PUB output deletion/reset calls a PHOTOS guard that prevents deleting a file still belonging to Photo Library. Non-photo generated output files remain owned by their existing department.

## Files changed in this phase

Existing unrelated working-tree changes were preserved. This list describes only Phase 1 changes.

| File | Responsibility |
|---|---|
| `app/PHOTOS/Endpoints/PhotoLifecycleEndpoint.php` (new) | HTTP validation, dispatch and status/JSON responses for the four lifecycle endpoints |
| `app/PHOTOS/Repos/PdoPhotoLifecycleRepository.php` (new) | Reference inventory, schema inspection, supplemental usage checks, conflicts, reassignment and shared-file checks |
| `app/PHOTOS/Repos/PdoPhotoLibraryRepository.php` | Compatibility deletion/reassignment methods delegate to PHOTOS lifecycle; usage inventory includes supplemental dependencies |
| `app/PHOTOS/Services/PhotoLifecycleService.php` (new) | Transactional deletion/merge, bulk deletion, detached palette cleanup, post-commit file disposal and cross-department source-file guard |
| `app/PHOTOS/Services/PhotoDuplicateService.php` (new) | Existing candidate grouping and dedupe orchestration, using full dependency checks and safe merges |
| `app/PHOTOS/Services/PhotoLibraryUsageService.php` (new) | Authoritative usage response normalization, moved from the shared service |
| `app/services/PhotoLibraryUsageService.php` | Compatibility class extending the PHOTOS implementation |
| `app/services/PhotoLibraryService.php` | Compatibility dedupe methods and detached-palette cleanup delegate to PHOTOS; registration/upload behavior unchanged |
| `app/services/SavedPaletteService.php` | Palette deletion transaction and delegation of detached photo cleanup; removes its independent file unlinking |
| `app/repos/PdoSavedPaletteRepository.php` | Connection access for the existing palette deletion service's transaction |
| `app/controllers/SavedPaletteController.php` | Returns cleanup outcomes from palette deletion |
| `app/services/AssetLibraryService.php` | Delegates disposal of generated assets stored under `/photos/`; retains other asset storage behavior |
| `app/repos/PdoAssetLibraryRepository.php` | Delegates Photo Library asset detachment and managed photo disposal to PHOTOS |
| `app/PUB/Repos/PdoPubAssetRepository.php` | Small delegation to the PHOTOS source-file guard |
| `app/PUB/Endpoints/PubAssetsEndpoint.php` | Checks that PUB deletion will not remove a Photo Library source file |
| `app/PUB/Services/PubRunService.php` | Same source-file guard during PUB reset |
| `api/v2/admin/photo-library/delete.php` | Thin existing-URL doorbell to PHOTOS |
| `api/v2/admin/photo-library/delete-inactive.php` | Thin existing-URL doorbell to PHOTOS |
| `api/v2/admin/photo-library/merge.php` | Thin existing-URL doorbell to PHOTOS |
| `api/v2/admin/photo-library/duplicate-candidates.php` | Thin existing-URL doorbell to PHOTOS |
| `api/v2/admin/saved-palette-photos/delete.php` | Retains palette-owned unlinking, delegates all asset disposal and returns cleanup outcomes |
| `api/v2/admin/saved-palette-delete.php` | Adds photo cleanup outcomes to the existing success response |
| `api/tests/PhotoLifecycleTest.php` (new) | Disposable lifecycle, merge, file-safety and palette-transaction fixtures |
| `api/tests/PhotoLibraryUsageTest.php` | Separates its usage fixture from the replacement fixture's pre-existing linked asset |
| `scripts/test-photo-lifecycle.php` (new) | Safe local runner, including existing project/PV/Gallery/REX regressions; never loads production `api/db.php` |
| `scripts/test-photo-lifecycle-http.mjs` (new) | Temporary HTTP server running real API doorbells/app code with fixture DB/bootstrap and a test-only auth stub |
| `docs/PHOTOS_PHASE1.md` (new) | This report |

The unchanged `api/v2/admin/photo-library/usage.php`, `dedupe-rel-path-rows.php` and `dedupe-saved-palette-rows.php` also delegate through the retained compatibility services to PHOTOS. Source-based deletion methods (`deleteSavedPalettePhoto`, `deleteExtraPhoto`, `deleteBySource`, `deleteBySourceAndTitle`, `deleteById`) reach the same deletion guard. `reassignAllUsages` reaches the transactional merge guard.

## Verification

Before implementation: the 7 existing replacement/usage regressions passed.

After implementation:

- `php scripts/test-photo-lifecycle.php`: **54 tests passed**. Includes unused/in-use deletion, mixed bulk deletion, shared paths/assets/symlinks, artwork cascade protection, project/PV/playlist/artwork merge references, conflicting associations/access contexts, keeper eligibility, dedupe dry runs/retention, transaction/savepoint failures, filesystem failures, palette deletion rollback, and existing project/PV/Gallery/REX/membership tests.
- `node scripts/test-photo-lifecycle-http.mjs`: **passed**. Actual preserved URLs, JSON contracts, deletion/usage guards, bulk outcomes, merge/dry-run behavior, invalid/missing IDs, method validation and HTTP 200/400/401/404/405/409 outcomes. Auth is a fixture stub; this does not claim to retest production SYSTEM login.
- Existing `node scripts/test-art-repertoire.mjs`: **passed**. Real multipart Art upload/storage/retrieval/filtering, palette types/direct association, Save as New/hash behavior, explicit Gallery opt-in, and Photo Library browser checks at 1280px and 390px.
- PHP syntax: **25 changed/new PHP files passed**. `git diff --check` passed.
- Upload/replacement implementations and the obsolete `cleanup-legacy-applied.php` implementation were compared byte-for-byte with the task-start backup and remain unchanged.

No destructive production test or production endpoint was executed. No frontend source was changed by this phase.

## The render recipe is current PUB functionality

The references found are **current video publishing inputs**, distinct from the retired masking/Applied Palette/base-variant renderer:

- `app/PUB/Create/Pinterest/BeforeAfterVideoRecipe.php`: builds a generic Remotion video plan for Pinterest before/after videos.
- `app/PUB/Create/YouTube/PlaylistVideoRecipe.php`: current YouTube playlist video settings.
- `app/PUB/Create/CreateManager.php` and `app/PUB/Repos/PdoPubAssetRepository.php`: file Creator ingredients in `pub_asset_orders.ingredients` for creation/redo.
- `app/PUB/Create/Video/PdoVideoJobRepository.php`: queues `video_recipe_key` and `props_json` in `pub_video_jobs`.

No recipes, render plans, creators, scheduling or playback behavior were refactored. If a merge would invalidate filed production inputs, PHOTOS returns a clear conflict rather than rewriting PUB-owned inputs/signatures. A corresponding input must be resolved through PUB first.

## Retired functionality: remove later, do not migrate

The following were encountered and are outside Phase 1 consolidation after the architectural clarification. They were not deleted, migrated or modernized:

- Masks/masking tools: `app/services/MaskOverlayService.php`, `app/services/MaskBlendService.php`, `app/repos/PdoMaskBlendSettingRepository.php`, `api/services/MaskTrainingService.php`, `api/v2/mask-training/`, admin mask endpoints and the Mask* frontend components.
- Applied Palettes and old base/variant rendering: `app/entities/AppliedPalette.php`, `app/services/PhotoRenderingService.php`, `api/v2/photos/render-apply.php`, `render-color.php`, `render-which-files.php`, `src/components/PhotoRenderer/`, and obsolete base/variant branches in `app/repos/PdoPhotoRepository.php`.
- The obsolete maintenance endpoint `api/v2/admin/photo-library/cleanup-legacy-applied.php` remains at its original location and implementation. Its repository reassignment call now encounters the common PHOTOS merge guard, but its legacy candidate/retirement logic was deliberately not moved into a new service. Retire the endpoint in the separately approved cleanup.
- Palette Set compatibility branches in `app/repos/PdoSavedPaletteRepository.php`, `app/services/SavedPaletteService.php` and old set relationships remain in place pending separate removal. Existing FK/path references are checked defensively while their rows exist; this does not restore or migrate Palette Sets.

These obsolete components should be retired in the next cleanup phase rather than promoted into new departments. No legacy table or historical migration record was removed or rewritten during this task.

## Remaining scope and limitations

- Upload/replacement cleanup is intentionally unchanged, including `api/v2/admin/saved-palette-photos/upload.php`, `app/services/PhotosUploadService.php`, `app/PHOTOS/Services/PhotoUploadService.php` and `PhotoReplacementService.php`. This is the explicit exception to sharing the new lifecycle disposal implementation. Retire obsolete upload/render paths later rather than modernize them.
- Generic PUB/ASSETS output deletion remains in its own department; the Photo Library source-file guard/delegation is the Phase 1 boundary.
- Completed alt-text jobs and inactive references conservatively block deletion. Duplicate project/group links, multiple artwork/asset/job records and differing permissions require explicit reconciliation; nothing is silently overwritten or cascaded.
- Failed filesystem disposal retains bytes and reports the reason. It does not undo an already committed row deletion. No automatic orphan-file sweeper or thumbnail purge was added.
- Live MySQL schema/FKs were inspected, but destructive tests ran on SQLite, not a disposable MySQL server. MySQL lock behavior and concurrent writer/filesystem races have not been integration-tested. Reference checks do not introduce a shared lock protocol across upload/publishing writers; that would require separately scoped writer changes.
- Unknown new FK consumers fail closed until explicitly supported. Historical migration/audit maps are not active associations and are not rewritten.
