# PHOTOS Phase 2 — deployment and rollback review

No deployment, production DDL, data deletion, asset removal, or palette deletion was performed in this session.

## Before deployment

1. Review `deployment-manifest.json` and `removed-code.json`. The manifest identifies every uploaded source/build file, size and SHA-256. Verify hashes against the staged artifact; regenerate after any source or build change.
2. The current frontend bundle includes the separately requested, still-uncommitted AdminSearchField and slide-label changes. Their new helper/test files are explicitly listed. Do not treat a frontend build as PHOTOS-only or omit its matching Client/Property search backend changes. They are disclosed dependencies, not new PHOTOS refactors.
   A concurrent Palette Viewer page search edit also appeared during preparation. It is included in the compiled bundle, but was not authored or acceptance-tested by this task. Obtain its owner's review before deploying the full bundle. The unrelated `AdminPlaylistsPage.zip` is excluded.
3. Save the exact live versions of affected PHP, root routing files, HTML shells and referenced JS/CSS assets to a private backup outside the document root. Back up the database before any later approved schema retirement. Protect credentials; do not publish database dumps or `api/db.php`.
4. Use explicit file manifests. Existing `deployapp.sh` and `deployapi.sh` use broad `rsync --delete`; do not run them as a preparation step. Review stale code removal separately against the exact allowlist. No recursive cleanup of `/photos`, `/assets`, `/exports`, `/share-cards`, or generated media directories.
5. Do not copy `dist/photos/**`, `.DS_Store`, or `palette-test.html`. The build includes one static progression wheel under `photos`; it is excluded from this package to avoid overwriting Photo Library storage. Keep the existing root Google verification file, robots and sitemap implementations.

## Approved code deployment sequence (NOT executed)

- Stage the complete PHP/PHOTOS dependency set and built hashed assets first. Check staging hashes.
- Switch PHP, routing and HTML entry points together in a short maintenance window or atomic release. Preserve server bootstrap/configuration, upload directories, login/session files and existing asset URLs.
- Obsolete executable application code is listed separately in `removed-code.json`. Removing those precise code files is part of a future approved deployment; no code removal command was executed on production. Current 410 rules protect retired routes from SPA fallback or stale PHP copies, but are not a substitute for removing retired code from the release.
- Keep old hashed frontend assets during cache turnover. Do not delete anything using a blanket mirror or wildcard.
- Do not execute `cleanup-proposal.sql` destructive sections during code cutover. The application intentionally tolerates retired tables remaining present through read-only deletion/merge interlocks.

## Required post-deployment checks

- Confirm retired mask/render/Palette Set URLs return HTTP 410, not React or executable legacy tools. Verify `.htaccess` is active on the host.
- Confirm real SYSTEM authentication, Photo Library edit/replacement/duplicate views, current Palette Viewer Full/Before/Zoom, Art defaults, Projects, Gallery, playlists and SAS. Use read-only smoke checks first; any production mutation requires an intentional user action.
- Confirm PUB/CHEF current recipe paths and thumbnail delivery. No full Pinterest/YouTube export was rendered as part of this cleanup.
- Confirm Adams Makeover remains inactive/private; its cover has no operational palette association. Palette 133 remains stored, with inactive viewer 56 and REX 64 retained. Palette 154's current Public presentation remains; obsolete Concept copy is not restored.
- Record server hashes, response statuses and deployment time. Local fixtures do not replace real host verification.

## Rollback before schema retirement

Restore the privately captured pre-deployment code/routing/HTML bundle and its referenced hashed assets as one release. No database conversion is required for this Phase 2 code release. Retired database tables and all image bytes remain untouched. Preserve any user edits made after deployment; do not restore a database backup merely to roll back code.

## Separate approved database retirement

Archive complete definitions/data for the fourteen proposed retired tables and both view definitions. Include their FK/index definitions. Keep a full database snapshot as well, and test restoration in a disposable database before approval. Re-run all read-only preflight queries; compare against `production-preflight.json`, including new FKs, jobs, views and unmatched metadata. Disable writes/maintenance workers while taking the final consistent backup and applying approved DDL.

Only then execute an explicitly approved copy of the commented DDL in dependency order. Do not disable FK checks. MySQL/MariaDB DDL commits implicitly, so SQL transaction rollback cannot restore dropped structures. Recover using the tested schema/data/view backup in dependency order and the matching application release; reconcile later legitimate writes before reopening service.

## Physical files

The approved asset removal list is empty. The historical file inventory contains 246 legacy-provenance paths: 237 referenced/protected and nine already absent. It authorizes no removal. Files with an old base/variant origin may now be current Photo Library assets. Before proposing any later file deletion, rescan current records, raw URLs, physical aliases, Asset Library metadata and PUB inputs. Keep current thumbnails, masks used as generic UI/CSS concepts, current render outputs and artwork images.
