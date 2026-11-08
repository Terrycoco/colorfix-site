import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createServer } from 'vite';
import { chromium } from 'playwright';

// Real PALETTES service/repository against SQLite only; never loads api/db.php.
const php = spawnSync('php', [], { encoding: 'utf8', input: `<?php
require getcwd().'/api/autoload.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE saved_palettes (id INTEGER PRIMARY KEY,nickname TEXT,display_title TEXT,palette_type TEXT,is_public INTEGER,is_active INTEGER,kicker_id INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE kickers (kicker_id INTEGER PRIMARY KEY,display_text TEXT);
CREATE TABLE saved_palette_members (id INTEGER,saved_palette_id INTEGER,color_id INTEGER,role_name TEXT,sheen TEXT,note TEXT,order_index INTEGER);
CREATE TABLE swatch_view (id INTEGER,name TEXT,brand TEXT,brand_name TEXT,code TEXT,hex6 TEXT,hcl_h REAL,hcl_c REAL,hcl_l REAL,int_only INTEGER,chip_num TEXT,cluster_id INTEGER,hue_cats TEXT,neutral_cats TEXT);
CREATE TABLE palette_viewers (palette_viewer_id INTEGER,saved_palette_id INTEGER,is_active INTEGER,format TEXT);
CREATE TABLE photo_library (photo_library_id INTEGER,palette_id INTEGER,is_inactive INTEGER,rel_path TEXT,tags TEXT,updated_at TEXT);
INSERT INTO saved_palettes VALUES (154,'Foothill Salmon Fix','Salmon','exterior',1,1,NULL,'2000-01-02',NULL), (133,'Hidden Cottage','Cottage Art','art',0,0,NULL,'2000-01-01',NULL), (1330,'Cottage 133','Exterior Cottage','exterior',1,1,NULL,'2026-01-01',NULL), (2,'Ocean Blue','Ocean','interior',1,1,NULL,'2026-01-02',NULL);");
$before = $pdo->query('SELECT * FROM saved_palettes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$service = new App\\PALETTES\\Services\\AdminPaletteListService($pdo);
$out = ['list' => $service->list(1), 'all' => $service->list(500), '133' => $service->list(1, '133'), '154' => $service->list(1, '154'), '13' => $service->list(1, '13'), '1330' => $service->list(1, '1330')];
if ($before !== $pdo->query('SELECT * FROM saved_palettes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)) throw new RuntimeException('Search modified palette data');
try { $service->list(1, '133 OR 1=1'); throw new RuntimeException('Invalid ID accepted'); } catch (InvalidArgumentException $e) {}
echo json_encode($out);
` });
assert.equal(php.status, 0, php.stderr);
const results = JSON.parse(php.stdout);
assert.deepEqual(results.list.map(p => p.id), [2], 'Older palette is outside the limited inventory');
assert.deepEqual(results['133'].map(p => p.id), [133]);
assert.deepEqual(results['154'].map(p => p.id), [154]);
assert.equal(results['133'][0].is_public, 0, 'Private inactive palette is found without publication');
assert.deepEqual(results['13'], [], 'Partial ID must not match 133 or 1330');
console.log('PASS PALETTES service: exact IDs, outside list limit, private/inactive lookup, invalid input, no data changes');

const entry = `import React from 'react';
import {createRoot} from 'react-dom/client';
import {MemoryRouter} from 'react-router-dom';
import {AdminDialogProvider} from '/src/components/AdminLayout/index.jsx';
import AdminPalettesPage from '/src/pages/AdminPalettesPage/index.jsx';
createRoot(document.getElementById('root')).render(React.createElement(MemoryRouter,null,React.createElement(AdminDialogProvider,null,React.createElement(AdminPalettesPage))));`;
const server = await createServer({
  logLevel: 'error', server: {host: '127.0.0.1', port: 0, open: false},
  plugins: [{name: 'palette-search-fixture', resolveId(id) {if(id === '/__palette-search.jsx') return id;}, load(id) {if(id === '/__palette-search.jsx') return entry;}}],
});
let browser;
try {
  await server.listen();
  const origin = `http://127.0.0.1:${server.httpServer.address().port}`;
  const html = await server.transformIndexHtml('/__palette-search', '<!doctype html><div id="root"></div><script type="module" src="/__palette-search.jsx"></script>');
  browser = await chromium.launch();
  for (const width of [1280, 390]) {
    const context = await browser.newContext({viewport: {width, height: 844}});
    const requests = [];
    await context.route('**/api/**', async route => {
      assert.equal(route.request().method(), 'GET', 'Search must never write');
      const url = new URL(route.request().url());
      if (url.pathname.endsWith('/palettes/list.php')) {
        const id = url.searchParams.get('id');
        requests.push(id);
        return route.fulfill({json: {ok: true, items: id === null ? results.all.filter(p => ![133,154].includes(p.id)) : results[id] || []}});
      }
      return route.fulfill({json: {ok: true, items: [], user: {is_admin: true}}});
    });
    await context.route('**/__palette-search', route => route.fulfill({contentType: 'text/html', body: html}));
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${origin}/__palette-search`);
    const rows = page.locator('.admin-palette-list__row');
    await rows.first().waitFor();
    const search = page.getByRole('searchbox', {name: 'Find palette by name or ID'});
    await search.fill('cOtTaGe');
    await page.waitForFunction(() => document.querySelectorAll('.admin-palette-list__row').length === 1);
    assert.match(await rows.first().innerText(), /Cottage 133 · #1330/);
    await search.fill('Ocean');
    await page.getByText('Ocean Blue · #2', {exact: true}).waitFor();
    assert.deepEqual(requests, [null], 'Name searches stay local');
    await page.getByLabel('Filter by visibility').selectOption('public');
    await page.getByLabel('Filter by palette type').selectOption('interior');
    await search.fill('133');
    await page.getByText('Hidden Cottage · #133', {exact: true}).waitFor();
    assert.equal(await rows.count(), 1);
    assert.match(await rows.first().innerText(), /Private/i);
    await search.fill('#133');
    await page.getByText('Hidden Cottage · #133', {exact: true}).waitFor();
    await search.fill('154');
    await page.getByText('Foothill Salmon Fix · #154', {exact: true}).waitFor();
    await search.fill('#154');
    await page.getByText('Foothill Salmon Fix · #154', {exact: true}).waitFor();
    await search.fill('13');
    await page.getByText('No palettes match those filters.', {exact: true}).waitFor();
    await search.fill('1330');
    await page.getByText('Cottage 133 · #1330', {exact: true}).waitFor();
    assert.equal(await rows.count(), 1);
    await search.fill('Ocean');
    await page.getByText('Ocean Blue · #2', {exact: true}).waitFor();
    assert.deepEqual(errors, []);
    console.log(`PASS ${width}px editor: name, numeric/# ID, exact match, 133/154 beyond inventory, filters bypassed for ID only, IDs displayed, name search restored`);
    await context.close();
  }
} finally {
  await browser?.close();
  await server.close();
}
