import assert from 'node:assert/strict';
import { readFile, mkdtemp, mkdir, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { createServer as createHttpServer } from 'node:http';
import { chromium } from 'playwright';
import { createServer } from 'vite';
import { resolveBrowsePlaylistsMarketingUrl } from '../src/helpers/routingHelper.js';

// Every browser API and external request is intercepted. No production visits.
const server = await createServer({ server: { host: '127.0.0.1', port: 0, strictPort: false, proxy: {} } });
let browser, registryServer, directory;
const requests = [], events = [], pageErrors = [];
const set = { id: 11, title: 'Set eleven', version: '123', items: [
  { id: 1, item_type: 'playlist', title: 'Public makeover', rex_url: '/t/existing-public', photo_library_id: 1 },
  { id: 2, item_type: 'playlist', title: 'Showcase makeover', rex_url: '/t/existing-showcase', photo_library_id: 2 },
  { id: 3, item_type: 'set', title: 'Other collection', target_set_id: 12, target_set_version: '124' },
] };
let checks = 0;
try {
  await server.listen();
  const origin = `http://127.0.0.1:${server.httpServer.address().port}`;
  browser = await chromium.launch({ headless: true });
  for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 844 }]) {
    const context = await browser.newContext({ viewport });
    await context.route('**/*', async route => {
      const request = route.request(), url = new URL(request.url());
      if (url.origin !== origin) return route.abort();
      if (url.pathname.startsWith('/api/')) {
        if (url.pathname.endsWith('record-event.php')) { events.push(request.postDataJSON()); return route.fulfill({ json: { ok: true, event_id: 4 } }); }
        if (url.pathname === '/api/v2/playlist-sets/get.php') { requests.push(url); return route.fulfill({ json: { ok: true, set: { ...set, id: Number(url.searchParams.get('id')) } } }); }
        if (url.pathname.includes('image-thumb')) return route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="520" height="390"><rect width="520" height="390" fill="#aabbb5"/></svg>' });
        if (url.pathname.includes('playlist-color-search')) { requests.push(url); return route.fulfill({ json: { ok: true, items: [], meta: { count: 0, limit: 120 } } }); }
        return route.fulfill({ json: { ok: true, user: null, is_admin: false, categories: [], schemes: [], items: [], data: [] } });
      }
      if (request.resourceType() === 'document') return route.fulfill({ contentType: 'text/html', body: await server.transformIndexHtml(url.pathname, await readFile('index.html', 'utf8')) });
      return route.continue();
    });
    const page = await context.newPage();
    page.on('pageerror', error => pageErrors.push(error.message));
    const firstEvent = events.length;
    await page.goto(`${origin}/browse-playlists?src=sas&set=99&include_private=1`);
    await page.getByRole('heading', { name: 'Browse Playlists', exact: true }).waitFor();
    await page.getByRole('link', { name: 'Public makeover', exact: false }).waitFor();
    await page.waitForTimeout(100);
    assert.equal(requests.at(-1).searchParams.get('id'), '11');
    assert.equal(requests.at(-1).searchParams.get('marketing'), '1');
    assert.equal(requests.at(-1).searchParams.get('include_private'), null);
    assert.equal(events.slice(firstEvent).filter(e => e.resource_type === 'page' && e.path === '/browse-playlists').length, 1, 'Exactly one main-router visit, including StrictMode.');
    assert.equal(events.at(-1).src, 'sas');
    assert(await page.getByText("What works. What doesn't. And why.", { exact: true }).isVisible());
    assert(await page.locator('.board-scroller').isVisible());
    assert.equal(await page.locator('.playlist-picker__brand, .playlist-picker__home').count(), 0);
    assert.equal(await page.getByRole('button', { name: 'Exit picker' }).count(), 0);
    const href = new URL(await page.getByRole('link', { name: 'Public makeover', exact: false }).getAttribute('href'), origin);
    assert.equal(href.pathname, '/t/existing-public');
    assert.equal(href.searchParams.get('src'), 'sas');
    assert(href.searchParams.get('return_to').startsWith('/browse-playlists'));
    assert.equal(href.searchParams.get('include_private'), null);
    assert.equal(new URL(await page.getByRole('link', { name: 'Other collection' }).getAttribute('href'), origin).searchParams.get('marketing'), '1');
    assert(await page.getByRole('button', { name: 'Go back', exact: true }).isVisible());
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No horizontal page scrolling.');
    assert(await page.locator('.playlist-thumbs__panel').evaluate(panel => panel.getBoundingClientRect().right <= innerWidth), 'Panel must fit inside viewport, not just be clipped by a parent.');
    await page.getByRole('link', { name: 'Other collection' }).scrollIntoViewIfNeeded();
    assert(await page.getByRole('link', { name: 'Other collection' }).isVisible());
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: path.join(tmpdir(), `colorfix-browse-playlists-${viewport.width}.png`), fullPage: true });
    checks++;
    await page.getByRole('link', { name: 'Search by Color Family' }).click();
    await page.getByRole('button', { name: 'Blues', exact: true }).click();
    await page.waitForTimeout(100);
    assert.equal(requests.at(-1).pathname, '/api/v2/playlist-color-search.php');
    assert.equal(requests.at(-1).searchParams.get('family'), 'Blues');
    assert.equal(requests.at(-1).searchParams.get('src'), 'sas');
    await page.getByRole('button', { name: 'Exit color family search' }).click();
    await page.getByRole('heading', { name: 'Browse Playlists', exact: true }).waitFor();
    checks++;
    await page.goto(`${origin}/picker?set=12&include_private=1`);
    await page.getByRole('button', { name: 'Exit picker' }).waitFor();
    await page.getByRole('heading', { name: 'Set eleven', exact: true }).waitFor();
    assert.equal(requests.at(-1).searchParams.get('id'), '12');
    assert.equal(requests.at(-1).searchParams.get('marketing'), null);
    assert.equal(requests.at(-1).searchParams.get('include_private'), '1');
    checks++;
    await context.close();
  }
  // Fresh tab/session: actual referrer classification, stored referrer and own-device exclusion.
  const context = await browser.newContext();
  await context.route('**/api/**', route => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('record-event.php')) events.push(route.request().postDataJSON());
    return route.fulfill({ json: url.pathname.includes('playlist-sets/get') ? { ok: true, set } : { ok: true, event_id: 4, user: null, categories: [], schemes: [], items: [] } });
  });
  await context.route('**/browse-playlists*', async route => route.fulfill({ contentType: 'text/html', body: await server.transformIndexHtml('/browse-playlists', await readFile('index.html', 'utf8')) }));
  const page = await context.newPage();
  await page.goto(`${origin}/browse-playlists`, { referer: 'https://www.google.com/search?q=colorfix' });
  await page.getByRole('heading', { name: 'Browse Playlists', exact: true }).waitFor();
  await page.waitForTimeout(100);
  assert.equal(events.at(-1).src, 'google');
  assert(events.at(-1).referrer.startsWith('https://www.google.com/'));
  const before = events.length;
  await page.goto(`${origin}/browse-playlists?src=pin&cf_internal=1`);
  await page.getByRole('heading', { name: 'Browse Playlists', exact: true }).waitFor();
  await page.waitForTimeout(100);
  assert.equal(events.length, before, 'Marked internal visits are excluded.');
  checks++;
  await context.close();

  assert.equal(resolveBrowsePlaylistsMarketingUrl('/picker?set=11&src=yt', 'BrowsePlaylistsTile'), '/browse-playlists?src=yt');
  for (const url of ['/picker?set=12','/picker?set=11&set_v=3','/picker?set=11&return_to=/admin/','/picker?set=11&aud=client']) assert.equal(resolveBrowsePlaylistsMarketingUrl(url, 'BrowsePlaylistsTile'), url);
  assert.equal(resolveBrowsePlaylistsMarketingUrl('/picker?set=11', 'OtherTile'), '/picker?set=11');
  checks++;

  // Run the existing prebuild in a temporary root against a local registry fixture.
  directory = await mkdtemp(path.join(tmpdir(), 'colorfix-browse-build-'));
  await mkdir(path.join(directory, 'dist'), { recursive: true });
  await mkdir(path.join(directory, 'api/cache'), { recursive: true });
  await writeFile(path.join(directory, 'dist/index.html'), await readFile('dist/index.html'));
  await writeFile(path.join(directory, 'api/cache/front-page-public.json'), JSON.stringify({ results: [{ handle: 'BrowsePlaylistsTile', item_type: 'search', display: 'Browse Playlists', target_url: '/picker?set=11' }] }));
  const registry = [ { id: 1, path: '/', title: 'Home' }, { id: 4, path: '/browse-playlists', title: 'Browse Playlists',
    og_title: 'Browse Color Transformations & Paint Ideas | ColorFix by Terry',
    og_description: 'Explore before-and-after color transformations, exterior and interior paint ideas, and designer insights from ColorFix by Terry.', og_image: '/brand/colorfix-by-terry-frontpage-logo-white-color.png' } ];
  registryServer = createHttpServer((req,res) => { res.setHeader('Content-Type','application/json'); res.end(JSON.stringify({ok:true,pages:registry})); });
  await new Promise(resolve => registryServer.listen(0, '127.0.0.1', resolve));
  // Use an async child so the local fixture registry remains responsive.
  const { spawn } = await import('node:child_process');
  await new Promise((resolve,reject) => {
    const child=spawn(process.execPath,[path.resolve('scripts/prebuild-homepage-html.mjs')],{cwd:directory,env:{...process.env,COLORFIX_BASE_URL:`http://127.0.0.1:${registryServer.address().port}`},stdio:['ignore','pipe','pipe']});
    let output='';child.stdout.on('data',v=>output+=v);child.stderr.on('data',v=>output+=v);child.on('error',reject);child.on('exit',code=>code===0?resolve():reject(Error(output)));
  });
  const html = await readFile(path.join(directory,'dist/browse-playlists/index.html'),'utf8');
  assert(html.includes('<title>Browse Color Transformations &amp; Paint Ideas | ColorFix by Terry</title>'));
  assert(html.includes('name="description" content="'+registry[1].og_description+'"'));
  assert(html.includes('name="robots" content="index,follow"'));
  assert(!html.includes('noindex'));
  assert(html.includes('rel="canonical" href="https://colorfix.terrymarr.com/browse-playlists"'));
  assert(html.includes('property="og:url" content="https://colorfix.terrymarr.com/browse-playlists"'));
  assert(html.includes('property="og:title"'));
  assert(html.includes('<div id="root"></div>'));
  const home = await readFile(path.join(directory,'dist/index.html'),'utf8');
  assert(home.includes('href="/browse-playlists"'), 'Prebuilt homepage marketing link uses the clean route.');
  assert.deepEqual(pageErrors, []);
  checks++;
  console.log(`PASS: ${checks} browser/build groups: desktop/mobile, set 11, branding, links, family search/return, legacy picker, single visit, source/internal policy, metadata and marketing compatibility. No production calls.`);
} finally {
  await browser?.close(); await server.close();
  if (registryServer) await new Promise(resolve=>registryServer.close(resolve));
  if (directory) await rm(directory,{recursive:true,force:true});
}
