import assert from 'node:assert/strict';
import net from 'node:net';
import { spawn } from 'node:child_process';
import { chromium } from 'playwright';
import { createServer } from 'vite';

const phpPort = await new Promise(resolve => {
  const listener = net.createServer();
  listener.listen(0, '127.0.0.1', () => { const port = listener.address().port; listener.close(() => resolve(port)); });
});
let logs = '';
const php = spawn('php', ['-S', `127.0.0.1:${phpPort}`, 'api/tests/HexSearchHttpFixture.php'], {
  env: { ...process.env, COLORFIX_HEX_TEST: '1' }, stdio: ['ignore', 'pipe', 'pipe'],
});
php.stderr.on('data', chunk => { logs += chunk; });
const backend = `http://127.0.0.1:${phpPort}`;
let browser;
let server;
try {
  for (let i = 0; i < 100; i++) {
    try { await fetch(backend); break; } catch {
      if (php.exitCode !== null) throw new Error(logs);
      await new Promise(resolve => setTimeout(resolve, 30));
    }
  }
  const invalid = await fetch(backend, { method: 'POST', body: JSON.stringify({ params: { hex: '#123' } }) });
  assert.equal(invalid.status, 400);
  const badParams = await fetch(backend, { method: 'POST', body: JSON.stringify({ params: 'wrong' }) });
  assert.equal(badParams.status, 400);
  const filtered = await fetch(backend, { method: 'POST', body: JSON.stringify({ params: { hex: '808080' }, searchFilters: { brand: ['sw'] } }) });
  assert.deepEqual((await filtered.json()).results.map(row => row.id), [1, 2, 7, 3, 4, 5], 'Server ignores brand filters for hex');
  server = await createServer({
    server: { host: '127.0.0.1', port: 0, strictPort: false, open: false },
    plugins: [{
      name: 'hex-search-fixture',
      resolveId(id) { if (id === '/__hex-test.js') return '\0hex-test'; },
      load(id) {
        if (id !== '\0hex-test') return;
        return `
          import React from 'react';
          import { createRoot } from 'react-dom/client';
          import { BrowserRouter, Route, Routes } from 'react-router-dom';
          import { AppStateProvider } from '/src/context/AppStateContext.jsx';
          import NameSearchItem from '/src/components/GalleryItems/NameSearchItem.jsx';
          import GalleryPage from '/src/pages/GalleryPage/index.jsx';
          const tile = React.createElement(NameSearchItem, { item: { display: 'Search By Color Name' } });
          createRoot(document.getElementById('root')).render(
            React.createElement(AppStateProvider, null, React.createElement(BrowserRouter, null,
              React.createElement(Routes, null,
                React.createElement(Route, { path: '/', element: tile }),
                React.createElement(Route, { path: '/admin/', element: tile }),
                React.createElement(Route, { path: '/results/:queryId', element: React.createElement(GalleryPage) }),
                React.createElement(Route, { path: '/admin/results/:queryId', element: React.createElement(GalleryPage) })))));
        `;
      },
    }],
  });
  await server.listen();
  const origin = `http://127.0.0.1:${server.httpServer.address().port}`;
  browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  await context.addInitScript(() => localStorage.setItem('pals.brand_filters.v1', JSON.stringify(['sw'])));
  const requests = [];
  await context.route('**/api/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/api/v2/hex-search.php') {
      requests.push(route.request().postDataJSON());
      const response = await route.fetch({ url: backend });
      return route.fulfill({ response });
    }
    if (url.pathname === '/api/v2/gallery/run-query.php') {
      requests.push(route.request().postDataJSON());
      return route.fulfill({ json: { meta: { meta_id: 18, item_type: 'swatch' }, results: [{ id: 9, name: 'Name result', hex6: 'FFFFFF', brand: 'sw', item_type: 'swatch' }], inserts: [] } });
    }
    if (url.pathname === '/api/v2/gallery-results.php') return route.fulfill({ json: { ok: true, items: [] } });
    if (url.pathname === '/api/session.php') return route.fulfill({ status: 401, json: { ok: false } });
    return route.fulfill({ json: [] });
  });
  await context.route(url => url.origin === origin && ['/', '/admin/'].includes(url.pathname), async route => route.fulfill({
    contentType: 'text/html',
    body: await server.transformIndexHtml('/__hex-test', '<!doctype html><div id="root"></div><script type="module" src="/__hex-test.js"></script>'),
  }));
  const page = await context.newPage();
  page.on('pageerror', error => console.error('Browser:', error.message));
  for (const [entry, term] of [['/', '#808080'], ['/', '808080'], ['/admin/', '#808080']]) {
    await page.goto(origin + entry);
    await page.getByText('Search by Name or Hex', { exact: true }).click();
    await page.getByRole('searchbox', { name: 'Search by Name or Hex' }).fill(term);
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await page.locator('#swatch-5').waitFor();
    const url = new URL(page.url());
    assert.equal(url.searchParams.get('hex'), '808080');
    assert.equal(url.hash, '');
    assert.equal(url.pathname, entry === '/admin/' ? '/admin/results/18' : '/results/18');
    const searchedSwatch = page.getByRole('img', { name: /^Searched color #808080\. Hue/ });
    assert.equal(await searchedSwatch.locator('.pals-fill').evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(128, 128, 128)');
    assert.equal(await searchedSwatch.locator('.pals-name').innerText(), '#808080');
    assert.match(await searchedSwatch.locator('.pals-meta').innerText(), /H:[\d.]+ • C:[\d.]+ • L:[\d.]+/);
    assert.equal(await searchedSwatch.locator('.pals-brand-full, .pals-brand-short, button, a').count(), 0);
    const previousUrl = page.url();
    await searchedSwatch.click();
    assert.equal(page.url(), previousUrl, 'Reference swatch has no fake paint detail route');
    const order = await page.evaluate(() => [...document.querySelectorAll('[id^="swatch-"]')].map(el => el.id).filter(id => /^swatch-\d+$/.test(id)));
    assert.deepEqual(order, [1, 2, 7, 3, 4, 5].map(id => `swatch-${id}`));
    assert.deepEqual(requests.at(-1).searchFilters, {}, 'Hex requests bypass active brand filters');
    await page.getByText('Exact matches', { exact: true }).last().waitFor();
    await page.getByText('Closest matches', { exact: true }).last().waitFor();
    assert.equal(await page.getByRole('button', { name: 'Group by Chroma', exact: true }).count(), 0);
  }
  await page.goto(origin + '/');
  await page.getByText('Search by Name or Hex', { exact: true }).click();
  await page.getByRole('searchbox').fill('Alabaster');
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  await page.getByText('Name result', { exact: true }).waitFor();
  assert.equal(new URL(page.url()).searchParams.get('name'), '%Alabaster%');
  assert.equal(requests.at(-1).params.name, '%Alabaster%');
  console.log('PASS browser + PHP/SQLite: both hex formats, public/admin routing, exact-first DOM ordering, all brands despite active filters, unchanged name/code search, HTTP validation');
} catch (error) {
  console.error(logs);
  throw error;
} finally {
  await browser?.close();
  await server?.close();
  php.kill();
}
