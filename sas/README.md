# SAS (Seek & Send)

SAS is an isolated React entrypoint at `/sas`. Its components and styles live in
`src/SAS`; it does not mount the main app, Player, REX, or ANA providers.

## Local Development

Run `npm run dev` and open `/sas` on the URL printed by Vite. The Photos shell is
at `/sas/photos`. Search currently shows placeholder empty results; there are no
API requests. The other three home tiles are disabled.

## Build and Deployment

- `npm run build` builds all four entries, including `dist/sas/index.html`.
- `npm run build:sas` rebuilds only SAS into `dist`, without clearing existing
  public/admin/player outputs. It can also produce SAS on a clean checkout, but
  a complete normal build is needed before deploying the whole site.
- `npm run preview` serves the built entries locally, including SAS deep links.
- After review, run `npm run build && ./deploy.sh` for a complete deployment.
  The existing rsync uploads SAS with the rest of `dist`; no second deployment
  script is needed.

Production URL: `https://colorfix.terrymarr.com/sas`.

Vite uses root-relative `/assets/` URLs, like the other entries. SAS imports no
main application styles. Its dedicated Vite fallback handles development and
preview; the SAS-only rules in the root `.htaccess` handle Apache refreshes.

## Verification

Check `/sas` and `/sas/photos` at 320px, 390px, and desktop widths. Confirm four
tiles in two columns, only Photos enabled, search/clear and Back working, direct
Photos loading and reload working, no horizontal overflow, and no API requests.
After a SAS-only build, the other three HTML entries and their assets must remain
intact. Production Apache routing must be checked after the reviewed deployment.
