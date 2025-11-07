// sw-open-color.js
// Usage:
//   node sw-open-color.js --slug=sw6994-greenblack --families=neutral-paint-colors,black-paint-colors --headed --pause
//
// Flags:
//   --slug=<sw####-name>            required
//   --families=a,b,c                required (comma separated family slugs)
//   --headed                        optional (run browser visible)
//   --pause                         optional (pause in Inspector after page is found)
//   --no-links                      optional (skip collecting links on page)

const { chromium, devices } = require('playwright');

function parseArgs() {
  const args = {};
  for (const part of process.argv.slice(2)) {
    const [k, vRaw] = part.split('=');
    if (!k?.startsWith('--')) continue;
    const key = k.slice(2);
    const v = vRaw === undefined ? true : vRaw;
    args[key] = v;
  }
  return args;
}

function splitFamilies(str) {
  return (str || '')
    .split(',')
    .map(s => s.trim())
    .filter(Boolean);
}

async function collectColorLinks(page) {
  return await page.evaluate(() => {
    const anchors = Array.from(document.querySelectorAll('a[href*="/en-us/color/color-family/"]'));
    const results = [];
    for (const a of anchors) {
      const href = a.getAttribute('href') || '';
      const match = href.match(/\/(sw\d{3,4}-[a-z0-9-]+)/i);
      if (match) {
        const slug = match[1].toLowerCase();
        const text = (a.textContent || '').trim();
        results.push({ slug, href, text });
      }
    }
    const seen = new Set();
    return results.filter(r => !seen.has(r.slug) && seen.add(r.slug));
  });
}

(async () => {
  const args = parseArgs();
  const slug = (args.slug || '').trim().toLowerCase();
  const families = splitFamilies(args.families);
  const headed = !!args.headed;
  const doPause = !!args.pause;
  const noLinks = !!args['no-links'];

  if (!slug) {
    console.error('❌ Missing required --slug, e.g. --slug=sw6994-greenblack');
    process.exit(1);
  }
  if (families.length === 0) {
    console.error('❌ Provide at least one family via --families=a,b,c');
    process.exit(1);
  }

  const browser = await chromium.launch({ headless: !headed });
  const context = await browser.newContext({
    ...devices['Desktop Chrome'],
    viewport: { width: 1360, height: 900 },
  });
  const page = await context.newPage();

  let foundUrl = null;
  let foundFamily = null;

  for (const fam of families) {
    const url = `https://www.sherwin-williams.com/en-us/color/color-family/${fam}/${slug}`;
    console.log(`🔎 Trying: ${url}`);

    try {
      const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
      const ok = resp && resp.ok();

      const finalUrl = page.url();
      const urlHasSlug = finalUrl.toLowerCase().includes(`/${slug}`);

      if (ok && urlHasSlug) {
        await page.waitForTimeout(1500);
        await page.waitForSelector('main, body', { timeout: 5000 }).catch(() => {});
        foundUrl = finalUrl;
        foundFamily = fam;
        console.log(`✅ Found page for ${slug} under family "${fam}"`);
        break;
      } else {
        console.log(`↩️  Not a match (ok=${ok}, hasSlug=${urlHasSlug})`);
      }
    } catch (err) {
      console.log(`⚠️  Error loading ${url}: ${err.message}`);
    }

    await page.waitForTimeout(500);
  }

  if (!foundUrl) {
    console.error(`❌ No working page found for "${slug}" in provided families.`);
    await browser.close();
    process.exit(2);
  }

  // Optional: pause here so you can inspect the page before scraping
  if (doPause) {
    console.log('⏸  Pausing in Playwright Inspector (close it to continue)...');
    await page.pause();
  }

  if (!noLinks) {
    const links = await collectColorLinks(page);
    console.log(`🔗 Found ${links.length} SW color links on page.`);
    for (const l of links) {
      console.log(`• ${l.slug} | ${l.text} | ${l.href}`);
    }
  }

  console.log('\n📍 FINAL');
  console.log(`Family: ${foundFamily}`);
  console.log(`URL:    ${foundUrl}`);

  await browser.close();
  process.exit(0);
})().catch(async (e) => {
  console.error('💥 Unhandled error:', e);
  process.exit(99);
});
