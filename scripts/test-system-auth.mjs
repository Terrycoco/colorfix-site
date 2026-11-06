import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, rm, mkdir, rename, access } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
import path from 'node:path';
import net from 'node:net';

const temp = await mkdtemp(path.join(tmpdir(), 'colorfix-system-test-'));
const port = await new Promise(resolve => {
  const server = net.createServer();
  server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); });
});
const origin = `http://127.0.0.1:${port}`;
let output = '';
const php = spawn('php', ['-S', `127.0.0.1:${port}`, 'api/tests/SystemAuthHttpFixture.php'], {
  env: { ...process.env, SYSTEM_AUTH_TEST_DB: path.join(temp, 'auth.sqlite'), COLORFIX_ADMIN_SESSION_PATH: path.join(temp, 'sessions') },
  stdio: ['ignore', 'pipe', 'pipe'],
});
php.stdout.on('data', value => { output += value; });
php.stderr.on('data', value => { output += value; });
let checks = 0;
function client(cookie = '') {
  return {
    cookie,
    async request(route, body, extra = {}) {
      const response = await fetch(origin + route, {
        method: body === undefined ? 'GET' : 'POST',
        headers: { ...(this.cookie ? { Cookie: this.cookie } : {}), ...(body === undefined ? {} : { 'Content-Type': 'application/json', Origin: origin }), ...extra },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      });
      for (const value of response.headers.getSetCookie()) {
        if (value.startsWith('cf_admin_session=')) this.cookie = value.split(';')[0];
      }
      return { status: response.status, data: await response.json(), headers: response.headers };
    },
  };
}
function status(result, expected) { assert.equal(result.status, expected); checks++; }
const credentials = { email: 'admin@example.test', password: 'initial-test-password', remember: true };

