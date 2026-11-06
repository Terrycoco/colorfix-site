import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from 'playwright';
import { preview } from 'vite';

// Test the actual compiled admin entrypoint and route tree, not a simplified
// component fixture. Optional live origin still intercepts every API request.
let server;
let browser;
try {
  const liveOrigin = process.env.COLORFIX_ADMIN_TEST_ORIGIN;
  if (!liveOrigin) server = await preview({ preview: { host: '127.0.0.1', port: 0, strictPort: false, open: false } });
  const origin = liveOrigin || `http://127.0.0.1:${server.httpServer.address().port}`;
  const html = liveOrigin ? null : await readFile('dist/admin/index.html', 'utf8');
  browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const details = [];
  const lists = [];
  let sessionChecks = 0;
  const playlist = { playlist_id: 43, title: 'Adams Makeover', type: 'normal', is_active: 1 };
  await context.route('**/api/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/session.php')) {
      sessionChecks++;
      if (sessionChecks > 1) await new Promise(resolve => setTimeout(resolve, 2000));
      return route.fulfill({ json: { ok: true, user: { id: 1, email: 'admin@example.test', is_admin: true } } });
    }
    if (url.pathname === '/api/v2/admin/playlists/list.php') {
      const completed = new Promise(resolve => setTimeout(resolve, 3000));
      lists.push(completed);
      await completed;
      return route.fulfill({ json: { ok: true, items: [playlist] } });
    }
    if (url.pathname === '/api/v2/admin/playlists/get.php') {
      details.push(url.href);
      return route.fulfill({ json: { ok: true, playlist, items: Array.from({ length: 5 }, (_, index) => ({
        playlist_item_id: 430 + index, playlist_id: 43, order_index: index + 1,
        title: `Stability slide ${index + 1}`, item_type: 'non-palette', is_active: 1,
      })) } });
    }
    return route.fulfill({ json: { ok: true, items: [], projects: [], presets: [], results: [], status: 'ok' } });
  });
  if (html) await context.route('**/admin/playlists/43', route => route.fulfill({ contentType: 'text/html', body: html }));
  const page = await context.newPage();
  page.on('pageerror', error => console.error('Admin build:', error.message));
  let documents = 0;
  page.on('request', request => { if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documents++; });
  await page.goto(origin + '/admin/playlists/43');
  await page.getByText('Stability slide 5', { exact: true }).waitFor();
  const baseline = details.length;
  await page.getByRole('button', { name: 'Edit', exact: true }).first().click();
  const input = page.getByLabel('Title', { exact: true });
  await input.fill('Unsaved work must survive');
  const handle = await input.elementHandle();
  await Promise.all(lists);
  await page.waitForTimeout(150);
  console.log(JSON.stringify({ source: liveOrigin ? 'deployed build (mock API)' : 'local production build', detailsBefore: baseline, detailsAfter: details.length, initialSessionChecks: sessionChecks, documentLoads: documents }));
  assert.equal(details.length, baseline, 'Late startup responses must not refetch/reinitialize the editor');
  assert.equal(documents, 1, 'No document reload');
  assert(await handle.evaluate(el => el.isConnected), 'Edit form stays mounted');
  assert.equal(await input.inputValue(), 'Unsaved work must survive');
  for (let index = 0; index < 2; index++) {
    const checked = page.waitForResponse(response => response.url().includes('/session.php'));
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await checked;
    await page.waitForTimeout(50);
    assert.equal(details.length, baseline);
    assert(await handle.evaluate(el => el.isConnected));
    assert.equal(await input.inputValue(), 'Unsaved work must survive');
  }
  console.log('PASS actual admin build: delayed startup data and repeated verification preserve the open form and unsaved text');
} finally {
  await browser?.close();
  if (server) await new Promise(resolve => server.httpServer.close(resolve));
}
