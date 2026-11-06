import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';
import { createServer } from 'vite';
import { openBrowser } from '@remotion/renderer';

const executable = process.env.COLORFIX_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
assert.ok(existsSync(executable), 'Set COLORFIX_CHROME to an installed Chrome executable.');
const server = await createServer({ server: { host: '127.0.0.1', port: 0 }, logLevel: 'error' });
let browser;
try {
  await server.listen();
  const port = server.httpServer.address().port;
  browser = await openBrowser('chrome', { browserExecutable: executable, chromeMode: 'chrome-for-testing', logLevel: 'error' });
  const page = await browser.newPage({ context: null, logLevel: 'error', indent: false, pageIndex: 0, onBrowserLog: null, onLog: () => {} });
  const client = page._client();
  async function click(selector, text) {
    const point = await page.evaluate((selector, text) => {
      const node = [...document.querySelectorAll(selector)].find((node) => !text || node.textContent.trim() === text);
      if (!node) throw new Error(`Missing ${selector}: ${text}`);
      node.scrollIntoView({ block: 'nearest' });
      const box = node.getBoundingClientRect();
      const x = box.left + box.width / 2;
      const y = box.top + box.height / 2;
      if (!node.contains(document.elementFromPoint(x, y))) throw new Error(`Occluded ${selector}: ${text}`);
      return { x, y };
    }, selector, text);
    for (const type of ['mousePressed', 'mouseReleased']) await client.send('Input.dispatchMouseEvent', { type, ...point, button: 'left', clickCount: 1 });
    await delay(80);
  }
  const state = () => page.evaluate(() => window.brandFilterTest.state);
  const checked = (brand) => page.evaluate((brand) => [...document.querySelectorAll('.bf-row')].find((node) => node.textContent.trim() === brand).querySelector('input').checked, brand);
  const persisted = () => page.evaluate(() => JSON.parse(localStorage.getItem('pals.brand_filters.v1')));
  const isOpen = () => page.evaluate(() => Boolean(document.querySelector('.bf-modal')));
  async function escape() {
    await client.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    await client.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape' });
    await delay(80);
  }

  for (const width of [1280, 390]) {
    await page.setViewport({ width, height: 900, deviceScaleFactor: 1, isMobile: width < 721, hasTouch: width < 721 });
    await page.goto({ url: `http://127.0.0.1:${port}/tests/brand-filters/`, timeout: 20000 });
    for (let i = 0; i < 100; i++) {
      if (await page.evaluate(() => Boolean(window.brandFilterTest?.state))) break;
      await delay(40);
    }
    await delay(350);
    const initial = { brands: ['behr', 'bm', 'sw'], applied: 0 };
    assert.deepEqual(await state(), initial);
    const initialRequests = await page.evaluate(() => window.brandFilterTest.requests.length);
    await click('button', 'Open brand filters');
    await click('.bf-row', 'Behr');
    await click('.bf-row', 'Benjamin Moore');
    assert.equal(await checked('Behr'), false);
    assert.equal(await checked('Benjamin Moore'), false);
    assert.deepEqual(await state(), initial, 'Toggling must not change shared filters or Apply sequence');
    assert.deepEqual(await persisted(), initial.brands, 'Toggling must not persist a draft');
    assert.equal(await page.evaluate(() => window.brandFilterTest.requests.length), initialRequests, 'Toggling must not request new results');
    assert.equal(await isOpen(), true);

    await click('button', 'Clear');
    assert.equal(await page.evaluate(() => document.querySelectorAll('.bf-checkbox:checked').length), 0);
    assert.deepEqual(await state(), initial, 'Clear remains pending');
    assert.deepEqual(await persisted(), initial.brands);
    assert.equal(await isOpen(), true);
    await click('button', 'Done');
    assert.equal(await isOpen(), false);
    assert.deepEqual(await state(), { brands: [], applied: 1 }, 'Done commits cleared selection exactly once');
    assert.deepEqual(await persisted(), []);

    await click('button', 'Open brand filters');
    await click('.bf-row', 'Sherwin-Williams');
    await click('button', 'Done');
    assert.deepEqual(await state(), { brands: ['sw'], applied: 2 });
    assert.deepEqual(await persisted(), ['sw']);
    await click('button', 'Open brand filters');
    assert.equal(await checked('Sherwin-Williams'), true);
    await click('.bf-row', 'Sherwin-Williams');
    await escape();
    assert.equal(await isOpen(), false);
    assert.deepEqual(await state(), { brands: ['sw'], applied: 2 }, 'Escape discards the draft');

    await click('button', 'Open brand filters');
    assert.equal(await checked('Sherwin-Williams'), true, 'Reopen restores committed brands');
    await click('button', 'Clear');
    await delay(220);
    for (const type of ['mousePressed', 'mouseReleased']) await client.send('Input.dispatchMouseEvent', { type, x: 2, y: 2, button: 'left', clickCount: 1 });
    await delay(80);
    assert.equal(await isOpen(), false);
    assert.deepEqual(await state(), { brands: ['sw'], applied: 2 }, 'Backdrop dismissal discards Clear');
    await click('button', 'Open brand filters');
    assert.equal(await checked('Sherwin-Williams'), true);
    await click('button', 'Done');
    assert.deepEqual(await state(), { brands: ['sw'], applied: 2 }, 'Unchanged Done must not reapply');
    assert.deepEqual(await page.evaluate(() => window.brandFilterTest.errors), [], 'No blank-page exceptions');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('h1'))), true);
    assert.equal(await page.evaluate(() => document.body.style.overflow), '');
    console.log(`PASS brand-filter drafts, deferred Clear, single Done commit, persistence, Escape/backdrop discard, reopening, no runtime errors (${width}px)`);
  }
  async function waitFor(predicate, label) {
    for (let i = 0; i < 150; i++) {
      if (await page.evaluate(predicate)) return;
      await delay(40);
    }
    throw new Error(`Timed out: ${label}; ${await page.evaluate(() => document.body.innerText.slice(0, 1200))}`);
  }
  for (const width of [1280, 390]) {
    await page.setViewport({ width, height: 900, deviceScaleFactor: 1, isMobile: width < 721, hasTouch: width < 721 });
    await page.goto({ url: `http://127.0.0.1:${port}/tests/brand-filters/?palette=1`, timeout: 20000 });
    await waitFor(() => window.brandFilterTest?.palette?.length === 2, 'palette hydration');
    assert.deepEqual(await state(), { brands: ['sw'], applied: 0 });
    assert.equal(await page.evaluate(() => document.querySelectorAll('.myp-palette-grid .pals-swatch').length), 2, 'Mixed-brand collection must show every picked color');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.myp-palette-grid #swatch-2'))), true, 'BM color stays visible with SW filter');
    if (width < 721) { await click('.myp-fab-add'); await delay(300); }
    await click('.myp-fuzzy input');
    await client.send('Input.insertText', { text: 'Fixture' });
    await client.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
    await client.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter' });
    await waitFor(() => Boolean(document.querySelector('.results')), 'cross-brand fuzzy results');
    assert.equal(await page.evaluate(() => document.querySelectorAll('.results .result-item').length), 3, 'Fuzzy results include all brands');
    const lookup = new URL(await page.evaluate(() => window.brandFilterTest.requests.findLast((url) => url.includes('/fuzzy-search.php'))));
    assert.equal(lookup.searchParams.has('brands'), false);
    assert.equal(lookup.searchParams.has('brand'), false);
    await click('.result-item .color-name', 'Fixture Behr');
    await waitFor(() => window.brandFilterTest.palette.length === 3, 'adding cross-brand fuzzy selection');
    if (width < 721) { await click('.drawer-back'); await delay(300); }
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.myp-palette-grid #swatch-3'))), true, 'Explicit Behr pick remains visible');
    assert.deepEqual(await page.evaluate(() => JSON.parse(localStorage.getItem('pals.palette.v1'))), [1, 2, 3]);
    if (width < 721) { await click('.myp-fab-add'); await delay(300); }
    await click('button', 'What Colors Go?');
    await waitFor(() => window.brandFilterTest.requests.some((url) => url.includes('/get-friends.php')), 'suggestion query');
    const suggestions = new URL(await page.evaluate(() => window.brandFilterTest.requests.findLast((url) => url.includes('/get-friends.php'))));
    assert.equal(suggestions.searchParams.get('brands'), 'sw', 'Browsing suggestions still honor the brand filter');
    assert.equal(suggestions.searchParams.get('ids'), '1,2,3', 'All chosen colors anchor suggestions, regardless of brand');
    if (width < 721) { await click('.drawer-back'); await delay(300); }
    await click('button', 'Open brand filters');
    await click('.bf-row', 'Sherwin-Williams');
    await click('.bf-row', 'Benjamin Moore');
    await click('button', 'Done');
    assert.deepEqual(await state(), { brands: ['bm'], applied: 1 });
    assert.equal(await page.evaluate(() => document.querySelectorAll('.myp-palette-grid .pals-swatch').length), 3, 'Changing browsing filters cannot hide chosen colors');
    assert.deepEqual(await page.evaluate(() => window.brandFilterTest.palette), [1, 2, 3]);
    assert.deepEqual(await page.evaluate(() => window.brandFilterTest.errors), []);
    console.log(`PASS cross-brand fuzzy lookup, adding/persisting picks, complete MyPalette collection, filter-independent anchors (${width}px)`);
    if (width < 721) { await click('.myp-fab-add'); await delay(300); }
    await click('button', 'See In All Brands');
    await waitFor(() => window.brandFilterTest.route.startsWith('/palette/translate?ids='), 'translation route');
    assert.equal(new URL(await page.evaluate(() => window.brandFilterTest.route), 'https://fixture.test').searchParams.get('ids'), '1,2,3', 'Every selected color translates without requiring cluster metadata');
    await waitFor(() => document.body.innerText.includes('Your Palette in All Brands'), 'translated palettes');
    assert.equal(await page.evaluate(() => document.querySelectorAll('.translation-page .sg-group-header').length), 3);
    assert.equal(await page.evaluate(() => document.querySelectorAll('.translation-page .pals-swatch').length), 9);
    assert.deepEqual(await page.evaluate(() => window.brandFilterTest.palette), [1, 2, 3]);
    assert.deepEqual(await state(), { brands: ['bm'], applied: 1 });
    assert.equal(await page.evaluate(() => window.brandFilterTest.requests.some((url) => url.includes('translate-palette-controller.php'))), false, 'MyPalette bypasses the stalled legacy service');
    assert.deepEqual(await page.evaluate(() => window.brandFilterTest.errors), []);
    console.log(`PASS MyPalette See In All Brands → complete translated palettes across brands (${width}px)`);
  }

  await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 1 });
  await page.goto({ url: `http://127.0.0.1:${port}/tests/brand-filters/?palette=1`, timeout: 20000 });
  await waitFor(() => window.brandFilterTest?.palette?.length === 2, 'retry fixture hydration');
  await page.evaluate(() => { window.brandFilterTest.failTranslation = true; });
  await click('button', 'See In All Brands');
  await waitFor(() => document.body.innerText.includes('HTTP 503'), 'translation error');
  assert.equal(await page.evaluate(() => document.body.innerText.includes('Translating palette…')), false);
  await page.evaluate(() => { window.brandFilterTest.failTranslation = false; });
  await click('button', 'Try again');
  await waitFor(() => document.body.innerText.includes('Your Palette in All Brands'), 'translation retry success');
  assert.equal(await page.evaluate(() => document.querySelectorAll('.translation-page .pals-swatch').length), 6);
  assert.deepEqual(await page.evaluate(() => window.brandFilterTest.errors), []);
  console.log('PASS translation error exits loading and Try again restores results');

} finally {
  await browser?.close({ silent: true });
  await server.close();
}
