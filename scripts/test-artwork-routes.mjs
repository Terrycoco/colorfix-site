import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createServer } from 'vite';
import { chromium } from 'playwright';

// Actual admin/public entries and routers, with every API request intercepted.
const before = process.argv.includes('--before');
const server = await createServer({ server: { host: '127.0.0.1', port: 5175, strictPort: true, proxy: {} }, logLevel: 'error' });
let browser;
try {
  await server.listen();
  const origin = 'http://127.0.0.1:5175';
  browser = await chromium.launch({ headless: true });
  for (const width of before ? [1280] : [1280, 390]) {
    const context = await browser.newContext({ viewport: { width, height: 844 } });
    let admin = true, artworkStatus = 200, failThumb = false;
    let linkedPlaylists = [{playlist_id:60,title:'Ingres fixture',rex_url:'/t/stable-artwork'}];
    const imageRequests = [];
    const requests = [], errors = [];
    await context.route('**/*', async route => {
      const request = route.request(), url = new URL(request.url());
      if (url.pathname.includes('image-thumb.php') || url.pathname.startsWith('/fixture-')) {
        imageRequests.push(url);
        if (failThumb && url.pathname.includes('image-thumb.php')) return route.fulfill({ status: 500, body: 'Fixture unavailable' });
        return route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="400"><rect width="300" height="400" fill="#987"/></svg>' });
      }
      if (url.pathname.includes('/api/')) {
        if (url.pathname.endsWith('/session.php')) return route.fulfill({ json: admin ? { ok: true, user: { id: 1, email: 'fixture@example.test', is_admin: true } } : { ok: false, user: null } });
        if (url.pathname.includes('artwork/page.php')) {
          requests.push(url);
          return route.fulfill({ status: artworkStatus, json: artworkStatus === 200
            ? { ok: true, data: { artwork: { display_title: 'Artwork fixture' }, photo: {photo_library_id:746,image_url:'/fixture-art.png',alt_text:'Artwork image'}, paint_approximations: { members: [{ color_id: 1, name: 'Saved artwork paint', hex: '123456' }] }, inspired_designs: [{photo_library_id:748,image_url:'/fixture-design.png',title:'Design fixture'}], playlists: linkedPlaylists } }
            : { ok: false, error: artworkStatus === 404 ? 'Artwork not publicly available.' : 'Unable to load artwork.' } });
        }
        if (url.pathname.includes('get-categories')) return route.fulfill({ json: [] });
        return route.fulfill({ json: { ok: true, event_id: 0, items: [], data: [], categories: [] } });
      }
      if (url.origin !== origin) return route.abort();
      if (request.resourceType() === 'document') {
        const file = url.pathname.startsWith('/admin') ? 'admin/index.html' : 'index.html';
        return route.fulfill({ contentType: 'text/html', body: await server.transformIndexHtml(url.pathname, await readFile(file, 'utf8')) });
      }
      return route.continue();
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${origin}/admin/inspired-by/1?src=sas#study`);
    await page.locator('.board-scroller').waitFor();
    if (before) {
      await page.waitForTimeout(800);
      assert.equal(await page.locator('.artwork-page').count(), 0);
      assert.equal(requests.length, 0);
      console.log('REPRODUCED: /admin/inspired-by/1 renders the admin header with no artwork page or artwork API request.');
    } else {
      await page.getByRole('heading', { name: 'Artwork fixture', exact: true }).waitFor();
      assert.equal(requests.at(-1).searchParams.get('artwork_id'), '1');
      assert(await page.getByText('Saved artwork paint', { exact: true }).isVisible());
      assert.equal(await page.locator('.main-layout').count(), 1);
      assert.equal(new URL(page.url()).searchParams.get('src'), 'sas');
      assert.equal(new URL(page.url()).hash, '#study');
      const watch = page.getByRole('link', {name:'Watch the Art-Inspired Palette Playlist',exact:true});
      assert.equal(await watch.getAttribute('href'),'/t/stable-artwork?src=sas','Use existing public REX with source attribution');
      const watchBox = await watch.boundingBox();
      const designHeading = await page.getByRole('heading',{name:'Designs Inspired by Artwork fixture'}).boundingBox();
      assert(watchBox.y < designHeading.y,'Watch link appears above inspired designs');
      assert.equal(await watch.evaluate(el => el.scrollWidth <= el.clientWidth),true,'Watch link fits on mobile');
      const hero = page.locator('.artwork-page__figure img');
      await hero.evaluate(img => img.decode());
      const thumb = new URL(await hero.getAttribute('src'));
      assert.equal(thumb.pathname, '/api/v2/image-thumb.php');
      assert.equal(thumb.searchParams.get('id'), '746');
      assert.equal(thumb.searchParams.get('w'), '1200');
      assert.equal(thumb.searchParams.get('crop_type'), 'original');
      assert.equal(await hero.evaluate(img => getComputedStyle(img).objectFit), 'contain');
      assert.equal(imageRequests.some(url => url.pathname.startsWith('/fixture-')), false, 'Originals are not downloaded for initial display');
      await page.getByRole('button', {name:'Enlarge Artwork fixture',exact:true}).click();
      const lightbox = page.getByRole('dialog', {name:'Enlarged photograph. Click to close.'});
      assert.equal(await lightbox.locator('img').getAttribute('src'), '/fixture-art.png');
      await lightbox.locator('img').evaluate(img => img.decode());
      await page.getByRole('button', {name:'Close enlarged photograph',exact:true}).click();
      const design = page.locator('.artwork-page__design img');
      await design.scrollIntoViewIfNeeded(); await design.evaluate(img => img.decode());
      const designThumb = new URL(await design.getAttribute('src'));
      assert.equal(designThumb.searchParams.get('id'), '748');
      assert.equal(designThumb.searchParams.get('w'), '480');
      await page.getByRole('button', {name:'Enlarge Design fixture',exact:true}).click();
      assert.equal(await lightbox.locator('img').getAttribute('src'), '/fixture-design.png');
      await page.keyboard.press('Escape');
      failThumb = true;
      await page.reload();
      await page.getByRole('heading', {name:'Artwork fixture',exact:true}).waitFor();
      await page.waitForFunction(() => document.querySelector('.artwork-page__figure img')?.getAttribute('src') === '/fixture-art.png');
      await page.locator('.artwork-page__figure img').evaluate(img => img.decode());
      failThumb = false;
      await page.reload();
      await page.getByRole('heading', { name: 'Artwork fixture', exact: true }).waitFor();
      linkedPlaylists = [];
      await page.reload();
      await page.getByRole('heading', {name:'Artwork fixture',exact:true}).waitFor();
      assert.equal(await page.getByRole('navigation',{name:'Artwork playlists'}).count(),0,'No playlist means no watch control');
      linkedPlaylists = [{playlist_id:60,title:'Ingres fixture',rex_url:'/t/stable-artwork'}, {playlist_id:61,title:'Another study <script>',rex_url:'/t/another-study'}];
      await page.reload();
      await page.getByRole('heading', {name:'Artwork fixture',exact:true}).waitFor();
      assert.equal(await page.getByRole('navigation',{name:'Artwork playlists'}).getByRole('link').count(),2,'Multiple qualified playlists stay distinct');
      assert.equal(await page.getByRole('link',{name:'Watch: Another study <script>',exact:true}).getAttribute('href'),'/t/another-study?src=sas');
      assert.equal(await page.locator('.artwork-page__playlists script').count(),0,'Playlist titles render as escaped text');
      for (const status of [404, 503]) {
        artworkStatus = status;
        await page.reload();
        await page.getByRole('alert').getByRole('heading', { name: 'Artwork unavailable' }).waitFor();
      }
      artworkStatus = 200;
      admin = false;
      const count = requests.length;
      await page.reload();
      await page.waitForURL(url => url.pathname === '/admin/login');
      assert.equal(requests.length, count, 'Unauthenticated admin route remains protected');
      await page.goto(`${origin}/inspired-by/1`);
      await page.getByRole('heading', { name: 'Artwork fixture', exact: true }).waitFor();
      assert(await page.getByText('Saved artwork paint', { exact: true }).isVisible());
      assert.deepEqual(errors, []);
      console.log(`PASS ${width}px: actual admin route and reload, correct artwork ID, shared layout, saved palette, sized display images/original lightbox/fallback, query/hash, error UI, login protection and anonymous public route.`);
    }
    await context.close();
  }
} finally { await browser?.close(); await server.close(); }
