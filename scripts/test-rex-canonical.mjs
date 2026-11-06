import assert from 'node:assert/strict';
import { chromium } from 'playwright';

// Verify deployed initial HTML and the real player DOM. Intercept analytics to
// inspect attribution without adding synthetic browser visits to production.
const origin = process.env.COLORFIX_BASE_URL || 'https://colorfix.terrymarr.com';
const browser = await chromium.launch({ headless: true });
try {
  for (const [token, experience, tracking] of [
    ['f9vnX2UfipaLMqCY_d7ptRLDH-7H3Pi2yS5BUMvcGsE', 'public', '?src=sas'],
    ['jBEPyoGag6P6hHLAcHhl6yuP6B-yWzw2ML_sV2IZC-o', 'showcase', '?src=sas&utm_source=test#slide'],
  ]) {
    const context = await browser.newContext();
    const events = [];
    await context.route('**/api/v2/admin/ana/record-event.php', async route => {
      events.push(route.request().postDataJSON());
      await route.fulfill({ json: { ok: true } });
    });
    const page = await context.newPage();
    const expected = `${origin}/t/${token}`;
    const response = await page.goto(expected + tracking, { waitUntil: 'domcontentloaded' });
    assert.equal(response.status(), 200);
    assert(!response.headers()['x-robots-tag']?.includes('noindex'));
    const html = await response.text();
    assert(html.includes(`<link rel="canonical" href="${expected}">`));
    assert(html.includes(`<meta property="og:url" content="${expected}">`));
    const payload = await page.locator('#colorfix-rex-bootstrap').evaluate(el => JSON.parse(el.textContent).payload);
    const plan = payload.data.destination.playback_plan;
    assert.equal(plan.experience_key, experience);
    assert.equal(plan.src, 'sas');
    assert(plan.items.length > 0);
    await page.locator('.player-root').waitFor();
    await page.waitForFunction(expected => document.querySelector('link[rel="canonical"]')?.href === expected, expected);
    const dom = await page.evaluate(() => ({
      canonical: [...document.querySelectorAll('link[rel="canonical"]')].map(el => el.href),
      ogUrl: [...document.querySelectorAll('meta[property="og:url"]')].map(el => el.content),
      robots: document.querySelector('meta[name="robots"]')?.content,
      path: location.pathname,
      src: new URL(location.href).searchParams.get('src'),
    }));
    assert.deepEqual(dom.canonical, [expected]);
    assert.deepEqual(dom.ogUrl, [expected]);
    assert(!dom.robots.includes('noindex'));
    assert.equal(dom.path, `/t/${token}`);
    assert.equal(dom.src, 'sas');
    assert(events.some(event => event.src === 'sas'), 'Player analytics retains src attribution');
    console.log(JSON.stringify({ experience, initialHtml: 'PASS', renderedDom: dom, playableItems: plan.items.length, tracking: 'PASS' }));
    await context.close();
  }
} finally {
  await browser.close();
}