try {
  for (let i = 0; i < 100; i++) {
    try { await fetch(origin + '/session'); break; } catch {
      if (php.exitCode !== null) throw new Error(output);
      await new Promise(resolve => setTimeout(resolve, 30));
    }
  }
  const forged = client('cf_admin=1; cf_admin_global=1; cf_device_token=forged');
  status(await forged.request('/public-catalog?action=list'), 200);
  status(await forged.request('/public-catalog?action=delete_supercat'), 401);
  status(await forged.request('/public-catalog', { action: 'list' }), 401);
  const rejectedSession = await forged.request('/session');
  status(rejectedSession, 401);
  assert(!rejectedSession.headers.getSetCookie().some(value => value.startsWith('cf_admin_session=')), 'Anonymous checks cannot overwrite a remembered cookie');
  status(await forged.request('/protected'), 401);
  status(await forged.request('/device-auth?token=forged'), 401);
  status(await forged.request('/device-register', { token: 'a'.repeat(48) }), 401);
  const first = client('cf_admin_session=attackerfixedsession');
  status(await first.request('/login', credentials, { Origin: 'https://evil.example' }), 403);
  status(await first.request('/login', { ...credentials, password: 'wrong' }), 401);
  const login = await first.request('/login', credentials);
  status(login, 200);
  assert.equal(login.data.user.is_admin, true);
  assert(!('password_hash' in login.data.user) && !('device_token' in login.data));
  const cookie = login.headers.getSetCookie().findLast(value => value.startsWith('cf_admin_session='));
  assert.match(cookie, /HttpOnly/i);
  assert.match(cookie, /SameSite=Lax/i);
  assert.match(cookie, /expires=/i);
  assert(!first.cookie.includes('attackerfixedsession'));
  const resumed = await first.request('/session');
  status(resumed, 200);
  const originalExpiry = /expires=([^;]+)/i.exec(cookie)[1];
  assert(Date.parse(originalExpiry) > Date.now() + 29 * 86400000, 'Cookie persists for 30 days');
  assert(resumed.headers.getSetCookie().some(value => value.includes(`expires=${originalExpiry}`)), 'Restoration preserves the original expiry');
  // Existing /tmp sessions migrate once, with no change to their cookie or expiry.
  const incomingId = first.cookie.split('=')[1];
  const legacyDir = path.join(tmpdir(), 'colorfix-admin-' + createHash('sha256').update(process.cwd()).digest('hex').slice(0, 16));
  await mkdir(legacyDir, { recursive: true, mode: 0o700 });
  const legacyFile = path.join(legacyDir, 'sess_' + incomingId);
  const durableFile = path.join(temp, 'sessions', 'sess_' + incomingId);
  await rename(durableFile, legacyFile);
  const migrated = await first.request('/session');
  status(migrated, 200);
  await access(durableFile);
  await assert.rejects(access(legacyFile));
  assert.equal(first.cookie, 'cf_admin_session=' + incomingId);
  // Business work can continue without holding up other requests for this admin.
  const slow = await fetch(origin + '/slow-protected', { headers: { Cookie: first.cookie } });
  const { execFileSync: checkLock } = await import('node:child_process');
  checkLock('php', ['-r', '$file=fopen(getenv("SYSTEM_TEST_SESSION_FILE"),"r+"); exit($file && flock($file,LOCK_EX|LOCK_NB) ? 0 : 1);'], {
    env: { ...process.env, SYSTEM_TEST_SESSION_FILE: durableFile },
  });
  assert.match(await slow.text(), /done/);
  // Real browser storage: close it, then restore only persistent cookies.
  const { chromium } = await import('playwright');
  let browser = await chromium.launch({ headless: true });
  let context = await browser.newContext();
  const browserLogin = await context.request.post(origin + '/login', { data: credentials, headers: { Origin: origin } });
  assert.equal(browserLogin.status(), 200);
  const storage = await context.storageState();
  const remembered = storage.cookies.find(value => value.name === 'cf_admin_session');
  assert(remembered?.httpOnly && remembered.expires > Date.now() / 1000 + 29 * 86400);
  await browser.close();
  browser = await chromium.launch({ headless: true });
  try {
    context = await browser.newContext({ storageState: { cookies: storage.cookies.filter(value => value.expires > 0), origins: [] } });
    const restored = await context.request.get(origin + '/protected');
    assert.equal(restored.status(), 200);
  } finally { await browser.close(); }
  console.log('PASS 30-day cookies, anonymous-cookie isolation, legacy migration, and authentication after browser restart');
  status(await first.request('/protected'), 200);
  status(await first.request('/logout', {}, { Origin: 'https://evil.example' }), 403);
  const second = client();
  status(await second.request('/login', credentials), 200);
  status(await first.request('/change-password', { current_password: 'wrong', new_password: 'changed-test-password' }), 403);
  status(await first.request('/change-password', { current_password: credentials.password, new_password: 'short' }), 400);
  status(await first.request('/change-password', { current_password: credentials.password, new_password: 'changed-test-password' }), 200);
  status(await first.request('/session'), 200);
  status(await second.request('/protected'), 401);
  status(await client().request('/login', credentials), 401);
  const third = client();
  status(await third.request('/login', { ...credentials, password: 'changed-test-password', remember: false }), 200);
  const replay = client(third.cookie);
  status(await third.request('/logout', {}), 200);
  status(await replay.request('/session'), 401);
  status(await first.request('/logout-all', {}), 200);
  status(await first.request('/protected'), 401);
  // CLI recovery increments the version; all existing session checks read that DB field.
  const { execFileSync } = await import('node:child_process');
  const dbChecks = execFileSync('php', ['-r', `
    $pdo=new PDO('sqlite:'.getenv('SYSTEM_AUTH_TEST_DB'));
    $row=$pdo->query('SELECT * FROM admin_users')->fetch(PDO::FETCH_ASSOC);
    if (!password_verify('changed-test-password',$row['password_hash']) || $row['session_version'] != 3) exit(1);
    require 'api/autoload.php';
    $repo=new App\\SYSTEM\\Repos\\PdoAdminUserRepository($pdo);
    $repo->setPasswordByEmail('admin@example.test',password_hash('recovery-test-password',PASSWORD_DEFAULT));
    $row=$repo->findByEmail('admin@example.test');
    if ($row['session_version'] != 4 || !password_verify('recovery-test-password',$row['password_hash'])) exit(1);
    $pdo->exec('UPDATE admin_users SET is_active=0');
    $repo->setPasswordByEmail('admin@example.test',password_hash('recovery-test-password',PASSWORD_DEFAULT));
    if ($repo->findByEmail('admin@example.test')['is_active'] != 0) exit(1);
    echo 'PASS database hashes, revocation version, CLI recovery, disabled-account preservation';
  `], { env: { ...process.env, SYSTEM_AUTH_TEST_DB: path.join(temp, 'auth.sqlite') }, encoding: 'utf8' });
  console.log(dbChecks);
  status(await client().request('/login', { ...credentials, password: 'recovery-test-password' }), 401);
  let limited = false;
  for (let i = 0; i < 12; i++) {
    const result = await client().request('/login', credentials);
    if (result.status === 429) { limited = true; break; }
  }
  assert(limited, 'Login rate limiting');
  console.log(`PASS ${checks} HTTP authentication checks: legacy rejection, session cookies, restoration, CSRF origin checks, password change, cross-device revocation, logout replay and throttling`);
} catch (error) {
  console.error(output);
  throw error;
} finally {
  php.kill();
  await rm(temp, { recursive: true, force: true });
}
