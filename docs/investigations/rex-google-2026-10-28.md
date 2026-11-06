# Public REX Google rendering failure — 2026-10-28

Application fix deployed to https://colorfix.terrymarr.com. The host's security false positive remains outside repository control. After deployment, the site owner confirmed: ‘it is indexed now on google.’ This is owner-reported Google confirmation; this agent did not independently operate Search Console.

## Confirmed cause and evidence

Affected public URL: https://colorfix.terrymarr.com/t/m70J1Zy-mPOwamhlAMIkAOyXTQ0zy2THjuzWxHCgWZg (playlist 3, Cottage Palettes, nine items).

The initial document succeeds. Its subsequent resolver API request is rejected before PHP by hosting ModSecurity rule 900933. The frontend previously interpreted the non-JSON rejection as a missing link and replaced the playable experience with `Link not found.` This explains Google's successful document fetch followed by a Soft 404 screenshot.

Access log: `/usr/local/apache/domlogs/shortgal/colorfix.terrymarr.com-ssl_log` (available through `~/access-logs/colorfix.terrymarr.com-ssl_log`). On Oct 28, document requests at `13:34:49 -0600` returned 200. Resolver, categories, session, and analytics API requests at `13:34:51 -0600` returned **409, 83 bytes**. Further failures occurred at 13:35:39 and 13:59:08 MDT. These times are 12:34:49, 12:34:51, 12:35:39 and 12:59:08 PDT respectively; the reported inspection window is 19:35 UTC.

Exact resolver evidence from `/usr/local/apache/logs/error_log`:

```text
[Wed Oct 28 13:35:39.946274 2026] [security2:error]
[client 192.178.7.193:35319]
ModSecurity: Access denied with code 409 (phase 1).
RBL lookup of 193.7.178.192.vulnscan.websitewelcome.com succeeded at REMOTE_ADDR.
[file "/opt/mod_security/hg_rules.conf"] [line "1600"] [id "900933"]
[msg "Vulnerability Scan RBL: vulnscan.websitewelcome.com"]
[hostname "colorfix.terrymarr.com"] [uri "/api/v2/rex/resolve.php"]
[unique_id "auJOi1sm2PRuSEsJ0riCUgAAAAU"]
```

The three logged sources, 192.178.7.192, .193 and .201, reverse-resolve to `crawl-192-178-7-{192,193,201}.googlebot.com`; forward DNS returns the corresponding original IPs. This follows Google's [verification procedure](https://developers.google.com/crawling/docs/crawlers-fetchers/verify-google-requests). These addresses were not in the user-triggered-fetchers-google JSON checked during investigation; DNS verification, rather than that list or the claimed user agent, authenticates these requests.

Cookie-free requests made from this workstation with curl, a Chrome user agent, and `Mozilla/5.0 (compatible; Google-InspectionTool/1.0)` all returned HTTP 200, application/json, ok=true, playlist 3, Cottage Palettes, nine items. This does not reproduce an actual request from Google's network.

The exact historical resolver response body was not captured. Its 409 status and size are confirmed by the access log, and the phase-1 rejection is confirmed by the security log. The supplied `<script>do...` console excerpt belongs to another failed API response and is not treated as a captured resolver body.

## Trace and configuration inspected

