import fs from "node:fs";
import path from "node:path";
import { chromium } from "playwright";

const ROOT = process.cwd();
const OUT_DIR = path.join(ROOT, "exports", "youtube", "brand");
const VARIANTS = [
  {
    name: "black-color",
    color: "#111",
    file: path.join(OUT_DIR, "colorfix-by-terry-frontpage-logo-black-color.png"),
  },
  {
    name: "white-color",
    color: "#fff",
    file: path.join(OUT_DIR, "colorfix-by-terry-frontpage-logo-white-color.png"),
  },
];

fs.mkdirSync(OUT_DIR, { recursive: true });

function htmlForVariant(variant) {
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
      width: 1400px;
      height: 620px;
      background: transparent;
    }
    body {
      display: grid;
      place-items: center;
    }
    .logo {
      --front-blurb-logo-size: 190px;
      position: relative;
      display: inline-block;
      width: min-content;
      max-width: 100%;
      padding: 0 0 calc(var(--front-blurb-logo-size) * .46);
      line-height: 1;
    }
    .main {
      display: inline-block;
      font-family: Poppins, Lato, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      font-size: var(--front-blurb-logo-size);
      font-weight: 900;
      letter-spacing: 0;
      line-height: .95;
      white-space: nowrap;
    }
    .color {
      color: ${variant.color};
    }
    .fix {
      color: #009ca6;
    }
    .by {
      position: absolute;
      left: 66%;
      top: calc(var(--front-blurb-logo-size) * .92);
      display: inline-flex;
      gap: .16em;
      color: rgba(71, 112, 111, .85);
      font-family: "Nothing You Could Do", cursive;
      font-size: calc(var(--front-blurb-logo-size) * .48);
      font-weight: 400;
      letter-spacing: 0;
      word-spacing: 0;
      line-height: 1;
      text-transform: none;
      transform: rotate(-1.5deg);
      white-space: nowrap;
      transform-origin: left center;
    }
  </style>
</head>
<body>
  <div class="logo" aria-label="ColorFix by Terry">
    <span class="main"><span class="color">Color</span><span class="fix">Fix</span></span>
    <span class="by" aria-label="by Terry"><span>by</span><span>Terry</span></span>
  </div>
</body>
</html>`;
}

const browser = await chromium.launch();
const page = await browser.newPage({
  viewport: { width: 1400, height: 620 },
  deviceScaleFactor: 2,
});
for (const variant of VARIANTS) {
  await page.setContent(htmlForVariant(variant), { waitUntil: "networkidle" });
  await page.evaluate(async () => {
    await document.fonts.ready;
  });
  const logo = page.locator(".logo");
  await logo.screenshot({
    path: variant.file,
    omitBackground: true,
  });
  console.log(variant.file);
}
await browser.close();
