import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { createServer } from 'vite';

// Real editor/master-detail components, with all API calls intercepted locally.
const server = await createServer({
  server: { host: '127.0.0.1', port: 0, strictPort: false, open: false },
  plugins: [{
    name: 'admin-playlist-loading-fixture',
    resolveId(id) { if (id === '/__playlist-loading.js') return '\0playlist-loading'; },
    load(id) {
      if (id !== '\0playlist-loading') return;
      return `
        import React from 'react';
        import { createRoot } from 'react-dom/client';
        import { BrowserRouter, Routes, Route, useNavigate } from 'react-router-dom';
        import { AppStateProvider } from '/src/context/AppStateContext.jsx';
        import { AdminDialogProvider } from '/src/components/AdminLayout/index.jsx';
        import AdminPlaylistsPage from '/src/pages/AdminPlaylistsPage/AdminPlaylistsPage.jsx';
        function App() {
          const navigate=useNavigate();
          return React.createElement(React.Fragment,null,
            React.createElement('button',{id:'open43',onClick:()=>navigate('/admin/playlists/43')},'Open 43'),
            React.createElement('button',{id:'open44',onClick:()=>navigate('/admin/playlists/44')},'Open 44'),
            React.createElement(Routes,null,React.createElement(Route,{path:'/admin/playlists/:playlistId',element:React.createElement(AdminPlaylistsPage)})));
        }
        createRoot(document.getElementById('root')).render(
          React.createElement(AppStateProvider,null,React.createElement(BrowserRouter,null,
            React.createElement(AdminDialogProvider,null,React.createElement(App)))));
      `;
    },
  }],
});
let browser;
let releaseStalled;
try {
  await server.listen();
  const origin = `http://127.0.0.1:${server.httpServer.address().port}`;
  browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 456, height: 800 } });
  const detailRequests = [];
  let stall43 = false;
  const playlists = [
    { playlist_id: 43, title: 'Adams Makeover', type: 'normal', is_active: 1 },
    { playlist_id: 44, title: 'Second Playlist', type: 'normal', is_active: 1 },
  ];
  await context.route('**/api/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/session.php')) {
      return route.fulfill({ json: { ok: true, user: { id: 1, email: 'admin@example.test', is_admin: true } } });
    }
    if (url.pathname === '/api/v2/admin/playlists/list.php') return route.fulfill({ json: { ok: true, items: playlists } });
    if (url.pathname === '/api/v2/admin/playlists/get.php') {
      const id = Number(url.searchParams.get('playlist_id'));
      detailRequests.push(id);
      if (id === 43 && stall43) await new Promise(resolve => { releaseStalled = resolve; });
      const items = Array.from({ length: id === 43 ? 5 : 1 }, (_, index) => ({
        playlist_item_id: id * 10 + index, playlist_id: id, order_index: index + 1,
        title: `Fixture ${id} slide ${index + 1}`, item_type: 'non-palette', is_active: 1,
      }));
      return route.fulfill({ json: { ok: true, playlist: playlists.find(row => row.playlist_id === id), items } }).catch(() => {});
    }
    return route.fulfill({ json: { ok: true, items: [], presets: [], projects: [] } });
  });
  await context.route('**/admin/playlists/43', async route => route.fulfill({
    contentType: 'text/html',
    body: await server.transformIndexHtml('/__playlist-loading', '<!doctype html><div id="root"></div><script type="module" src="/__playlist-loading.js"></script>'),
  }));
  const page = await context.newPage();
  page.on('pageerror', error => console.error('Playlist fixture:', error.message));
  await page.goto(origin + '/admin/playlists/43');
  await page.getByText('Fixture 43 slide 5', { exact: true }).waitFor();
  await page.waitForTimeout(50);
  const baseline = detailRequests.length;
  for (let index = 0; index < 3; index++) {
    const checked = page.waitForResponse(response => response.url().includes('/session.php'));
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await checked;
    await page.waitForTimeout(50);
  }
  assert.equal(detailRequests.length, baseline, 'Session refresh must not reload/reset the editor');
  await page.getByText('Fixture 43 slide 5', { exact: true }).waitFor();
  await page.locator('#open44').click();
  await page.getByText('Fixture 44 slide 1', { exact: true }).waitFor();
  await page.clock.install();
  stall43 = true;
  await page.locator('#open43').click();
  await page.getByText('Loading playlist...', { exact: true }).waitFor();
  await page.clock.runFor(31000);
  await page.getByText('Loading the playlist timed out. Please try again.', { exact: true }).waitFor();
  assert.equal(await page.getByText('Loading playlist...', { exact: true }).count(), 0);
  assert.equal(await page.getByText('Fixture 44 slide 1', { exact: true }).count(), 0, 'Failed navigation must not expose the previous playlist');
  stall43 = false;
  releaseStalled?.();
  await page.getByRole('button', { name: 'Retry', exact: true }).click();
  await page.getByText('Fixture 43 slide 5', { exact: true }).waitFor();
  console.log('PASS mobile playlist editor: five slides load, auth refresh does not reload details, route selection works, stalled requests time out and Retry recovers');
} finally {
  releaseStalled?.();
  await browser?.close();
  await server.close();
}
