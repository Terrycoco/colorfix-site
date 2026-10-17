import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from 'playwright';
import { createServer } from 'vite';

// Entirely intercepted browser fixture: no requests or ANA writes reach production.
const helper = await readFile(new URL('../src/helpers/sourceParam.js', import.meta.url), 'utf8');
const browser = await chromium.launch({ headless: true });
let server;
let passed = 0;
try {
  const context = await browser.newContext();
  await context.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/source.js') {
      return route.fulfill({ contentType: 'text/javascript', body: helper });
    }
    await route.fulfill({ contentType: 'text/html', body: `<!doctype html>
      <a id="browse" href="/picker?set=11">Browse</a>
      <a id="home" href="/">Home</a>
      <a id="playlist" href="/t/playlist-token?experience=public&src=site#slide">Playlist</a>
      <a id="pv" href="/t/viewer-token?back=1">PV</a>
      <a id="outside" href="//example.com/path?keep=1">External</a>
      <a id="download" href="/download" download>Download</a>
      <script type="module">
        import * as source from '/source.js';
        source.initializeSourcePropagation();
        window.source = source;
      </script>` });
  });
  const page = await context.newPage();
  const ready = () => page.waitForFunction(() => Boolean(window.source));
  const check = async (expected) => {
    await ready();
    assert.equal(new URL(page.url()).searchParams.get('src'), expected || null);
    assert.equal(await page.evaluate(() => window.source.getCurrentSourceParam()), expected);
    assert.equal(await page.evaluate(() => sessionStorage.getItem('ana_src')), expected);
  };
  const entrance = async (path, referrer) => {
    await page.goto(`https://colorfix.test${path}`, referrer ? { referer: referrer } : {});
    await ready();
  };
  await entrance('/?src=sas');
  await page.click('#browse'); await check('sas');
  await page.click('#playlist'); await check('sas');
  assert.equal(new URL(page.url()).searchParams.get('experience'), 'public');
  assert.equal(new URL(page.url()).hash, '#slide');
  console.log('PASS SAS -> Browse -> Playlist, preserving query and hash'); passed++;

  await page.click('#home'); await check('sas');
  await page.click('#playlist'); await check('sas');
  console.log('PASS SAS -> Homepage -> Playlist (overrides old site tag)'); passed++;
  await page.click('#pv'); await check('sas');
  console.log('PASS SAS -> REX -> PV, preserving token and back query'); passed++;
  assert.equal(new URL(page.url()).pathname, '/t/viewer-token');
  assert.equal(new URL(page.url()).searchParams.get('back'), '1');

  await entrance('/', 'https://www.google.com/search?q=paint'); await check('google');
  await page.click('#playlist'); await check('google');
  console.log('PASS Google referrer -> Homepage -> Playlist'); passed++;

  await page.evaluate(() => sessionStorage.clear());
  await entrance('/t/bookmarked-token?src=sas'); await check('sas');
  await page.click('#playlist'); await check('sas');
  console.log('PASS saved SAS URL reestablishes SAS after storage cleared'); passed++;

  await entrance('/'); await check('');
  await page.click('#playlist'); await check('');
  console.log('PASS Direct entrance clears previous SAS source'); passed++;
  await entrance('/?src=client'); await check('client');
  console.log('PASS new explicit source supersedes previous source'); passed++;

  assert.equal(await page.getAttribute('#outside', 'href'), '//example.com/path?keep=1');
  assert.equal(await page.getAttribute('#download', 'href'), '/download');
  for (const url of ['https://example.com/', '//example.com/', 'mailto:a@example.com', 'tel:123', '#part']) {
    assert.equal(await page.evaluate((url) => window.source.withSourceParam(url), url), url);
  }
  console.log('PASS no source leakage to external URLs or downloads'); passed++;

  await page.evaluate(() => history.pushState({ keep: true }, '', '/picker?set=11'));
  await check('client');
  assert.equal(await page.evaluate(() => history.state.keep), true);
  await page.evaluate(() => history.replaceState(history.state, '', '/t/pv-token?back=1'));
  await check('client');
  console.log('PASS programmatic navigation and state preservation'); passed++;

  await page.evaluate(() => {
    const a = document.createElement('a'); a.id = 'dynamic';
    a.href = '/results/7?brand=DE'; document.body.append(a);
  });
  await page.waitForFunction(() => document.querySelector('#dynamic').search.includes('src=client'));
  console.log('PASS dynamic internal links receive source in actual href'); passed++;

  await entrance('/', 'https://www.bing.com/search?q=paint'); await check('bing');
  await entrance('/', 'https://www.pinterest.com/pin/123'); await check('pin');
  await entrance('/', 'https://google.com.evil.test/'); await check('');
  for (const [referrer, source] of [
    ['https://duckduckgo.com/?q=paint', 'duckduckgo'],
    ['https://search.yahoo.com/search?p=paint', 'yahoo'],
    ['https://l.facebook.com/', 'facebook'],
    ['https://youtu.be/test', 'yt'],
  ]) {
    await entrance('/', referrer); await check(source);
  }
  console.log('PASS Bing/Pinterest recognition and lookalike-domain rejection'); passed++;

  await context.close();

  // Exercise the actual Router synchronization and ANA hook, not only DOM links.
  server = await createServer({
    server: { host: '127.0.0.1', port: 0, strictPort: false, open: false },
    plugins: [{
      name: 'source-test-fixture',
      resolveId(id) { if (id === '/__source-test.js') return '\0source-test'; },
      load(id) {
        if (id !== '\0source-test') return;
        return `
          import React, { useEffect } from 'react';
          import { createRoot } from 'react-dom/client';
          import { BrowserRouter, useLocation, useNavigate } from 'react-router-dom';
          import SourceNavigation from '/src/ANA/SourceNavigation.jsx';
          import ANAProvider from '/src/ANA/ANAProvider.jsx';
          import { useANA } from '/src/ANA/useANA.js';
          import { initializeSourcePropagation } from '/src/helpers/sourceParam.js';
          initializeSourcePropagation();
          function App() {
            const location = useLocation();
            const navigate = useNavigate();
            const { track } = useANA();
            useEffect(() => { track('visit', {}, { resource_type: 'playlist', resource_id: 123 }); }, [location.key]);
            return React.createElement('button', {
              id: 'next', onClick: () => navigate('/t/test-viewer?back=1', { state: { keep: true } })
            }, location.pathname + location.search);
          }
          createRoot(document.getElementById('root')).render(
            React.createElement(BrowserRouter, null,
              React.createElement(SourceNavigation),
              React.createElement(ANAProvider, null, React.createElement(App))));
        `;
      },
    }],
  });
  await server.listen();
  const localOrigin = `http://127.0.0.1:${server.httpServer.address().port}`;
  const spaContext = await browser.newContext();
  const events = [];
  await spaContext.route('**/api/**', async (route) => {
    if (route.request().url().includes('record-event.php')) events.push(route.request().postDataJSON());
    await route.fulfill({ json: { ok: true } });
  });
  await spaContext.route('**/__source-test?*', async (route) => route.fulfill({
    contentType: 'text/html',
    body: await server.transformIndexHtml('/__source-test', '<!doctype html><div id="root"></div><script type="module" src="/__source-test.js"></script>'),
  }));
  const spaPage = await spaContext.newPage();
  spaPage.on('pageerror', (error) => console.error('SPA fixture error:', error.message));
  await spaPage.goto(`${localOrigin}/__source-test?src=sas`);
  await spaPage.waitForSelector('#next');
  await spaPage.click('#next');
  await spaPage.waitForFunction(() => document.querySelector('#next').textContent.includes('src=sas'));
  assert.equal(new URL(spaPage.url()).searchParams.get('back'), '1');
  assert.equal(await spaPage.evaluate(() => history.state.usr.keep), true);
  await spaPage.waitForTimeout(100);
  assert.equal(events.length, 2, 'one ANA event per fixture page, without a duplicate from source synchronization');
  assert.ok(events.every((event) => event.src === 'sas'));
  console.log('PASS actual React Router navigation, URL/state synchronization, and ANA payload source'); passed++;
  await spaContext.close();
  console.log(`${passed} source propagation checks passed.`);
} finally {
  await server?.close();
  await browser.close();
}
