import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { createServer } from 'vite';

// Exercise the real provider/login components with intercepted HTTP responses.
// No accounts, cookies or requests are sent to production.
const server = await createServer({
  server: { host: '127.0.0.1', port: 0, strictPort: false, open: false },
  plugins: [{
    name: 'system-auth-browser-fixture',
    resolveId(id) { if (id === '/__system-auth-test.js') return '\0system-auth-test'; },
    load(id) {
      if (id !== '\0system-auth-test') return;
      return `
          import React from 'react';
          import { createRoot } from 'react-dom/client';
          import { BrowserRouter, useLocation } from 'react-router-dom';
          import { AppStateProvider, useAppState } from '/src/context/AppStateContext.jsx';
          import LoginPage from '/src/pages/login/LoginPage.jsx';
          import HomePage from '/src/pages/HomePage/index.jsx';
          import { isAdmin } from '/src/helpers/authHelper.js';
          function App() {
            const { user, authReady } = useAppState();
            const location = useLocation();
            if (location.pathname === '/login') return React.createElement(LoginPage);
            if (!authReady) return React.createElement('p', null, 'Checking');
            if (location.pathname === '/') return React.createElement(HomePage);
            return user?.is_admin && isAdmin()
              ? React.createElement('h1', null, 'Verified admin')
              : React.createElement(LoginPage);
          }
          createRoot(document.getElementById('root')).render(
            React.createElement(AppStateProvider, null,
              React.createElement(BrowserRouter, null, React.createElement(App))));
        `;
    },
  }],
});
let browser;
try {
  await server.listen();
  const origin = `http://127.0.0.1:${server.httpServer.address().port}`;
  browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  let authenticated = false;
  let sessionStatus = 200;
  let sessionDelay = 0;
  let rememberSubmitted = false;
  const user = { id: 1, email: 'admin@example.test', is_admin: true };
  await context.addInitScript(() => {
    localStorage.setItem('isTerry', 'true');
    localStorage.setItem('cf_logged_in', '1');
    localStorage.setItem('cf_user', JSON.stringify({ is_admin: true }));
    localStorage.setItem('cf_device_token', 'forged');
    document.cookie = 'cf_admin=1; path=/';
  });
  await context.route('**/api/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/session.php')) {
      if (sessionDelay) await new Promise(resolve => setTimeout(resolve, sessionDelay));
      if (sessionStatus !== 200) return route.fulfill({ status: sessionStatus, json: { error: 'Temporary failure' } });
      return route.fulfill({ status: authenticated ? 200 : 401, json: authenticated ? { ok: true, user } : { ok: false } });
    }
    if (url.pathname.endsWith('/login.php')) {
      const input = new URLSearchParams(route.request().postData());
      rememberSubmitted = input.get('remember') === 'true';
      authenticated = true;
      return route.fulfill({ json: { success: true, user } });
    }
    if (url.pathname.endsWith('/v2/front-page.php')) {
      return route.fulfill({ json: { success: true, results: [], inserts: [], meta: {} } });
    }
    return route.fulfill({ json: [] });
  });
  await context.route(url => url.origin === origin && ['/', '/login'].includes(url.pathname), async route => route.fulfill({
    contentType: 'text/html',
    body: await server.transformIndexHtml('/__system-auth-test', '<!doctype html><div id="root"></div><script type="module" src="/__system-auth-test.js"></script>'),
  }));
  const page = await context.newPage();
  page.on('pageerror', error => console.error('Browser fixture:', error.message));
  await page.goto(origin + '/');
  const loginLink = page.locator('footer').getByRole('link', { name: 'Login', exact: true });
  await loginLink.waitFor();
  assert.equal(await loginLink.getAttribute('href'), '/login');
  await loginLink.click();
  await page.getByRole('heading', { name: 'Login', exact: true }).waitFor();
  assert.equal(await page.evaluate(() => localStorage.getItem('cf_user')), null);
  await page.getByLabel('Email:', { exact: true }).fill(user.email);
  await page.getByLabel('Password:', { exact: true }).fill('browser-test-password');
  await page.getByRole('button', { name: 'Show password', exact: true }).click();
  assert.equal(await page.getByLabel('Password:', { exact: true }).getAttribute('type'), 'text');
  assert.equal(await page.getByLabel('Password:', { exact: true }).inputValue(), 'browser-test-password');
  await page.getByRole('button', { name: 'Hide password', exact: true }).click();
  assert.equal(await page.getByLabel('Password:', { exact: true }).getAttribute('type'), 'password');
  await page.getByLabel('Remember this personal device for 30 days').check();
  await page.getByRole('button', { name: 'Log In', exact: true }).click();
  await page.getByRole('heading', { name: 'Verified admin' }).waitFor();
  assert.equal(new URL(page.url()).pathname, '/admin/');
  assert(rememberSubmitted);
  assert(!page.url().includes('device_token'));
  sessionStatus = 503;
  const temporaryFailure = page.waitForResponse(response => response.url().includes('/session.php') && response.status() === 503);
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await temporaryFailure;
  await page.waitForTimeout(50);
  await page.getByRole('heading', { name: 'Verified admin' }).waitFor();
  assert.equal(await page.getByRole('heading', { name: 'Login', exact: true }).count(), 0);
  sessionStatus = 200;
  sessionDelay = 500;
  await page.goto(origin + '/login');
  await page.getByRole('status').waitFor();
  assert.equal(await page.getByRole('heading', { name: 'Login', exact: true }).count(), 0, 'No login form while checking a remembered session');
  await page.getByRole('heading', { name: 'Verified admin' }).waitFor();
  assert.equal(new URL(page.url()).pathname, '/admin/');
  sessionDelay = 0;
  authenticated = false;
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await page.getByRole('heading', { name: 'Login', exact: true }).waitFor();
  console.log('PASS browser: password eye control, automatic remembered-login redirect without login flash, transient failures preserve admin, authoritative revocation clears access');
} finally {
  await browser?.close();
  await server.close();
}
