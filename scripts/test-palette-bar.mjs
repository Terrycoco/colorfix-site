import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createServer } from 'vite';
import { chromium } from 'playwright';

// Real Context, router, shared bar, Gallery and ArtworkPage; every API is isolated.
const inspect = process.argv.includes('--inspect');
const key = 'pals.palette.v1';
const colors = Array.from({ length: 6 }, (_, i) => ({ id: i + 1, name: `Bar color ${i + 1}`, brand: 'sw', hex: '123456', r: 18, g: 52, b: 86, hcl_h: 10, hcl_c: 20, hcl_l: 50 }));
const art = { id: 746, item_type: 'art-swatch', artwork_id: 9, photo_library_id: 746, photo_url: '/fixture.svg', title: 'Independent artwork' };
const server = await createServer({ server: { host: '127.0.0.1', port: 0, proxy: {} }, logLevel: 'error' });
let browser;
try {
  await server.listen();
  const origin = `http://127.0.0.1:${server.httpServer.address().port}`;
  browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const calls = [], errors = [];
  let holdLookup = null, lookupStatus = 200;
  await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.pathname.endsWith('/get-swatch.php')) {
      calls.push(url.searchParams.get('id'));
      if (holdLookup) await holdLookup;
      if (lookupStatus !== 200) return route.fulfill({ status: lookupStatus, json: { error: 'Fixture lookup unavailable' } });
      return route.fulfill({ json: colors.find(c => String(c.id) === url.searchParams.get('id')) });
    }
    if (url.pathname.includes('/api/')) {
      if (url.pathname.includes('get-categories')) return route.fulfill({ json: [] });
      if (url.pathname.includes('get-search-presets')) return route.fulfill({ json: [] });
      if (url.pathname.includes('image-thumb')) return route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="520" height="390"/>' });
      if (url.pathname.includes('artwork/page.php')) return route.fulfill({ json: { ok: true, data: { artwork: { display_title: 'Independent artwork' }, photo: {}, paint_approximations: { members: [{ color_id: 999, name: 'Artwork-only paint', hex: 'abcdef' }] }, inspired_designs: [] } } });
      if (url.pathname.includes('run-query')) return route.fulfill({ json: { results: [...colors.slice(0, 5).map(c => ({ ...c, item_type: 'swatch' })), art], inserts: [], meta: { gallery_owns_photos: true, item_type: 'swatch' } } });
      if (url.pathname.includes('front-page')) return route.fulfill({ json: { results: [], inserts: [], meta: {} } });
      return route.fulfill({ json: { ok: true, event_id: 0, success: true, user: null, categories: [], schemes: [], items: [], data: [] } });
    }
    if (url.origin !== origin) return route.abort();
    if (request.resourceType() === 'document') return route.fulfill({ contentType: 'text/html', body: await server.transformIndexHtml(url.pathname, await readFile('tests/palette-bar/index.html', 'utf8')) });
    return route.continue();
  });
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  const palette = () => page.evaluate(() => window.paletteBarTest.palette);
  const stored = () => page.evaluate(key => JSON.parse(localStorage.getItem(key)), key);
  const waitIds = async ids => {
    await page.waitForFunction(ids => JSON.stringify(window.paletteBarTest?.palette.map(c => Number(c.id))) === JSON.stringify(ids), ids);
  };
  const navigate = async pathname => {
    await page.evaluate(pathname => { history.pushState({ ...history.state, key: crypto.randomUUID(), idx: (history.state?.idx || 0) + 1 }, '', pathname); window.dispatchEvent(new PopStateEvent('popstate')); }, pathname);
    await page.waitForTimeout(200);
  };
  await page.goto(origin + '/');
  await page.waitForFunction(() => Boolean(window.paletteBarTest));
  if (inspect) {
    await page.evaluate(({ key, colors }) => localStorage.setItem(key, JSON.stringify(colors)), { key, colors: colors.slice(0, 5) });
    await page.reload(); await waitIds([1, 2, 3, 4, 5]);
    const first = calls.length;
    const provider = await page.evaluate(() => window.paletteBarTest.providerId);
    await navigate('/results/5');
    await page.locator('a.pals-art').click();
    await page.getByRole('heading', { name: 'Independent artwork', exact: true }).waitFor();
    await waitIds([1, 2, 3, 4, 5]);
    console.log(JSON.stringify({ restorationRequests: first, artworkNavigationRequests: calls.length - first, artworkRemountedProvider: provider !== await page.evaluate(() => window.paletteBarTest.providerId) }));
  } else {
    await page.evaluate(colors => window.paletteBarTest.addManyToPalette(colors), colors.slice(0, 5));
    await waitIds([1, 2, 3, 4, 5]);
    assert.equal(calls.length, 0, 'Complete input needs no fetch');
    await page.waitForFunction(() => document.querySelectorAll('.palette-bar-swatch').length === 5);
    assert.deepEqual(await page.locator('.palette-bar-swatch').evaluateAll(nodes => nodes.map(n => n.title)), colors.slice(0, 5).map(c => c.name));
    assert.equal((await stored())[0].name, colors[0].name);
    const provider = await page.evaluate(() => window.paletteBarTest.providerId);
    await navigate('/results/5');
    await page.locator('a.pals-art').click();
    await page.getByRole('heading', { name: 'Independent artwork', exact: true }).waitFor();
    assert.equal(await page.evaluate(() => window.paletteBarTest.providerId), provider, 'Actual Gallery → artwork link keeps provider mounted');
    assert(await page.getByText('Artwork-only paint', { exact: true }).isVisible());
    await page.getByRole('button', { name: 'Go back', exact: true }).click();
    await page.waitForURL(url => url.pathname === '/results/5');
    for (const route of ['/', '/privacy', '/results/5', '/inspired-by/9']) {
      await navigate(route); await waitIds([1, 2, 3, 4, 5]);
      assert.equal(await page.evaluate(() => window.paletteBarTest.providerId), provider);
      assert.equal(await page.locator('.palette-bar-swatch').count(), 5, 'Shared bar keeps all five display swatches');
    }
    assert.equal(calls.length, 0, 'Zero swatch fetches during client-side navigation');
    await page.reload(); await waitIds([1, 2, 3, 4, 5]);
    assert.equal(calls.length, 0, 'Complete persisted objects restore without fetches');
    for (const route of ['/', '/results/5', '/inspired-by/9', '/privacy']) await navigate(route);
    assert.equal(calls.length, 0);
    await page.evaluate(() => window.paletteBarTest.removeFromPalette(2)); await waitIds([1, 3, 4, 5]);
    assert.deepEqual((await stored()).map(c => c.id), [1, 3, 4, 5]);
    await page.evaluate(color => window.paletteBarTest.addToPalette(color), colors[5]); await waitIds([1, 3, 4, 5, 6]);
    assert.equal((await stored()).at(-1).name, 'Bar color 6');
    await page.evaluate(() => window.paletteBarTest.addToPalette(1));
    await page.waitForTimeout(100); assert.equal(calls.length, 0, 'Adding an existing ID reuses Context details');
    await page.evaluate(() => window.paletteBarTest.reorderPalette([...window.paletteBarTest.palette].reverse()));
    await waitIds([6, 5, 4, 3, 1]); assert.deepEqual((await stored()).map(c => c.id), [6, 5, 4, 3, 1]);
    await page.evaluate(colors => window.paletteBarTest.setPalette(colors), colors.slice(2, 5)); await waitIds([3, 4, 5]);
    assert.deepEqual((await stored()).map(c => c.id), [3, 4, 5], 'Existing setter replaces and persists the palette');
    await page.evaluate(() => window.paletteBarTest.clearPalette()); await waitIds([]); assert.deepEqual(await stored(), []);
    // Backward-compatible migration of the existing ID-only persistence format.
    await page.evaluate(key => localStorage.setItem(key, JSON.stringify([1, 2, 3, 4, 5])), key);
    const before = calls.length;
    await page.reload(); await waitIds([1, 2, 3, 4, 5]);
    assert.equal(calls.length - before, 5, 'Legacy IDs hydrate once each, even under StrictMode');
    assert((await stored()).every(c => c.name && c.brand && c.hex));
    await page.reload(); await waitIds([1, 2, 3, 4, 5]);
    assert.equal(calls.length - before, 5, 'Migrated reload never fetches again');
    // Storage events are tested with a real second tab, including clear/removal.
    const other = await context.newPage(); await other.goto(origin + '/privacy'); await other.waitForFunction(() => window.paletteBarTest?.palette.length === 5);
    await other.evaluate(() => window.paletteBarTest.removeFromPalette(3)); await waitIds([1, 2, 4, 5]);
    await other.evaluate(() => window.paletteBarTest.clearPalette()); await waitIds([]);
    assert.equal(calls.length - before, 5, 'Cross-tab removal and clear do not refetch or resurrect entries');
    await other.close();
    // RGB used by the shared bar can be derived from a complete hex-only DTO.
    await page.evaluate(color => window.paletteBarTest.addToPalette({ ...color, r: undefined, g: undefined, b: undefined }), colors[5]);
    await waitIds([6]);
    assert.deepEqual((await palette()).map(c => [c.r, c.g, c.b]), [[18, 52, 86]]);
    assert.equal(calls.length - before, 5, 'Hex-only complete objects need no lookup');
    await page.evaluate(() => window.paletteBarTest.clearPalette()); await waitIds([]);
    const missingBefore = calls.length;
    await page.evaluate(() => window.paletteBarTest.addToPalette(6)); await waitIds([6]);
    assert.equal(calls.length - missingBefore, 1, 'New bare ID fetches details once');
    assert.equal((await stored())[0].name, 'Bar color 6');
    // A slow legacy restore must not resurrect a palette cleared by the user.
    let release;
    holdLookup = new Promise(resolve => { release = resolve; });
    await page.evaluate(key => localStorage.setItem(key, '[6]'), key);
    const heldBefore = calls.length;
    await page.reload();
    await page.waitForFunction(() => Boolean(window.paletteBarTest));
    while (calls.length === heldBefore) await page.waitForTimeout(20);
    await page.evaluate(() => window.paletteBarTest.clearPalette()); await waitIds([]);
    release(); holdLookup = null;
    await page.waitForTimeout(200);
    assert.deepEqual(await stored(), []); assert.deepEqual(await palette(), []);
    // A temporary lookup failure must not erase persisted legacy IDs.
    lookupStatus = 503;
    await page.evaluate(key => localStorage.setItem(key, '[6]'), key);
    const failureBefore = calls.length;
    await page.reload(); await page.waitForFunction(() => Boolean(window.paletteBarTest));
    while (calls.length - failureBefore < 5) await page.waitForTimeout(20);
    await page.waitForTimeout(250);
    assert.deepEqual(await stored(), [6], 'Failed restore preserves original IDs for retry');
    lookupStatus = 200;
    await page.reload(); await waitIds([6]);
    assert.equal((await stored())[0].name, 'Bar color 6');
    assert.deepEqual(errors, []);
    console.log('PASS: real Context/router/bar; five swatches; Home/Gallery/artwork/privacy navigation; stable provider; independent artwork paint; zero navigation or complete-object reload fetches; legacy restore exactly 5 then 0; add/remove/replace/reorder/clear persistence and cross-tab sync. All APIs intercepted.');
  }
  await context.close();
} finally { await browser?.close(); await server.close(); }
