import assert from 'node:assert/strict';
import { createServer } from 'vite';
import { chromium } from 'playwright';

const source = `import React from 'react'; import {createRoot} from 'react-dom/client'; import {AppStateProvider} from '/src/context/AppStateContext.jsx'; import ItemEditPage from '/src/pages/ItemEditPage/index.jsx'; createRoot(document.getElementById('root')).render(React.createElement(AppStateProvider,null,React.createElement(ItemEditPage)));`;
const server = await createServer({ server: { host: '127.0.0.1', port: 5177, strictPort: true, proxy: {} }, plugins: [{ name: 'item-editor-fixture', resolveId(id) { if (id === '/__items.jsx') return id; }, load(id) { if (id === '/__items.jsx') return source; } }], logLevel: 'error' });
let browser;
try {
  await server.listen(); browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  const items = [{ id: 77, handle: '', image_url: 'photo:746', is_active: 1 }, { id: 78, handle: 'Keep', is_active: 1 }];
  const saves = [], deletes = [], errors = [];
  let failDelete = false;
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.pathname.includes('/api/')) {
      if (url.pathname.endsWith('get-all-items.php')) return route.fulfill({ json: items });
      if (url.pathname.endsWith('get-all-queries.php')) return route.fulfill({ json: { data: [] } });
      if (url.pathname.endsWith('upsert-item.php')) {
        const item = request.postDataJSON(); saves.push(item);
        item.id ||= 79;
        const index = items.findIndex(row => row.id === item.id);
        if (index < 0) items.push(item); else items[index] = item;
        return route.fulfill({ json: { success: true, item } });
      }
      if (url.pathname.endsWith('delete-item.php')) {
        const { id } = request.postDataJSON(); deletes.push(id);
        if (failDelete) return route.fulfill({ status: 409, json: { success: false, error: 'Referenced item' } });
        items.splice(items.findIndex(row => row.id === id), 1);
        return route.fulfill({ json: { success: true, id } });
      }
      if (url.pathname.includes('get-categories')) return route.fulfill({ json: [] });
      return route.fulfill({ json: { ok: false, data: [] } });
    }
    if (url.origin !== 'http://127.0.0.1:5177') return route.abort();
    if (request.resourceType() === 'document') return route.fulfill({ contentType: 'text/html', body: await server.transformIndexHtml('/', '<html><body><div id="root"></div><script type="module" src="/__items.jsx"></script></body></html>') });
    return route.continue();
  });
  await page.goto('http://127.0.0.1:5177/');
  await page.locator('li').filter({ hasText: '#77' }).waitFor();
  const save = page.getByRole('button', { name: 'Save Item', exact: true });
  const remove = page.getByRole('button', { name: 'Delete Item', exact: true });
  const fresh = page.getByRole('button', { name: '+ New Item', exact: true });
  assert.equal(saves.length, 0, 'Opening the editor creates nothing');
  assert(await remove.isDisabled(), 'Unsaved drafts cannot be deleted');
  await page.locator('li').filter({ hasText: '#77' }).click();
  assert.equal(await page.locator('input[type=text]').first().inputValue(), '', 'Blank handle stays blank');
  await fresh.click(); await page.waitForTimeout(100);
  assert.equal(saves.length, 0, 'New Item must never submit');
  await page.locator('input[type=text]').first().fill('Draft');
  await fresh.click(); await page.waitForTimeout(100);
  assert.equal(saves.length, 0, 'Abandoning a draft creates nothing');
  await page.locator('input[type=text]').first().fill('Explicit save');
  await save.click(); await page.getByText('id: 79', { exact: true }).waitFor();
  assert.equal(saves.length, 1);
  await save.click(); await page.waitForFunction(() => !document.querySelector('button[type=submit]').disabled);
  assert.equal(saves.length, 2); assert.equal(saves[1].id, 79, 'Second Save updates the newly saved record');
  assert.equal(items.length, 3, 'Repeated Save creates no duplicate');
  await page.locator('li').filter({ hasText: '#77' }).click();
  page.once('dialog', dialog => dialog.dismiss()); await remove.click();
  assert.equal(deletes.length, 0, 'Cancel deletion sends no request');
  page.once('dialog', dialog => dialog.accept()); await remove.click();
  await page.getByText('id:', { exact: true }).waitFor();
  assert.deepEqual(deletes, [77]); assert.deepEqual(items.map(row => row.id), [78, 79]);
  assert(await remove.isDisabled());
  await page.locator('li').filter({ hasText: '#78' }).click(); failDelete = true;
  page.once('dialog', dialog => dialog.accept()); await remove.click();
  await page.waitForFunction(() => !document.querySelector('button[type=submit]').disabled);
  assert.equal(await page.locator('li').filter({ hasText: '#78' }).count(), 1, 'Failed deletion retains selected item');
  assert.deepEqual(errors, []);
  console.log('PASS: New/draft changes send zero saves; explicit Save creates once and retains ID; confirmed exact-row delete, cancel, unsaved and failed-delete behavior. All APIs intercepted.');
} finally { await browser?.close(); await server.close(); }
