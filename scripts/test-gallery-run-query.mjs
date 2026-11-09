import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, mkdir, copyFile, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import net from 'node:net';
import WebSocket from 'ws';
import { createServer } from 'vite';

// Runs real public doorbells against SQLite fixtures, never api/db.php.
const project = process.cwd();
const root = await mkdtemp(path.join(tmpdir(), 'colorfix-gallery-'));
let php, chrome, vite, socket;
let phpLogs = '', chromeLogs = '';
const requests = [], browserErrors = [];
const freePort = () => new Promise(resolve => {
  const server = net.createServer();
  server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); });
});
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
try {
  for (const file of ['api/run-query.php', 'api/v2/run-query.php', 'api/v2/gallery/run-query.php']) {
    const dest = path.join(root, file);
    await mkdir(path.dirname(dest), { recursive: true });
    await copyFile(path.join(project, file), dest);
  }
  await writeFile(path.join(root, 'api/autoload.php'), `<?php require_once ${JSON.stringify(project + '/api/autoload.php')};`);
  await writeFile(path.join(root, 'api/db.php'), `<?php require_once ${JSON.stringify(project + '/api/tests/fixtures/GalleryRunQueryDatabase.php')}; $pdo=galleryRunQueryDatabase();`);
  const phpPort = await freePort();
  const backend = `http://127.0.0.1:${phpPort}`;
  php = spawn('php', ['-S', `127.0.0.1:${phpPort}`, '-t', root], { stdio: ['ignore', 'pipe', 'pipe'] });
  php.stderr.on('data', data => { phpLogs += data; });
  for (let i = 0; i < 100; i++) {
    try { await fetch(backend); break; } catch { await delay(30); }
  }
  const canonical = '/api/v2/gallery/run-query.php';
  async function query(body, route = canonical) {
    const response = await fetch(backend + route, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const data = await response.json();
    assert.equal(response.status, 200, JSON.stringify(data));
    assert.equal(data.success, true);
    return data;
  }
  const blues = await query({ query_id: 5 });
  const art = blues.results.find(row => row.photo_library_id === 746);
  assert.equal(art.item_type, 'art-swatch');
  assert.equal(art.saved_palette_id, 163);
  assert.deepEqual([art.crop_x, art.crop_y, art.crop_width, art.crop_height], [0, 0.083990, 1, 0.600000]);
  assert.equal(art.display_title, 'Blue artwork');
  assert.equal(blues.results.find(row => row.photo_library_id === 747).rex_url, '/t/stable-house-token');
  assert(!blues.results.some(row => [748, 749, 750].includes(row.photo_library_id)), 'Private, inactive, retired photos stay excluded');
  assert.deepEqual(blues.inserts.map(row => row.id), [1, 2]);
  assert.equal(blues.results.filter(row => row.id === 3).length, 1, 'Multiple photo rows do not duplicate a color');
  const swatchIds = data => data.results.filter(row => row.item_type === 'swatch').map(row => row.id);
  assert.deepEqual(swatchIds(blues), [1, 2, 3]);
  assert.deepEqual(swatchIds(await query({ query_id: 5, params: { group_mode: 'chroma' } })), [1, 2, 3]);
  assert.deepEqual(swatchIds(await query({ query_id: 5, params: { group_mode: 'lightness' } })), [3, 2, 1]);
  assert.deepEqual(swatchIds(await query({ query_id: 5, searchFilters: { brand: ['sw'] } })), [1, 3]);
  for (const id of [2, 6, 12, 15]) await query({ query_id: id });
  assert.deepEqual(swatchIds(await query({ query_id: 18, params: { name: '%House%', code: '%House%' } })), [2]);
  assert.deepEqual(swatchIds(await query({ query_id: 16, params: { brand: 'sw' } })), [1, 3, 4]);
  for (const route of ['/api/run-query.php', '/api/v2/run-query.php']) {
    const response = await fetch(backend + route + '?t=123', { method: 'POST', body: '{"query_id":5}', redirect: 'manual' });
    assert.equal(response.status, 307);
    assert.equal(response.headers.get('location'), canonical + '?t=123');
    assert.deepEqual(await query({ query_id: 5 }, route + '?t=123'), blues, 'Redirect preserves POST/body');
  }
  assert.equal((await fetch(backend + canonical)).status, 405);
  assert.equal((await fetch(backend + canonical, { method: 'OPTIONS' })).status, 200);
  assert.equal((await fetch(backend + canonical, { method: 'POST', body: '{}' })).status, 400);
  assert.equal((await fetch(backend + canonical, { method: 'POST', body: '{"query_id":999}' })).status, 404);
  console.log('PASS HTTP/SQLite: canonical and legacy redirects, exact Art crop, ordinary REX links, visibility, inserts, grouping, filters, name/brand/family queries, error statuses.');

  const entry = `
    import React from 'react';
    import {createRoot} from 'react-dom/client';
    import {BrowserRouter,Routes,Route} from 'react-router-dom';
    import {AppStateProvider} from '/src/context/AppStateContext.jsx';
    import GalleryPage from '/src/pages/GalleryPage/index.jsx';
    createRoot(document.getElementById('root')).render(React.createElement(AppStateProvider,null,
      React.createElement(BrowserRouter,null,React.createElement(Routes,null,
        React.createElement(Route,{path:'/results/:queryId',element:React.createElement(GalleryPage)})))));
  `;
  vite = await createServer({ plugins: [{
    name: 'isolated-gallery-test',
    resolveId(id) { if (id === '/__gallery-test.js') return '\0gallery-test'; },
    load(id) { if (id === '\0gallery-test') return entry; },
    configureServer(server) {
      server.middlewares.use(async (req, res, next) => {
        const url = new URL(req.url, 'http://localhost');
        if (url.pathname.startsWith('/results/')) {
          res.setHeader('Content-Type', 'text/html');
          return res.end(await server.transformIndexHtml(url.pathname, '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><div id="root"></div><script type="module" src="/__gallery-test.js"></script>'));
        }
        if (url.pathname.startsWith('/photos/')) {
          res.setHeader('Content-Type', 'image/svg+xml');
          return res.end('<svg xmlns="http://www.w3.org/2000/svg" width="600" height="800"><rect width="600" height="800" fill="navy"/></svg>');
        }
        if (!url.pathname.startsWith('/api/')) return next();
        if (url.pathname === canonical) {
          const chunks = [];
          for await (const chunk of req) chunks.push(chunk);
          const body = Buffer.concat(chunks).toString();
          requests.push({ path: url.pathname, body: JSON.parse(body) });
          const response = await fetch(backend + req.url, { method: req.method, headers: { 'Content-Type': 'application/json' }, body });
          res.statusCode = response.status;
          res.setHeader('Content-Type', 'application/json');
          return res.end(await response.text());
        }
        requests.push({ path: url.pathname });
        res.setHeader('Content-Type', 'application/json');
        if (url.pathname === '/api/session.php') { res.statusCode = 401; return res.end('{"ok":false}'); }
        res.end('[]');
      });
    },
  }], server: { host: '127.0.0.1', port: 0, open: false } });
  await vite.listen();
  const origin = `http://127.0.0.1:${vite.httpServer.address().port}`;
  const debugPort = await freePort();
  chrome = spawn(process.env.CHROME_BIN || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', [
    '--headless=new', '--no-first-run', '--no-default-browser-check', '--disable-background-networking',
    `--user-data-dir=${path.join(root, 'chrome')}`, `--remote-debugging-port=${debugPort}`, 'about:blank',
  ], { stdio: ['ignore', 'ignore', 'pipe'] });
  chrome.stderr.on('data', data => { chromeLogs += data; });
  let targets;
  for (let i = 0; i < 150; i++) {
    try { targets = await (await fetch(`http://127.0.0.1:${debugPort}/json`)).json(); break; } catch { await delay(50); }
  }
  assert(targets, chromeLogs);
  socket = new WebSocket(targets.find(target => target.type === 'page').webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { socket.once('open', resolve); socket.once('error', reject); });
  let sequence = 0;
  const pending = new Map();
  socket.on('message', raw => {
    const message = JSON.parse(raw);
    if (message.method === 'Runtime.exceptionThrown') browserErrors.push(message.params.exceptionDetails);
    if (!message.id) return;
    const promise = pending.get(message.id);
    pending.delete(message.id);
    if (message.error) promise.reject(new Error(JSON.stringify(message.error))); else promise.resolve(message.result);
  });
  const cdp = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++sequence;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
  });
  await cdp('Runtime.enable');
  await cdp('Page.enable');
  const evaluate = async expression => {
    const result = await cdp('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    assert(!result.exceptionDetails, JSON.stringify(result.exceptionDetails));
    return result.result.value;
  };
  async function waitFor(expression) {
    for (let i = 0; i < 200; i++) { if (await evaluate(expression)) return; await delay(50); }
    throw new Error(`Browser condition timed out: ${expression}\n${JSON.stringify(browserErrors)}\n${phpLogs}`);
  }
  for (const width of [1280, 390]) {
    await cdp('Emulation.setDeviceMetricsOverride', { width, height: 844, deviceScaleFactor: 1, mobile: width === 390 });
    await cdp('Page.navigate', { url: origin + '/results/5' });
    await waitFor("Boolean(document.querySelector('.pals-art-image') && document.querySelector('.pals-photo'))");
    const shape = await evaluate(`(() => {
      const image=document.querySelector('.pals-art-image'),opening=image.parentElement;
      const box=opening.getBoundingClientRect();
      return {frame:!!document.querySelector('.pals-art-frame'),width:image.style.width,height:parseFloat(image.style.height),top:parseFloat(image.style.top),left:parseFloat(image.style.left),ratio:box.width/box.height,src:image.getAttribute('src'),srcset:image.getAttribute('srcset'),href:document.querySelector('.pals-photo').getAttribute('href')};
    })()`);
    assert.equal(shape.frame, true);
    assert.equal(shape.width, '100%');
    // CSSOM serializes percentages to fewer digits than JavaScript numbers.
    assert(Math.abs(shape.height - 100 / 0.6) < 0.001, JSON.stringify(shape));
    assert(Math.abs(shape.top + 100 * 0.083990 / 0.6) < 0.001, JSON.stringify(shape));
    assert.equal(shape.left, 0);
    assert(Math.abs(shape.ratio - 1.25) < 0.005);
    assert.equal(shape.src, '/photos/art.svg');
    assert.equal(shape.srcset, null, 'Manual crop uses the original, not a cropped thumbnail');
    assert.equal(shape.href, '/t/stable-house-token');
  }
  await evaluate("localStorage.setItem('pals.brand_filters.v1',JSON.stringify(['sw']))");
  const previousRequests = requests.length;
  await cdp('Page.navigate', { url: origin + '/results/5?group_mode=chroma' });
  await waitFor("Boolean(document.querySelector('.pals-art-image'))");
  assert(!await evaluate("Boolean(document.querySelector('.pals-photo'))"), 'Brand filter applies to photo source color, not artwork palette brand');
  assert(requests.slice(previousRequests).some(request => request.body?.searchFilters?.brand?.includes('sw')));
  assert(!requests.some(request => ['/api/v2/run-query.php', '/api/run-query.php', '/api/v2/gallery-results.php'].includes(request.path)), 'Combined Gallery query needs no old route or second photo request');
  assert.deepEqual(browserErrors, []);
  console.log('PASS Chrome desktop/mobile: actual GalleryPage calls canonical route; ArtSwatch gold frame and 5:4 crop; ordinary picture/REX link; artwork survives brand filtering; no second photo query.');
} catch (error) {
  console.error(phpLogs, chromeLogs);
  throw error;
} finally {
  socket?.close();
  chrome?.kill();
  await vite?.close();
  php?.kill();
  // Chrome may still be closing its profile immediately after SIGTERM.
  await delay(250);
  await rm(root, { recursive: true, force: true });
}
