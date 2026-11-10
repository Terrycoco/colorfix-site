import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createServer } from 'vite';
import { chromium } from 'playwright';
import { resolveAppPath } from '../src/helpers/routingHelper.js';

const before = process.argv.includes('--before');
// Insert the actual shared navigation components into both app shells for the
// fixture only. Real routers, public pages, admin guard and entry files remain.
const probeImports = `import NavigationSearch from '@components/GalleryItems/SearchItem';
import NavigationButton from '@components/GalleryItems/ButtonItem';
import NavigationPlaylist from '@components/GalleryItems/PlaylistItem';
import {Link as NavigationLink} from 'react-router-dom';\n`;
const probe = `<div data-navigation-fixture>
  <div data-probe="search"><NavigationSearch item={{target_url:window.__target,display:'Navigation target'}} /></div>
  <div data-probe="button"><NavigationButton item={{target_url:window.__target,display:'Navigation target'}} /></div>
  <div data-probe="playlist"><NavigationPlaylist item={{target_url:window.__target,display:'Navigation target'}} /></div>
  <NavigationLink data-probe="link" to={window.__target}>Navigation target</NavigationLink>
  <NavigationLink data-probe="admin-return" to="/admin/items">Admin return</NavigationLink>
</div>`;
const origin = 'http://127.0.0.1:5178';
const server = await createServer({ server: { host: '127.0.0.1', port: 5178, strictPort: true, proxy: {} }, logLevel: 'error', plugins: [{
  name: 'navigation-fixture', enforce: 'pre', transform(code, id) {
    if (!/\/src\/(?:AdminApp|App)\.jsx$/.test(id)) return null;
    return probeImports + code.replace('<MessagePopup />', `<MessagePopup />${probe}`);
  },
}] });
let browser;
try {
  await server.listen(); browser = await chromium.launch({ headless: true });
  const cases = [
    ['/', '/art-gallery'], ['/admin/', '/art-gallery'], ['/admin/items', '/art-gallery'],
    ['/admin/', '/inspired-by/1'], ['/admin/', '/results/4'], ['/admin/', '/admin/items'],
  ];
  if (!before) for (const [start, target] of cases) assert.equal(resolveAppPath(target, start), target);
  if (!before) for (const target of ['/search', '/color/1', '/palette/translate', '/articles/2', '/admin/items?x=1#item', '/art-gallery?x=1#art', 'items', 'https://example.test/page', '//example.test/page', '#section']) {
    assert.equal(resolveAppPath(target, '/admin/items'), target, `Preserve destination: ${target}`);
  }
  const scenarios = before ? [['/admin/', '/art-gallery', 'search']] : [
    ...cases.map(([start, target]) => [start, target, 'search']),
    ...['button', 'playlist', 'link'].map(kind => ['/admin/', '/art-gallery', kind]),
    ['/admin/', '/art-gallery?x=1#art', 'search'],
  ];
  for (const [start, target, kind] of scenarios) {
    const context = await browser.newContext();
    await context.addInitScript(target => {
      window.__target = target;
      window.__documentId = crypto.randomUUID();
      window.__entry = location.pathname.startsWith('/admin') ? 'admin' : 'public';
    }, target);
    await context.route('**/*', async route => {
      const request = route.request(), url = new URL(request.url());
      if (url.pathname.includes('/api/')) {
        if (url.pathname.endsWith('session.php')) return route.fulfill({ json: { ok: true, user: { id: 1, email: 'fixture@example.test', is_admin: true } } });
        if (url.pathname.includes('get-categories') || url.pathname.includes('get-search-presets') || url.pathname.includes('get-all-items')) return route.fulfill({ json: [] });
        if (url.pathname.includes('get-all-queries')) return route.fulfill({ json: { data: [] } });
        if (url.pathname.includes('artwork/page.php')) return route.fulfill({ json: url.searchParams.get('action') === 'list'
          ? { ok: true, items: [] }
          : { ok: true, data: { artwork: { display_title: 'Artwork fixture' }, photo: {}, paint_approximations: null, inspired_designs: [] } } });
        return route.fulfill({ json: { ok: true, event_id: 0, results: [], inserts: [], items: [], data: [], meta: {} } });
      }
      if (url.origin !== origin) return route.abort();
      if (request.resourceType() === 'document') return route.fulfill({ contentType: 'text/html', body: await server.transformIndexHtml(url.pathname,
        await readFile(url.pathname.startsWith('/admin') ? 'admin/index.html' : 'index.html', 'utf8')) });
      return route.continue();
    });
    const page = await context.newPage(), errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(origin + start);
    await page.locator('[data-navigation-fixture]').waitFor();
    const documentId = await page.evaluate(() => window.__documentId);
    const element = page.locator(`[data-probe="${kind}"]`);
    await (kind === 'link' ? element : element.locator('.item')).click();
    if (before) {
      await page.waitForURL(url => url.pathname === '/admin/art-gallery');
      console.log('REPRODUCED: SearchItem targets /art-gallery; admin entry normalizer rewrites it to /admin/art-gallery.');
    } else {
      const expected = new URL(target, origin), adminTarget = expected.pathname.startsWith('/admin');
      await page.waitForURL(url => url.pathname === expected.pathname && url.search === expected.search && url.hash === expected.hash);
      await page.locator('[data-navigation-fixture]').waitFor();
      if (expected.pathname === '/art-gallery') await page.getByRole('heading', { name: 'Art Gallery', exact: true }).waitFor();
      if (expected.pathname === '/inspired-by/1') await page.getByRole('heading', { name: 'Artwork fixture', exact: true }).waitFor();
      if (adminTarget) await page.getByRole('button', { name: 'Save Item', exact: true }).waitFor();
      assert.equal(await page.evaluate(() => window.__documentId), documentId, 'Public and admin navigation must reuse the current document');
      await page.goBack();
      await page.waitForURL(url => url.pathname === new URL(start, origin).pathname);
      await page.locator('[data-navigation-fixture]').waitFor();
      if (start.startsWith('/admin') && !adminTarget) {
        await page.goForward();
        await page.waitForURL(url => url.pathname === expected.pathname);
        await page.locator('[data-probe="admin-return"]').click();
        await page.getByRole('button', { name: 'Save Item', exact: true }).waitFor();
        assert.equal(await page.evaluate(() => window.__documentId), documentId);
      }
      assert.deepEqual(errors, []);
      console.log(`PASS ${kind}: ${start} → ${target}; correct route, Back and SPA navigation without reload.`);
    }
    await context.close();
  }
} finally { await browser?.close(); await server.close(); }
