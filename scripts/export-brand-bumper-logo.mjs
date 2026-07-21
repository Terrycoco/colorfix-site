import fs from "node:fs";
import path from "node:path";
import { chromium } from "playwright";

const ROOT = process.cwd();
const OUT_DIR = path.join(ROOT, "exports", "brand-bumper");
const OUT_FILE = path.join(OUT_DIR, "colorfix-brand-bumper-current-transparent.png");
const OUT_DARK_PREVIEW = path.join(OUT_DIR, "colorfix-brand-bumper-current-on-black.png");

fs.mkdirSync(OUT_DIR, { recursive: true });

function html(background = "transparent") {
  return `<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nothing+You+Could+Do&family=Poppins:wght@900&display=swap" rel="stylesheet">
  <style>
    html, body {
      margin: 0;
      padding: 0;
      width: 1800px;
      height: 900px;
      background: ${background};
    }
    body {
      display: grid;
      place-items: center;
    }
    .brand-bumper-logo {
      --brand-bumper-logo-size: 220px;
      position: relative;
      display: inline-flex;
      flex-direction: column;
      align-items: flex-start;
      width: max-content;
      max-width: none;
      padding: 0;
      line-height: 1;
      overflow: visible;
    }
    .brand-bumper-logo__main {
      display: inline-block;
      font-family: Poppins, Lato, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      font-size: var(--brand-bumper-logo-size);
      font-weight: 900;
      letter-spacing: 0;
      line-height: .95;
      white-space: nowrap;
    }
    .brand-bumper-logo__color {
      color: #fff;
    }
    .brand-bumper-logo__fix {
      color: #009ca6;
    }
    .brand-bumper-logo__by {
      --brand-bumper-signature-cover-x: 105%;
      position: relative;
      display: inline-flex;
      width: max-content;
      max-width: none;
      height: auto;
      overflow: hidden;
      margin-top: calc(var(--brand-bumper-logo-size) * -.03);
      margin-left: calc(var(--brand-bumper-logo-size) * 3.08);
      gap: .16em;
      color: rgba(170, 205, 202, 1);
      font-family: "Nothing You Could Do", cursive;
      font-size: calc(var(--brand-bumper-logo-size) * .48);
      font-weight: 400;
      letter-spacing: 0;
      word-spacing: 0;
      line-height: 1;
      text-transform: none;
      transform: rotate(-1.5deg);
      transform-origin: left center;
      white-space: nowrap;
      opacity: 1;
      filter: drop-shadow(0 0 1px rgba(123, 174, 171, .18));
      padding: .18em .24em .2em 0;
    }
    .brand-bumper-logo__by::after {
      content: "";
      position: absolute;
      inset: 0 -.3em 0 0;
      z-index: 2;
      background: #000;
      transform: translateX(var(--brand-bumper-signature-cover-x));
      transform-origin: right center;
      pointer-events: none;
    }
  </style>
</head>
<body>
  <div class="brand-bumper-logo" aria-label="ColorFix by Terry">
    <span class="brand-bumper-logo__main" aria-hidden="true">
      <span class="brand-bumper-logo__color">Color</span><span class="brand-bumper-logo__fix">Fix</span>
    </span>
    <span class="brand-bumper-logo__by" aria-hidden="true">
      <span>by</span><span>Terry</span>
    </span>
  </div>
</body>
</html>`;
}

const browser = await chromium.launch();
const page = await browser.newPage({
  viewport: { width: 1800, height: 900 },
  deviceScaleFactor: 2,
});

for (const [file, background, omitBackground] of [
  [OUT_FILE, "transparent", true],
  [OUT_DARK_PREVIEW, "#000", false],
]) {
  await page.setContent(html(background), { waitUntil: "networkidle" });
  await page.evaluate(async () => {
    await document.fonts.ready;
  });
  await page.locator(".brand-bumper-logo").screenshot({
    path: file,
    omitBackground,
  });
  console.log(file);
}

await browser.close();
