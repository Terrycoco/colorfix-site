# SYSTEM authentication

Namespace: `App\SYSTEM` (the existing autoloader maps it to `app/SYSTEM`).

- `Repos/PdoAdminUserRepository.php`: stored `admin_users` fields and password/version updates.
- `Services/AdminAuthService.php`: password verification, current user, password changes and revocation.
- `Services/AdminSession.php`: private server sessions and host-only HttpOnly cookies.
- `Services/AdminLoginThrottle.php`: login attempt limits.
- `Http/AdminGuard.php`: shared protected-endpoint check.
- `Endpoints/AdminAuthEndpoint.php`: HTTP request/response handling.

The API files only bootstrap and ring the corresponding endpoint. Existing
`api/v2/admin/auth.php` consumers use a compatibility function that delegates to
the SYSTEM guard. The old boolean cookies, browser storage and JSON device-token
file are not accepted as authentication. Tokens are not put in URLs or returned
to JavaScript. The session is checked against the stored `is_active` and
`session_version` on every protected request. Password changes revoke other
sessions while renewing the current one; logout-all revokes the current one too.

## Routes

- POST `/api/login.php`: email, password, optional remember (default false).
- GET `/api/session.php`: verified user or 401.
- POST `/api/logout.php`: invalidate this session.
- POST `/api/admin-logout-all.php`: invalidate all sessions.
- POST `/api/admin-change-password.php`: current_password, new_password.
- The old device-auth/register routes validate the current session only. A
  supplied legacy token cannot restore or register access.

Without remember, the cookie lasts for the browser session and server access has
an eight-hour absolute expiry. Remember lasts at most 30 days. Sessions live in
the hosting account's private `.colorfix/admin-sessions-*` directory outside the
web/deployment trees and `/tmp` cleanup. `COLORFIX_ADMIN_SESSION_PATH` can override
that location (tests use a temporary directory). Existing sessions are moved from
the previous temp location on their next authenticated request without extending
their original expiry. Anonymous requests do not set a session cookie. This uses
PHP sessions, not a new database session table.

## Cutover and recovery

Do not deploy API/UI changes before creating a usable admin account. The code
never seeds a password, reuses the old hardcoded credential, or changes schema.
The `admin_users` table must already exist. From the local project directory:

```
bash scripts/setup-admin-password.sh
```

This uploads only SYSTEM classes and the setup command, leaving live API/frontend
behavior unchanged. It prompts twice on the remote terminal with typing hidden,
stores the hash, then verifies it against the database. No plaintext file is needed.
The default email is Terry's; pass a different email as the first argument if needed.
This command never deploys the login cutover or signs out the old boolean-cookie login.

Alternatively use an SSH terminal and a private password file outside the document
root (permissions 0600):

```
php scripts/system-admin-password.php --email=YOUR_EMAIL --password-file=/private/path/password.txt
```

This CLI-only command stores a PHP password hash, increments session_version for
an existing account and does not reactivate disabled accounts. Delete the private
plaintext input file after provisioning and save the password in a password manager.
It also provides recovery if a browser session is lost. Never commit the file or
put the password in a command-line argument, API URL or source code.

Deploy SYSTEM classes before their API callers, then deploy the API and browser
changes together. All legacy browsers must sign in once. All admin entrypoints
and the legacy maintenance/write routes listed in
`Resources/admin-route-coverage.json` invoke the shared guard. Public analytics
and error receivers, CLI-only scheduling, and separately authenticated worker
routes are explicitly classified. The public Advanced Search supercategory
catalog allows only its GET/list action without a session. Server CLI maintenance
continues to rely on OS/SSH access. Worker-secret authentication remains separate.
The pre-existing truncated `api/update-hcl-rgb-lookup.php` is preserved unchanged
and denied direct web access in `api/.htaccess`; it is not an enabled maintenance route.

Run `node scripts/audit-system-admin-guards.mjs` when adding admin routes to catch
unclassified entrypoints or missing guard imports.

Validation: `node scripts/test-system-auth.mjs` uses an isolated temporary SQLite
database and PHP HTTP server, without production data or accounts.