- `.htaccess`: `/t/{token}` rewrites to `/t.php?token=...` with QSA. Existing API files bypass the SPA fallback. No user-agent bypass or challenge code.
- `t.php`: loads `api/autoload.php`, then `api/db.php`, then `RexPublicEntryEndpoint::handle` with `rex-shell.html`.
- `api/v2/rex/resolve.php`: loads the same autoloader and database file, then `RexResolveEndpoint::handle`.
- `api/.htaccess`: `php_value auto_prepend_file "/home1/shortgal/public_html/colorfix/app/bootstrap.php"`. Other rules concern integrations and a denied legacy maintenance file.
- `app/bootstrap.php`: path aliases, logger inclusion, error logging and an uncaught-exception handler. No challenge/409 logic. Its pre-existing exception handler references `ColorFix\Lib\Logger` although the included class uses `App\Lib\Logger`; this unrelated defect was not changed or attributed as the cause of the logged phase-1 denials.
- Parent `~/public_html/.htaccess`: PHP handler comments and a root-domain redirect to ColorFix. No bot challenge rules. No `.user.ini` found in the site root or API directory at the inspected paths.
- `api/autoload.php`: class loading. `api/db.php`: PDO/timezone and entry/error logging. No security challenge code. `api/php_error.log` had no current inspection errors; its last entry was August 14. `logs/latest.log` had not changed since October 11. `api/assign.log` has no entry in the checked 12:34:50–12:35:59 PDT window. Its entries do not contain request identifiers, so it cannot independently attribute requests. The security log's phase-1 denial establishes that the failed requests did not reach the PHP application. Temporary diagnostics are unnecessary given that evidence.
- `PdoRexReservationRepository::findByToken` reads `rex_reservations`.
- `RexResolver::resolveToken/resolveReservation` checks reservation existence/status/revocation, dispatches through `RexResolverRegistryFactory` and retains existing fallback behavior.
- `PlaylistExperienceResolver::resolve` selects the reservation experience, passes src and the reservation token to `PlaylistExperienceService::buildPlaybackPlanFromPlaylistExperience`. Public/Showcase use the public plan builder; client/concept retain their project flow. No admin login is required for the affected public reservation.
- `src/pages/REX/RexPublicPage/index.jsx` is the React loader. Previously JSON parse failure became null, and any failed response then threw `Link not found.` The final error fallback used the same text. Missing-token input, API errors, and unsupported/malformed destinations shared the error display. The server entry endpoint also previously mapped all resolution exceptions to its HTML 404.
- Repository searches found no `<script>do`, cookie challenge or Google user-agent security middleware in the relevant bootstrap/request code. Other uses of HTTP 409 are unrelated business conflicts. The rejecting code is the host-owned `/opt/mod_security/hg_rules.conf:1600`.

## Targeted changes

Production PHP source files changed:

1. `app/REX/Exceptions/RexNotFoundException.php` — typed missing/intentionally unavailable resource result.
2. `app/REX/Services/RexResolver.php` — explicit absent token/alias and inactive/revoked checks use that type. Existing fallback dispatch remains.
3. `app/REX/Resolvers/RouteResolver.php` — absent route uses that type.
4. `app/REX/Resolvers/DocumentResolver.php` — absent document uses that type.
5. `app/REX/Resolvers/PlaylistThumbsResolver.php` — absent playlist uses that type.
6. `app/PLAYLISTS/Services/PlaylistExperienceService.php` — absent public/project playlists and absent projects use that type; configuration errors retain failure semantics.
7. `app/REX/Endpoints/RexResolveEndpoint.php` — confirmed missing resources return generic 404 JSON; unexpected errors return generic 500 JSON. Logs exception type/file/line server-side without credentials, cookies, exception messages, query values or response payloads. JSON encoding throws on failure. Shares the existing successful response representation with the public entry endpoint.
8. `app/REX/Endpoints/RexPublicEntryEndpoint.php` — unexpected resolution/rendering failures return 503 with noindex and Retry-After. Eligible marketing playlists embed their already-resolved payload in an inert application/json script, escaped with JSON_HEX_* flags. The existing shared indexing policy gates embedding: stored is_active/is_public/indexable=1 and resolved public/Showcase experience. Private/client/noneligible resources keep their existing API flow, exclusions and access rules.

Frontend source files changed:

9. `src/helpers/rexResolution.js` — validates the bootstrap token, applies browser/referrer/session src attribution to the plan, and distinguishes a confirmed 404 JSON verdict from challenges/server failures.
10. `src/pages/REX/RexPublicPage/index.jsx` — consumes the eligible initial plan without a redundant API request. Otherwise retains resolver fetching and resource routing. Genuine failures show a generic retryable message, never the missing-link fallback. Route analytics/navigation and the existing players remain in place.

Test files added/updated:

- `api/tests/RexFailureHandlingTest.php`
- `scripts/test-rex-failures.mjs`
- `scripts/test-rex-loader.mjs`
- `scripts/test-rex-public-render.mjs`
- `scripts/test-rex-canonical.mjs` (reads the initial bootstrap instead of awaiting an API request that is no longer required).

