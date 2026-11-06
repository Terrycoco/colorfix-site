# SAS (Seek & Send)

SAS is an isolated React entrypoint at `/sas`. Its components and styles live in
`src/SAS`; it does not mount the main app, Player, REX, or ANA providers.

Photos searches use the protected `/api/v2/admin/sas/search-photos.php` endpoint.
An existing ColorFix SYSTEM login is accepted automatically. An authorized SAS
photo search promotes that verified session to persistent device access, without
requiring another password. If the session is missing or revoked, SAS displays
an inline sign-in form using the existing `/api/login.php` and the same ColorFix
account/password. Successful sign-in resumes the search and preserves selections.
SAS sign-in sends `session_mode=sas`; there is no separate SAS credential store.

SAS device identities have no server-side expiration date. The shared HttpOnly
SYSTEM cookie is renewed on use within browser cookie-lifetime limits. Password
changes, account deactivation, session-version revocation, and explicit sign-out
remain enforced by SYSTEM. Ordinary ColorFix logins keep their existing session
and 30-day remember behavior until an authorized SAS search promotes that device.

Photo search 401 responses lead to sign-in; other failures retain Retry. Quick
Send public destinations remain available without login. Photo sharing continues
to build public POD links with `src=sas`; recipient access is unchanged.

## Local development and verification

Run `npm run dev` and open `/sas` or `/sas/photos`.

- `node scripts/test-sas-auth.mjs` runs the real standalone SAS UI at mobile widths
  against an isolated local SYSTEM account/session fixture. It covers missing and
  existing ColorFix logins, invalid passwords, password visibility, persistence
  beyond ordinary deadlines, password-change/logout revocation, retained search
  and selections, transient-error Retry, and POD links with `src=sas`.
- `node scripts/test-system-auth.mjs` checks ordinary SYSTEM session behavior.

## Build and deployment

- `npm run build` builds all four entries, including `dist/sas/index.html`.
- `npm run build:sas` builds only SAS without clearing other `dist` entries.
- The SAS auth fix also changes SYSTEM services/endpoint and the SAS PHP endpoint;
  a frontend-only deployment is insufficient for non-expiring device behavior.
- No schema migration or password change is required for this fix.

Production URL: `https://colorfix.terrymarr.com/sas`.
