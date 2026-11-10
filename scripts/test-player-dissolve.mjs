import assert from 'node:assert/strict';
import { createServer } from 'vite';
import { chromium } from 'playwright';

const origin = 'http://127.0.0.1:5179';
const source = `import React from 'react'; import {createRoot} from 'react-dom/client';
import Player from '/src/components/Player/index.jsx'; import '/src/styles/ZIndex.css';
const slides=[['wide',1600,800],['tall',600,1200],['wide-again',1600,800]].map(([name])=>({type:'normal',image_url:'/'+name+'.svg',title:name,transition:'animation',palette_hash:'skip-preload'}));
slides.push({type:'text',title:'Text slide',subtitle:'After photograph'});
createRoot(document.getElementById('root')).render(React.createElement(Player,{slides,hideStars:true,showSlidePalettePrompt:false}));`;
const server = await createServer({ server: { host: '127.0.0.1', port: 5179, strictPort: true, proxy: {} }, plugins: [{ name: 'player-dissolve-fixture', resolveId(id) { if (id === '/__dissolve.jsx') return id; }, load(id) { if (id === '/__dissolve.jsx') return source; } }], logLevel: 'error' });
let browser;
try {
  await server.listen(); browser = await chromium.launch({ headless: true });
  for (const viewport of [{ width: 1280, height: 800 }, { width: 390, height: 844 }]) {
    const page = await browser.newPage({ viewport });
    const errors = []; let releaseTall;
    const tallGate = new Promise(resolve => { releaseTall = resolve; });
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.origin !== origin || url.pathname.startsWith('/api/')) return route.abort();
      if (url.pathname.endsWith('.svg')) {
        const tall = url.pathname === '/tall.svg';
        if (tall) await tallGate;
        return route.fulfill({ contentType: 'image/svg+xml', body: `<svg xmlns="http://www.w3.org/2000/svg" width="${tall ? 600 : 1600}" height="${tall ? 1200 : 800}"><rect width="100%" height="100%" fill="${tall ? '#257' : '#b73'}"/></svg>` });
      }
      if (route.request().resourceType() === 'document') return route.fulfill({ contentType: 'text/html', body: await server.transformIndexHtml('/', '<html><body style="margin:0"><div id="root"></div><script type="module" src="/__dissolve.jsx"></script></body></html>') });
      return route.continue();
    });
    await page.goto(origin);
    await page.waitForFunction(() => Number(getComputedStyle(document.querySelector('img.is-current')).opacity) === 1);
    const stage = page.locator('.player-stage');
    const startSampler = () => page.evaluate(() => {
      window.outgoingOpacity = [];
      const sample = () => {
        const image = document.querySelector('img.is-prev');
        if (!image) { window.outgoingRemoved = true; return; }
        window.outgoingOpacity.push(Number(getComputedStyle(image).opacity));
        requestAnimationFrame(sample);
      };
      window.outgoingRemoved = false; requestAnimationFrame(sample);
    });
    const finishAndCheck = async label => {
      await page.waitForFunction(() => window.outgoingRemoved, { timeout: 10000 });
      const samples = await page.evaluate(() => window.outgoingOpacity);
      assert(samples.some(value => value > 0.05 && value < 0.95), `${label}: real intermediate dissolve frames`);
      assert.equal(samples.at(-1), 0, `${label}: outgoing reaches zero before removal`);
      for (let i = 1; i < samples.length; i++) assert(samples[i] <= samples[i - 1] + 0.001, `${label}: no opacity jump/reappearance`);
      assert.equal(await page.locator('img.is-prev').count(), 0);
    };
    await stage.click();
    await page.locator('img.is-prev').waitFor();
    await page.waitForTimeout(250);
    assert.equal(await page.locator('img.is-prev').evaluate(img => Number(getComputedStyle(img).opacity)), 1, 'Keep outgoing visible until incoming image loads');
    await startSampler(); releaseTall();
    await finishAndCheck('landscape → portrait');
    assert.equal(await page.locator('img.is-current').getAttribute('src'), '/tall.svg');
    await stage.click(); await page.locator('img.is-prev').waitFor(); await startSampler();
    await finishAndCheck('portrait → landscape');
    await page.getByRole('button', { name: 'Go back one slide', exact: true }).click();
    await page.locator('img.is-prev').waitFor(); await startSampler();
    await finishAndCheck('Back transition');
    // Rapid navigation must cancel the previous transition's cleanup work.
    await stage.click(); await page.waitForTimeout(100); await stage.click();
    await page.locator('.intro-text__title').waitFor();
    await page.waitForFunction(() => !document.querySelector('img.is-prev'));
    assert(await page.getByText('Text slide', { exact: true }).isVisible());
    assert.deepEqual(errors, []);
    console.log(`PASS ${viewport.width}×${viewport.height}: mixed aspect ratios, zero-opacity painted before removal, slow load, Back, rapid navigation and image-to-text cleanup.`);
    await page.close();
  }
} finally { await browser?.close(); await server.close(); }