Deployment uploaded the eight scoped PHP files, generated fingerprinted `dist/assets/`, and `dist/rex-shell.html`. The REX loader bundle is `assets/index-DJ263sIr.js`; the REX shell entry is `assets/main-B2iudEOB.js`. Other HTML entry points, security configuration and passwords were not deployed. Existing fingerprinted assets were retained. A pre-deployment backup is outside the document root at `~/.colorfix/rex-backups/before-google-fix-20261028.tar.gz`. Standard builds/deployments regenerate these assets from source.

The companion `rex-google-2026-10-28.patch` is relative to the working files at the start of this investigation, so it excludes earlier uncommitted features.

## Verification

- PHP syntax passed for every changed PHP source and the new PHP test; endpoint/exception syntax also checked with production PHP.
- Six resolver failure/status fixtures passed: database failure 500 without SQL detail exposure; missing token 404; inactive reservation 404; unknown resolver 500; absent route 404; empty token 400.
- All 68 existing REX indexing/canonical policy checks passed, including client/private/inactive/not-indexable exclusions. Existing sitemap eligibility/flag/deduplication tests passed.
- Response-helper tests passed for HTML 409, HTML 200 challenge, non-JSON 404, JSON 500, confirmed missing JSON 404, successful JSON, token mismatch and inferred-src attribution.
- The actual React REX loader passed browser tests for HTML 409, 500, network failure and confirmed 404. Retry issues a new request for genuine failures.
- Production build passed.
- LIVE anonymous initial document: HTTP 200; X-Robots-Tag `index, follow`; HTML robots `index, follow`; canonical/og:url are the affected REX address with no query parameters. Initial payload contains Cottage Palettes, playlist 3, nine items.
- LIVE direct resolver after deployment: ok=true, playlist 3, Cottage Palettes, nine items, without login.
- LIVE browser: affected public playlist, public Door Color Fixes Vol 1, and Showcase Palmdale Door ColorFix all render the actual player anonymously, with no `Link not found.`, stable REX canonicals/og:url, and zero resolver requests needed for initial playback. Normal analytics events retain src=sas.
- Repeated all three live browser cases while deliberately intercepting EVERY API request with HTTP 409 plus an HTML cookie challenge. All three still render the actual player using the embedded plan. This simulates the proven failure mode; it is not an actual Google request.
- LIVE nonexistent token: document 404 with noindex/noarchive; resolver 404 with generic `Link not found.` JSON.

## Hosting action still required

Ask Bluehost to correct the false positive in rule 900933 / `vulnscan.websitewelcome.com` for independently verified Google crawlers fetching ColorFix APIs. Supply the timestamp, IP, URI and unique ID above. Request removal of the erroneous RBL classification or a narrowly scoped host-managed correction, while keeping other security rules and private/admin authentication intact. Do not disable ModSecurity globally or trust a Google user-agent string. The application fix avoids the duplicate resolver dependency for eligible public rendering, but the host still blocks category/session/analytics requests from those Google IPs.

Suggested support text (prepared only; not sent):

> On Oct 28 2026 at 13:35:39 MDT (19:35:39 UTC), ModSecurity rule 900933 in /opt/mod_security/hg_rules.conf:1600 denied /api/v2/rex/resolve.php with HTTP 409 during Google Search Console inspection of colorfix.terrymarr.com. Unique ID: auJOi1sm2PRuSEsJ0riCUgAAAAU; client 192.178.7.193, reverse DNS crawl-192-178-7-193.googlebot.com, forward DNS confirms the same IP. The same RBL denial affects .192 and .201, also DNS-verified Google sources, and category/session/analytics APIs. Please investigate and correct this Google crawler false positive in vulnscan.websitewelcome.com without globally disabling security or trusting user-agent strings.

## Google confirmation

After deployment, Terry reported: “it is indexed now on google.” The owner confirms the indexing outcome. The agent independently verified the live anonymous responses and rendered player as described above, but did not operate an authenticated Search Console session or inspect its new screenshot/result directly.
