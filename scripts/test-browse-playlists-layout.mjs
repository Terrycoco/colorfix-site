import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { tmpdir } from 'node:os';
import { chromium } from 'playwright';

// Actual compiled app/router/CSS. All APIs are fixtures, including ANA recording.
const inspect = process.argv.includes('--inspect');
const baselineArgument = process.argv.find(value => value.startsWith('--baseline='));
const baselinePath = path.join(tmpdir(), 'colorfix-browse-container-baseline.json');
const report = [];
let server, browser;
try {
  let origin = '';
  if (!origin) {
    const root = path.resolve('dist');
    server = createServer(async (request, response) => {
      try {
        let file = path.resolve(root, '.' + decodeURIComponent(new URL(request.url, 'http://fixture').pathname));
        if (!file.startsWith(root + path.sep) && file !== root) throw Error('Invalid fixture path');
        try { if ((await stat(file)).isDirectory()) file = path.join(file, 'index.html'); }
        catch { file = path.join(root, 'index.html'); }
        response.setHeader('Content-Type', ({ '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml', '.webp': 'image/webp', '.woff2': 'font/woff2' })[path.extname(file)] || 'application/octet-stream');
        response.end(await readFile(file));
      } catch { response.statusCode = 404; response.end('Not found'); }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
  }
  browser = await chromium.launch({ headless: true });
  for (const viewport of [{ width: 1920, height: 1080 }, { width: 1280, height: 800 }, { width: 390, height: 844 }]) {
    const context = await browser.newContext({ viewport });
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.origin !== origin) return route.abort();
      if (!url.pathname.startsWith('/api/')) return route.continue();
      if (url.pathname.endsWith('/get-search-presets.php')) return route.fulfill({ json: [] });
      if (url.pathname.includes('image-thumb')) return route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="520" height="390"><rect width="520" height="390" fill="#b5c3b8"/></svg>' });
      if (url.pathname.includes('playlist-sets/get')) return route.fulfill({ json: { ok: true, set: { id: 11, title: 'Legacy fixture set', items: Array.from({ length: 24 }, (_, i) => ({ id: i + 1, title: `Playlist ${i + 1}`, item_type: 'playlist', rex_url: `/t/existing-token-${i + 1}`, photo_library_id: i + 1 })) } } });
      return route.fulfill({ json: { ok: true, event_id: 0, user: null, categories: [], schemes: [], items: [], data: [] } });
    });
    const page = await context.newPage();
    const measure = () => page.evaluate(() => {
      const box = element => { const r = element.getBoundingClientRect(), s = getComputedStyle(element); return { top: r.top, bottom: r.bottom, left: r.left, right: r.right, width: r.width, height: r.height, paddingLeft: s.paddingLeft, paddingRight: s.paddingRight, paddingTop: s.paddingTop, marginLeft: s.marginLeft, marginRight: s.marginRight, maxWidth: s.maxWidth, background: s.backgroundColor, display: s.display, position: s.position, justifyContent: s.justifyContent, alignItems: s.alignItems, gridTemplateColumns: s.gridTemplateColumns, gap: s.gap, overflowY: s.overflowY, maxHeight: s.maxHeight, scrollTop: element.scrollTop, clientHeight: element.clientHeight, scrollHeight: element.scrollHeight }; };
      const selectors = ['html', 'body', '#root', '.app', '.main-layout', '.board-scroller', '.standalone-layout', '.playlist-picker', '.playlist-thumbs__panel', '.playlist-thumbs__header', '.playlist-picker__brand img', 'h1', '.playlist-picker__tagline', '.playlist-thumbs__header-row p:last-child', '.playlist-thumbs__grid-wrap', '.playlist-thumbs__grid', '.playlist-thumbs__card:first-child', '.playlist-thumbs__image', '.playlist-thumbs__card:last-child'];
      return { windowY: window.scrollY, viewport: { width: innerWidth, height: innerHeight }, scrollingElement: document.scrollingElement.tagName, horizontalOverflow: document.documentElement.scrollWidth > innerWidth,
        elements: Object.fromEntries(selectors.filter(s => document.querySelector(s)).map(s => [s, box(document.querySelector(s))])) };
    });
    for (const route of ['/privacy', '/search', '/browse-playlists', '/picker?set=11', '/picker?set=12']) {
      await page.mouse.move(0, 0); // Keep hover transforms out of before/after geometry snapshots.
      await page.goto(origin + route);
      if (route.startsWith('/picker') || route === '/browse-playlists') await page.getByRole('link', { name: 'Playlist 24', exact: false }).waitFor({ state: 'attached' }).catch(error => { throw new Error(`${route} at ${viewport.width}px: ${error.message}`); });
      else if (route === '/privacy') await page.getByRole('heading', { name: 'ColorFix by Terry Privacy Policy', exact: true }).waitFor();
      else await page.locator('.searchpage').waitFor();
      await page.evaluate(() => document.fonts.ready);
      await page.waitForTimeout(100);
      const initial = await measure();
      const matchedRules = route === '/browse-playlists' ? await page.evaluate(() => [...document.styleSheets].flatMap(sheet => {
        try { return [...sheet.cssRules].filter(rule => rule.selectorText && document.querySelector('.playlist-picker').matches(rule.selectorText) && rule.style.justifyContent).map(rule => ({ sheet: sheet.href, selector: rule.selectorText, justifyContent: rule.style.justifyContent })); }
        catch { return []; }
      })) : undefined;
      report.push({ route, width: viewport.width, initial, matchedRules });
      if (route === '/browse-playlists') {
        await page.screenshot({ path: path.join(tmpdir(), `colorfix-browse-layout-${inspect ? 'before' : 'after'}-${viewport.width}.png`) });
        if (!inspect) {
          assert.equal(initial.windowY, 0, 'Direct load starts at window scroll zero');
          for (const selector of ['h1', '.playlist-picker__tagline', '.playlist-thumbs__header-row p:last-child']) {
            const b = initial.elements[selector];
            assert(b.top >= 0 && b.bottom <= viewport.height, `Complete header is visible at scroll zero: ${selector} at ${b.top}..${b.bottom}`);
          }
          assert.equal(await page.locator('.playlist-picker__brand, .playlist-picker__home').count(), 0, 'No duplicate landing branding or Home link');
          assert(await page.locator('.board-scroller').isVisible(), 'Standard app navigation remains');
          assert(await page.getByRole('button', { name: 'Go back', exact: true }).isVisible(), 'Standard Back remains');
          assert.equal(initial.horizontalOverflow, false);
          const panel = initial.elements['.playlist-thumbs__panel'];
          const standard = report.find(r => r.width === viewport.width && r.route === '/privacy').initial.elements['.main-layout'];
          const main = initial.elements['.main-layout'];
          assert(main && initial.elements['.app'], 'Landing must use the existing App and MainLayout components');
          for (const key of ['left', 'right', 'width', 'maxWidth', 'paddingLeft', 'paddingRight', 'paddingTop', 'marginLeft', 'marginRight', 'background']) assert.equal(main[key], standard[key], `Shared container must match normal ColorFix page: ${key}`);
          assert.equal(initial.elements.body.overflowY, report.find(r => r.width === viewport.width && r.route === '/privacy').initial.elements.body.overflowY, 'Use existing document overflow behavior without page-wide overrides');
          assert(Math.abs(panel.width - (main.width - parseFloat(main.paddingLeft) - parseFloat(main.paddingRight))) < 1, 'Landing fills only shared container content width');
          const grid = initial.elements['.playlist-thumbs__grid'];
          const header = initial.elements['.playlist-thumbs__header'];
          assert(Math.abs(grid.left - header.left) < 1 && Math.abs(grid.right - panel.right) < 1, 'Grid fills content width and aligns with heading gutters');
          const cards = await page.locator('.playlist-thumbs__card').evaluateAll(nodes => nodes.map(n => { const r = n.getBoundingClientRect(); return { left: r.left, right: r.right, top: r.top, width: r.width, href: n.getAttribute('href'), tag: n.tagName, pointerEvents: getComputedStyle(n).pointerEvents }; }));
          const firstRow = cards.filter(c => Math.abs(c.top - cards[0].top) < 1);
          assert(Math.abs(firstRow.at(-1).right - grid.right) < 1, 'Responsive cards fill the entire first row');
          assert(cards.every((c, i) => c.tag === 'A' && c.href.startsWith(`/t/existing-token-${i + 1}?`) && c.pointerEvents !== 'none'), 'All cards retain clickable, existing REX links');
          const image = initial.elements['.playlist-thumbs__image'];
          assert(Math.abs(image.width / image.height - 4 / 3) < 0.01, 'Thumbnail proportions preserved');
          assert(panel.left >= 0 && panel.right <= viewport.width, 'Panel is not clipped');
          for (const selector of ['.playlist-picker', '.playlist-thumbs__panel', '.playlist-thumbs__grid-wrap']) {
            assert(!['auto', 'scroll', 'hidden', 'clip'].includes(initial.elements[selector].overflowY), `No nested modal scroll/clipping: ${selector}`);
          }
          await page.mouse.move(viewport.width / 2, viewport.height / 2);
          await page.mouse.wheel(0, 100000);
          await page.waitForTimeout(150);
          const end = await measure();
          assert(end.windowY > 0 || end.elements.body.scrollTop > 0, 'Wheel scrolls the shared page, not a nested picker');
          const last = end.elements['.playlist-thumbs__card:last-child'];
          assert(last.top >= 0 && last.bottom <= viewport.height, 'Document scrolling reaches the complete final card');
          for (const selector of ['.playlist-picker', '.playlist-thumbs__grid-wrap']) assert.equal(end.elements[selector].scrollTop, 0);
          for (const card of await page.locator('.playlist-thumbs__card').all()) await card.click({ trial: true }); // Real hit testing without navigation or playback requests.
          await page.evaluate(() => { window.scrollTo({ top: 0, left: 0, behavior: "instant" }); document.body.scrollTop = 0; });
          await page.waitForFunction(() => window.scrollY === 0);
          assert((await page.locator("h1").boundingBox()).y >= 0, "Header returns to the top after document scrolling");
        }

      }
    }
    await context.close();
  }
  if (inspect) {
    await writeFile(baselinePath, JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
  } else {
    const before = baselineArgument ? JSON.parse(await readFile(baselineArgument.slice(11), 'utf8')) : null;
    for (const row of report.filter(r => r.route !== '/browse-playlists')) {
      const e = row.initial.elements;
      if (row.route.startsWith('/picker')) {
      assert.equal(e['.standalone-layout'].position, 'fixed');
      assert.equal(e['.standalone-layout'].overflowY, 'hidden');
      assert.equal(e['.playlist-picker'].display, 'flex');
      assert.equal(e['.playlist-picker'].justifyContent, 'center');
      assert.equal(e['.playlist-picker'].height, row.initial.viewport.height);
      assert.equal(e['.playlist-thumbs__grid-wrap'].overflowY, 'auto');
      assert.equal(e['.playlist-thumbs__panel'].maxHeight, `${row.initial.viewport.height - 80}px`);
      }
      if (before) {
        const original = before.find(r => r.route === row.route && r.width === row.width);
        assert.deepEqual(row.initial, original.initial, `Existing page/picker layout unchanged: ${row.route} at ${row.width}px`);
      }
    }
    await writeFile(path.join(tmpdir(), 'colorfix-browse-grid-after.json'), JSON.stringify(report, null, 2));
    console.log('PASS: actual compiled routes at 1920px, 1280px and 390px; full header at zero, container matches /privacy, no horizontal/picker overflow, shared page scroll reaches final card; /privacy, /search and legacy picker snapshots compared when supplied. All APIs intercepted.');
    console.log(JSON.stringify(report.filter(r => r.route === '/browse-playlists').map(r => ({ width: r.width, headingTop: r.initial.elements.h1.top, containerWidth: r.initial.elements['.main-layout'].width, containerLeft: r.initial.elements['.main-layout'].left, panelWidth: r.initial.elements['.playlist-thumbs__panel'].width, gridWidth: r.initial.elements['.playlist-thumbs__grid'].width, columns: r.initial.elements['.playlist-thumbs__grid'].gridTemplateColumns, cardWidth: r.initial.elements['.playlist-thumbs__card:first-child'].width, bodyOverflow: r.initial.elements.body.overflowY })), null, 2));
  }
} finally {
  await browser?.close();
  if (server) await new Promise(resolve => server.close(resolve));
}
