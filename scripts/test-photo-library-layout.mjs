import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import path from 'node:path';
import { chromium } from 'playwright';

const root = path.resolve('dist');
const server = createServer(async (req, res) => {
  try {
    let file = path.join(root, new URL(req.url, 'http://fixture').pathname);
    try { if ((await stat(file)).isDirectory()) file = path.join(file, 'index.html'); }
    catch { file = path.join(root, 'admin/index.html'); }
    res.setHeader('Content-Type', ({ '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' })[path.extname(file)] || 'application/octet-stream');
    res.end(await readFile(file));
  } catch { res.statusCode = 404; res.end(); }
});
let browser;
try {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch({ headless: true });
  for (const width of [1440, 390]) {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    const photos = Array.from({ length: 51 }, (_, index) => ({ photo_library_id: index + 1, title: `Photo ${index + 1}`, tags: 'kitchen, oak', image_url: '/fixture.svg', source_type: 'art_inspired', palette_owned_by_library: true, is_inactive: false }));
    let failSave = false;
    const saves = [];
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.origin !== origin) return route.abort();
      if (url.pathname === '/fixture.svg') return route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="100"><rect width="160" height="100" fill="#a9c5c3"/></svg>' });
      if (!url.pathname.startsWith('/api/')) return route.continue();
      if (url.pathname.endsWith('/session.php')) return route.fulfill({ json: { ok: true, user: { id: 1, is_admin: true } } });
      if (url.pathname.endsWith('/photo-library/list.php')) {
        const offset = Number(url.searchParams.get('offset') || 0);
        return route.fulfill({ json: { ok: true, items: photos.slice(offset, offset + 50) } });
      }
      if (url.pathname.endsWith('/photo-library/update.php')) {
        const payload = route.request().postDataJSON();
        saves.push(payload);
        if (failSave) return route.fulfill({ status: 500, json: { ok: false, error: 'Fixture save failed' } });
        Object.assign(photos.find(p => p.photo_library_id === payload.photo_library_id), payload);
        return route.fulfill({ json: { ok: true } });
      }
      return route.fulfill({ json: { ok: true, items: [], data: [], groups: [], series: [] } });
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${origin}/admin/photo-library?q=kitchen`);
    const row = id => page.locator(`[data-admin-object-id="${id}"]`);
    await row(1).waitFor();
    const thumbnail = row(2).getByRole('button', { name: 'Enlarge photo', exact: true });
    await thumbnail.click();
    const photoPreview = page.getByRole('dialog', { name: 'Photo preview' });
    await photoPreview.waitFor();
    assert.equal(await photoPreview.locator('img').getAttribute('src'), '/fixture.svg');
    assert.equal(await photoPreview.locator('img').evaluate(img => getComputedStyle(img).objectFit), 'contain');
    await photoPreview.locator('img').click();
    await photoPreview.waitFor({ state: 'hidden' });
    await thumbnail.focus();
    await page.keyboard.press('Enter');
    await photoPreview.waitFor();
    await page.keyboard.press('Escape');
    await photoPreview.waitFor({ state: 'hidden' });
    assert.equal(await thumbnail.evaluate(img => document.activeElement === img), true);
    console.log(`PASS: ${width}px list thumbnail preview, original image, keyboard, click/Escape close`);
    await row(1).click();
    await page.getByLabel('Title', { exact: true }).fill('Edited kitchen');
    await thumbnail.click();
    await photoPreview.waitFor();
    await photoPreview.locator('img').click();
    await photoPreview.waitFor({ state: 'hidden' });
    assert.equal(await page.getByLabel('Title', { exact: true }).inputValue(), 'Edited kitchen', 'preview must preserve unsaved edits');
    await row(2).click();
    await page.getByRole('button', { name: 'Stay', exact: true }).click();
    assert.equal(await page.getByLabel('Title', { exact: true }).inputValue(), 'Edited kitchen');
    await row(2).click();
    await page.getByRole('button', { name: 'Discard', exact: true }).click();
    await row(1).click();
    assert.equal(await page.getByLabel('Title', { exact: true }).inputValue(), 'Photo 1');
    await page.getByLabel('Title', { exact: true }).fill('Saved kitchen');
    await row(2).click();
    failSave = true;
    await page.getByRole('button', { name: 'Save and continue' }).click();
    await page.getByRole('dialog').getByText('Fixture save failed').waitFor();
    assert.equal(await page.getByLabel('Title', { exact: true }).inputValue(), 'Saved kitchen');
    failSave = false;
    await page.getByRole('button', { name: 'Save and continue' }).click();
    await page.getByRole('dialog').waitFor({ state: 'hidden' });
    assert.equal(saves.at(-1).title, 'Saved kitchen');
    await row(1).click();
    assert.equal(await page.getByLabel('Title', { exact: true }).inputValue(), 'Saved kitchen');
    await page.getByRole('button', { name: 'Load more' }).click();
    await row(51).waitFor({ state: 'attached' });
    assert.equal(await page.locator('[data-admin-object-id]').count(), 51);
    await page.getByRole('button', { name: '+ Upload', exact: true }).click();
    await page.getByRole('dialog', { name: 'Upload photos' }).waitFor();
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `horizontal overflow at ${width}px`);
    assert.deepEqual(errors, []);
    await page.screenshot({ path: `/tmp/photo-library-${width}.png`, fullPage: true });
    console.log(`PASS: ${width}px selection, discard, failed/successful save, pagination, upload, layout`);
    await context.close();
  }
  {
    const artContext = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const artworks = [1, 2].map(id => ({ artwork_id: id, photo_library_id: id, artist: `Artist ${id}`, display_title: `Artwork ${id}` }));
    await artContext.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.origin !== origin) return route.abort();
      if (url.pathname === '/fixture.svg') return route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="100"/>' });
      if (!url.pathname.startsWith('/api/')) return route.continue();
      if (url.pathname.endsWith('/session.php')) return route.fulfill({ json: { ok: true, user: { id: 1, is_admin: true } } });
      if (url.pathname.endsWith('/artwork/index.php')) return route.fulfill({ json: { ok: true, items: artworks, artwork: artworks.find(a => a.photo_library_id === Number(url.searchParams.get('photo_library_id'))) } });
      if (url.pathname.endsWith('/photo-library/list.php')) return route.fulfill({ json: { ok: true, items: artworks.map(a => ({ photo_library_id: a.photo_library_id, image_url: '/fixture.svg' })) } });
      return route.fulfill({ json: { ok: true, items: [] } });
    });
    const artPage = await artContext.newPage();
    await artPage.goto(`${origin}/admin/artworks`);
    const first = artPage.locator('[data-admin-object-id="1"]');
    const second = artPage.locator('[data-admin-object-id="2"]');
    const thumb = second.getByRole('button', { name: 'Enlarge photo', exact: true });
    await thumb.click();
    const preview = artPage.getByRole('dialog', { name: 'Photo preview' });
    await preview.waitFor();
    assert.equal(await first.getAttribute('aria-current'), 'true');
    await preview.locator('img').click();
    await preview.waitFor({ state: 'hidden' });
    assert.equal(await first.getAttribute('aria-current'), 'true', 'closing preview must not select its parent row');
    await thumb.focus();
    await artPage.keyboard.press('Space');
    await preview.waitFor();
    await artPage.keyboard.press('Escape');
    await preview.waitFor({ state: 'hidden' });
    assert.equal(await first.getAttribute('aria-current'), 'true');
    await second.getByText('Artwork 2', { exact: true }).click();
    assert.equal(await second.getAttribute('aria-current'), 'true');
    console.log('PASS: artwork thumbnail click/Space previews without selecting row; text still selects');
    await artContext.close();
  }
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  let paletteRequests = 0;
  let savedPayload;
  const playlist = { playlist_id: 60, title: 'Ingres fixture', playlist_type: 'normal', is_active: true };
  const slide = { playlist_item_id: 8, item_type: 'palette', title: 'Elegant Dining Room', photo_library_id: 752, image_url: '/fixture.svg', palette_hash: '', is_active: true, order_index: 1 };
  await context.route('**/*', route => {
    const url = new URL(route.request().url());
    if (url.origin !== origin) return route.abort();
    if (url.pathname === '/fixture.svg') return route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="100"/>' });
    if (!url.pathname.startsWith('/api/')) return route.continue();
    if (url.pathname.endsWith('/session.php')) return route.fulfill({ json: { ok: true, user: { id: 1, is_admin: true } } });
    if (url.pathname.endsWith('/playlists/get.php')) return route.fulfill({ json: { ok: true, playlist, items: [slide] } });
    if (url.pathname.endsWith('/playlists/list.php')) return route.fulfill({ json: { ok: true, items: [playlist] } });
    if (url.pathname.endsWith('/photo-library/list.php')) return route.fulfill({ json: { ok: true, items: [{ photo_library_id: 752, palette_id: 99, attached_saved_palette_id: 99, attached_saved_palette_label: 'Ingres palette', image_url: '/fixture.svg' }] } });
    if (url.pathname.endsWith('/photo-library/editorial.php')) {
      assert.equal(url.searchParams.get('photo_library_id'), '752');
      return route.fulfill({ json: { ok: true, editorial: { design_notes: 'Photo 752 notes' } } });
    }
    if (url.pathname.endsWith('/palettes/detail.php')) {
      paletteRequests++;
      assert.equal(url.searchParams.get('id'), '99');
      return route.fulfill({ json: { ok: true, item: { id: 99, palette_hash: 'ingres-hash', nickname: 'Ingres palette' } } });
    }
    if (url.pathname.endsWith('/playlist-items/save.php')) {
      savedPayload = route.request().postDataJSON();
      return route.fulfill({ json: { ok: true, playlist_id: 60, playlist, items: [slide] } });
    }
    // Reproduce the reported empty dropdown; exact photo palette lookup must work anyway.
    return route.fulfill({ json: { ok: true, items: [], data: [], presets: [] } });
  });
  const page = await context.newPage();
  await page.goto(`${origin}/admin/playlists/60`);
  const enlarge = page.getByRole('button', { name: 'Enlarge Elegant Dining Room', exact: true });
  await enlarge.click();
  const preview = page.getByRole('dialog', { name: 'Photo preview', exact: true });
  await preview.waitFor();
  assert.equal(await preview.locator('img').getAttribute('src'), '/fixture.svg');
  assert.equal(await preview.locator('img').evaluate(img => getComputedStyle(img).objectFit), 'contain');
  assert.equal(await page.getByRole('button', {name:'Save & Close',exact:true}).count(), 0, 'Photo click must not open slide editing');
  await preview.locator('img').click();
  await preview.waitFor({ state: 'detached' });
  await enlarge.click();
  await page.keyboard.press('Escape');
  await preview.waitFor({ state: 'detached' });
  await page.getByText('Elegant Dining Room', { exact: true }).dblclick();
  const paletteSelect = page.locator('label').filter({ has: page.locator('.admin-field__label').filter({ hasText: /^Palette$/ }) }).locator('select');
  await paletteSelect.waitFor();
  await page.waitForFunction(() => [...document.querySelectorAll('select')].some(select => select.value === 'ingres-hash'));
  assert.equal(await paletteSelect.inputValue(), 'ingres-hash');
  assert.equal(await paletteSelect.locator('option:checked').textContent(), 'Ingres palette');
  assert.ok(paletteRequests > 0);
  await page.getByRole('button', {name:'Enlarge Elegant Dining Room',exact:true}).last().click();
  await preview.waitFor();
  await page.getByRole('button', {name:'Close photo preview',exact:true}).click();
  await preview.waitFor({state:'detached'});
  assert.equal(await paletteSelect.inputValue(),'ingres-hash','Closing preview preserves slide editing');
  console.log('PASS: playlist thumbnail/detail preview opens original, click/Escape closes, row/editor interactions preserved');
  await page.locator('.admin-workbench-drawer__close-row').getByRole('button', { name: 'Photo Editorial', exact: true }).click();
  const editorial = page.getByRole('dialog', { name: 'Photo Editorial · #752', exact: true });
  await editorial.waitFor();
  await page.waitForFunction(() => document.querySelector('textarea[aria-label="Design Notes"]')?.value === 'Photo 752 notes');
  await editorial.getByRole('button', { name: 'Enlarge Selected photo', exact: true }).click();
  await preview.waitFor();
  await preview.locator('img').click();
  await preview.waitFor({ state: 'detached' });
  assert.equal(await editorial.getByLabel('Design Notes', { exact: true }).inputValue(), 'Photo 752 notes');
  await editorial.getByRole('button', { name: 'Close', exact: true }).last().click();
  await editorial.waitFor({ state: 'hidden' });
  assert.equal(await paletteSelect.inputValue(), 'ingres-hash');
  console.log('PASS: top toolbar opens correct photo editorial; nested preview closes back to editorial; slide editor stays open');
  await page.getByRole('button', { name: 'Save & Close', exact: true }).first().click();
  await page.waitForTimeout(500);
  assert.ok(savedPayload?.items?.some(item => item.palette_hash === 'ingres-hash'), 'inherited palette must be persisted');
  console.log('PASS: existing photo #752 slide resolves and saves its palette with an empty palette list');
  await context.close();

} finally {
  await browser?.close();
  await new Promise(resolve => server.close(resolve));
}
