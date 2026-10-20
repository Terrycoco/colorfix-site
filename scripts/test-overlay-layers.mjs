import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';
import { createServer } from 'vite';
import { openBrowser } from '@remotion/renderer';

// Uses production components with fixture responses, never live recovery/delete APIs.
const executable = process.env.COLORFIX_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
assert.ok(existsSync(executable), 'Set COLORFIX_CHROME to an installed Chrome executable.');
const server = await createServer({ server: { host: '127.0.0.1', port: 0 }, logLevel: 'error' });
let browser;
try {
  await server.listen();
  const port = server.httpServer.address().port;
  browser = await openBrowser('chrome', { browserExecutable: executable, chromeMode: 'chrome-for-testing', logLevel: 'error' });
  const errors = [];
  const page = await browser.newPage({ context: null, logLevel: 'error', indent: false, pageIndex: 0, onBrowserLog: null, onLog: () => {} });
  page.on('error', (error) => errors.push(error.message));
  const client = page._client();
  async function waitFor(fn, label) {
    const deadline = Date.now() + 8000;
    while (Date.now() < deadline) {
      if (await page.evaluate(fn)) return;
      await delay(40);
    }
    throw new Error(`Timed out: ${label}; errors=${JSON.stringify(errors)}; DOM=${await page.evaluate(() => document.body.innerText.slice(0, 1600))}`);
  }
  async function load(width = 1280) {
    await page.setViewport({ width, height: 900, deviceScaleFactor: 1, isMobile: width < 721, hasTouch: width < 721 });
    await page.goto({ url: `http://127.0.0.1:${port}/tests/overlays/`, timeout: 20000 });
    await waitFor(() => Boolean(window.overlayFixture), 'fixture mount');
    await delay(100);
  }
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
    await client.send('Input.dispatchMouseEvent', { type: 'mousePressed', ...point, button: 'left', clickCount: 1 });
    await client.send('Input.dispatchMouseEvent', { type: 'mouseReleased', ...point, button: 'left', clickCount: 1 });
    await delay(70);
  }
  async function key(key, modifiers = 0) {
    await client.send('Input.dispatchKeyEvent', { type: 'keyDown', key, code: key, windowsVirtualKeyCode: key === 'Escape' ? 27 : key === 'Enter' ? 13 : 9, modifiers });
    await client.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code: key, modifiers });
    await delay(60);
  }
  const z = (selector) => page.evaluate((s) => Number(getComputedStyle(document.querySelector(s)).zIndex), selector);
  async function check(name, fn) { await fn(); console.log(`PASS ${name}`); }

  for (const width of [1280, 390]) {
    await check(`ERR Edit → Recovery and Delete (${width}px)`, async () => {
      await load(width);
      await click('button', 'ERR fixture');
      await waitFor(() => document.body.innerText.includes('Fixture incident'), 'ERR report');
      // Double-click a production grid row (both presentations support the same action).
      const row = await page.evaluate(() => {
        const candidate = [...document.querySelectorAll('tr, [role="row"], .mobile-data-grid__row')].find((node) => node.textContent.includes('Fixture incident'));
        if (!candidate) return null;
        const box = candidate.getBoundingClientRect();
        return { x: Math.min(innerWidth - 12, box.left + box.width / 2), y: box.top + box.height / 2 };
      });
      assert.ok(row, 'ERR grid row exists');
      for (const count of [1, 2]) {
        for (const type of ['mousePressed', 'mouseReleased']) await client.send('Input.dispatchMouseEvent', { type, ...row, button: 'left', clickCount: count });
        await delay(60);
      }
      await waitFor(() => Boolean(document.querySelector('.admin-workbench-drawer, .mobile-workbench-drawer')), 'ERR Edit');
      const drawerSelector = width < 721 ? '.mobile-workbench-drawer-overlay' : '.admin-workbench-drawer-host';
      await click('button', 'Recover');
      await waitFor(() => document.body.innerText.includes('Start Recovery'), 'Recovery plan');
      assert.ok(await z('.admin-dialog-backdrop') > await z(drawerSelector));
      await click('.admin-dialog__button', 'Cancel');
      assert.equal(await page.evaluate(() => Boolean(document.querySelector('.admin-dialog-backdrop'))), false);
      assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Recover');
      if (width < 721) assert.equal(await page.evaluate(() => document.body.style.overflow), 'hidden');
      await click('button', 'Delete Working Record');
      assert.ok(await z('.admin-dialog-backdrop') > await z(drawerSelector));
      await key('Escape');
      assert.equal(await page.evaluate(() => Boolean(document.querySelector('.admin-dialog-backdrop'))), false);
      assert.equal(await page.evaluate((s) => Boolean(document.querySelector(s)), drawerSelector), true);
      await key('Escape');
      assert.equal(await page.evaluate((s) => Boolean(document.querySelector(s)), drawerSelector), false);
      assert.equal(await page.evaluate(() => document.body.style.overflow), '');
    });
  }

  await check('Dialog → MobileSelect → return; Tab focus containment', async () => {
    await load(390);
    await click('button', 'Dialog fixture');
    await click('.admin-mobile-select__trigger');
    assert.ok(await z('.admin-mobile-select__overlay') > await z('.admin-dialog-backdrop'));
    await key('Tab', 8); // Shift+Tab from selector search should stay in selector.
    assert.equal(await page.evaluate(() => document.querySelector('.admin-mobile-select__overlay').contains(document.activeElement)), true);
    await key('Escape');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.admin-mobile-select__overlay'))), false);
    assert.equal(await page.evaluate(() => document.activeElement.classList.contains('admin-mobile-select__trigger')), true);
    assert.equal(await page.evaluate(() => document.body.style.overflow), 'hidden');
    await key('Escape');
    assert.equal(await page.evaluate(() => document.body.style.overflow), '');
  });

  await check('Dropdown escapes clipping; Escape closes menu before dialog', async () => {
    await load();
    await click('button', 'Dialog fixture');
    await click('.fancy-selected');
    assert.equal(await page.evaluate(() => document.querySelector('.fancy-options').parentElement === document.body), true);
    assert.ok(await z('.fancy-options') > await z('.admin-dialog-backdrop'));
    await click('.fancy-option-label', 'Beta');
    await click('.fancy-selected');
    await key('Escape');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.fancy-options'))), false);
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.admin-dialog-backdrop'))), true);
  });

  await check('Fuzzy desktop results and TagMultiSelect escape clipping', async () => {
    await load();
    await click('button', 'Dialog fixture');
    await click('.fuzzy-dropdown input');
    await client.send('Input.insertText', { text: 'Fixture' });
    await key('Enter');
    await waitFor(() => Boolean(document.querySelector('.results')), 'fuzzy results');
    assert.equal(await page.evaluate(() => document.querySelector('.results').parentElement === document.body), true);
    assert.ok(await z('.results') > await z('.admin-dialog-backdrop'));
    await click('.result-item');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.results'))), false);
    await click('.tag-select input');
    await client.send('Input.insertText', { text: 'Fixture' });
    await waitFor(() => Boolean(document.querySelector('.tag-dropdown')), 'tag results');
    assert.equal(await page.evaluate(() => document.querySelector('.tag-dropdown').parentElement === document.body), true);
    await click('.tag-suggestion');
    await key('Escape');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.admin-dialog-backdrop'))), true);
  });

  await check('Dialog → fuzzy mobile picker → return', async () => {
    await load(390);
    await click('button', 'Dialog fixture');
    await click('.fuzzy-mobile-trigger');
    assert.ok(await z('.fuzzy-sheet-host') > await z('.admin-dialog-backdrop'));
    await key('Escape');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.fuzzy-sheet-host'))), false);
    assert.equal(await page.evaluate(() => document.activeElement.classList.contains('fuzzy-mobile-trigger')), true);
    assert.equal(await page.evaluate(() => document.body.style.overflow), 'hidden');
  });

  await check('EditableSwatch → mobile picker stays open on input; Escape returns through owners', async () => {
    await load(390);
    await click('button', 'Dialog fixture');
    await click('.ed-swatch__btn');
    assert.equal(await page.evaluate(() => document.querySelector('.ed-swatch__popover').parentElement === document.body), true);
    await click('.ed-swatch__popover .fuzzy-mobile-trigger');
    await click('.fuzzy-sheet-input');
    await client.send('Input.insertText', { text: 'Fixture' });
    await key('Enter');
    await waitFor(() => Boolean(document.querySelector('.fuzzy-sheet-item')), 'editable swatch picker results');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.ed-swatch__popover'))), true);
    await key('Escape');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.ed-swatch__popover'))), true);
    await key('Escape');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.ed-swatch__popover'))), false);
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.admin-dialog-backdrop'))), true);
  });

  await check('MobileGridDrawer → feedback → return', async () => {
    await load(390);
    await click('button', 'Grid drawer fixture');
    await click('button', 'Grid feedback');
    assert.ok(await z('.admin-dialog-backdrop') > await z('.mobile-grid-drawer-overlay'));
    await key('Escape');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.mobile-grid-drawer-overlay'))), true);
    assert.equal(await page.evaluate(() => document.body.style.overflow), 'hidden');
    await key('Escape');
    assert.equal(await page.evaluate(() => document.body.style.overflow), '');
  });

  await check('PUB MusicEditor → feedback; busy Escape; toast above overlays', async () => {
    await load();
    await click('button', 'PUB fixture');
    await click('button', 'Show toast');
    await click('button', 'Open Music');
    assert.equal(await page.evaluate(() => document.querySelectorAll('[role="dialog"]').length), 2);
    await page.evaluate(() => window.overlayFixture.feedback());
    await waitFor(() => document.body.innerText.includes('Fixture Feedback'), 'PUB feedback');
    const feedbackZ = await page.evaluate(() => Number(getComputedStyle([...document.querySelectorAll('.admin-dialog-backdrop')].at(-1)).zIndex));
    const musicZ = await page.evaluate(() => Number(getComputedStyle(document.querySelector('[aria-label="Music"]').parentElement).zIndex));
    assert.ok(feedbackZ > musicZ);
    assert.ok(await z('.app-toast') > feedbackZ);
    assert.ok(await z('.admin-toast-region') > feedbackZ);
    await click('.app-toast__close'); // A real pointer hit above every overlay.
    await key('Escape');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('[aria-label="Music"]'))), true);
    await page.evaluate(() => window.overlayFixture.busy(true));
    await delay(60);
    await key('Escape');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('[aria-label="Music"]'))), true);
    await page.evaluate(() => window.overlayFixture.busy(false));
    await delay(60);
    await key('Escape');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('[aria-label="Music"]'))), false);
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.admin-dialog-backdrop'))), true);
    await key('Escape');
    assert.equal(await page.evaluate(() => document.body.style.overflow), '');
  });

  await check('Editor → feedback; Escape leaves editor open; restoration', async () => {
    await load();
    await click('button', 'Editor fixture');
    await click('button', 'Editor feedback');
    await key('Escape');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.admin-editor-backdrop'))), true);
    assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Editor feedback');
    await key('Escape');
    assert.equal(await page.evaluate(() => document.body.style.overflow), '');
    assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Editor fixture');
  });
  await check('Editor → fullscreen photo preview → return', async () => {
    await load();
    await click('button', 'Editor fixture');
    await click('.render-img');
    assert.equal(await page.evaluate(() => document.querySelector('.full-overlay').parentElement === document.body), true);
    assert.ok(await z('.full-overlay') > await z('.admin-editor-backdrop'));
    await key('Escape');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.full-overlay'))), false);
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.admin-editor-backdrop'))), true);
    assert.equal(await page.evaluate(() => document.body.style.overflow), 'hidden');
    await key('Escape');
    assert.equal(await page.evaluate(() => document.body.style.overflow), '');
  });
  await check('Saved palette editor → painter note → return', async () => {
    await load();
    await click('button', 'Saved palette fixture');
    await waitFor(() => Boolean(document.querySelector('button[aria-label="Add painter note"]')), 'palette member');
    await click('button[aria-label="Add painter note"]');
    assert.equal(await page.evaluate(() => document.querySelector('.asp-note-overlay').parentElement === document.body), true);
    assert.ok(await z('.asp-note-overlay') > await z('.asp-modal-backdrop'));
    await key('Escape');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.asp-note-overlay'))), false);
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.asp-modal-backdrop'))), true);
    await key('Escape');
    assert.equal(await page.evaluate(() => document.body.style.overflow), '');
  });

  await check('Palette inspector body portal and Escape', async () => {
    await load();
    await click('button', 'Inspector fixture');
    assert.equal(await page.evaluate(() => document.querySelector('.pi-overlay').parentElement === document.body), true);
    await key('Escape');
    assert.equal(await page.evaluate(() => Boolean(document.querySelector('.pi-overlay'))), false);
    assert.equal(await page.evaluate(() => document.body.style.overflow), '');
  });

  await check('Player CSS preserves image, end-screen, and control ordering locally', async () => {
    await load();
    await click('button', 'Player layers fixture');
    const selectors = ['.player-image.is-prev', '.player-image.is-current', '.player-hue-wheel-slide', '.player-like', '.player-end-footer', '.player-end-actions', '.player-end-screen', '.player-end-screen .player-exit', '.player-root > .player-exit', '.player-palette-prompt', '.player-advance-hint', '.player-back--end-screen'];
    const levels = await page.evaluate((selectors) => selectors.map((selector) => Number(getComputedStyle(document.querySelector(selector)).zIndex)), selectors);
    for (let i = 1; i < levels.length; i++) assert.ok(levels[i] > levels[i - 1], `${selectors[i]} above ${selectors[i - 1]}`);
    assert.equal(await page.evaluate(() => getComputedStyle(document.querySelector('.player-root')).isolation), 'isolate');
  });
  assert.deepEqual(errors, [], 'No uncaught browser errors');
} finally {
  await browser?.close({ silent: true });
  await server.close();
}
