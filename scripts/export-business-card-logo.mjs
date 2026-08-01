import fs from "node:fs";
import path from "node:path";
import { chromium } from "playwright";

const ROOT = process.cwd();
const OUT_DIR = path.join(ROOT, "exports", "business-card");
const OUT_PNG = path.join(OUT_DIR, "colorfix-by-terry-business-card-transparent.png");
const OUT_SVG = path.join(OUT_DIR, "colorfix-by-terry-business-card-transparent.svg");
const OUT_LIVE_TEXT_SVG = path.join(OUT_DIR, "colorfix-by-terry-business-card-live-text.svg");

const COLORS = {
  color: "#111111",
  fix: "#009ca6",
  byTerry: "#47706f",
};

fs.mkdirSync(OUT_DIR, { recursive: true });

function logoHtml() {
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
      width: 2200px;
      height: 1100px;
      background: transparent;
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
      color: ${COLORS.color};
    }
    .brand-bumper-logo__fix {
      color: ${COLORS.fix};
    }
    .brand-bumper-logo__by {
      --brand-bumper-signature-cover-x: 105%;
      position: relative;
      display: inline-flex;
      width: max-content;
      max-width: none;
      height: auto;
      overflow: visible;
      margin-top: calc(var(--brand-bumper-logo-size) * -.03);
      margin-left: calc(var(--brand-bumper-logo-size) * 3.08);
      gap: .16em;
      color: ${COLORS.byTerry};
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

function logoSvg() {
  return `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="1070" height="350" viewBox="0 0 1070 350" role="img" aria-label="ColorFix by Terry">
  <title>ColorFix by Terry</title>
  <defs>
    <filter id="signature-shadow" x="-5%" y="-10%" width="110%" height="130%">
      <feDropShadow dx="0" dy="0" stdDeviation="1" flood-color="#7baeab" flood-opacity=".18"/>
    </filter>
    <style>
      @import url('https://fonts.googleapis.com/css2?family=Nothing+You+Could+Do&amp;family=Poppins:wght@900&amp;display=swap');
      .main { font-family: Poppins, Lato, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 220px; font-weight: 900; letter-spacing: 0; }
      .signature { font-family: "Nothing You Could Do", cursive; font-size: 105.6px; font-weight: 400; letter-spacing: 0; word-spacing: 0; }
    </style>
  </defs>
  <text class="main" x="0" y="207">
    <tspan fill="${COLORS.color}">Color</tspan><tspan fill="${COLORS.fix}">Fix</tspan>
  </text>
  <text class="signature" x="678" y="300" fill="${COLORS.byTerry}" filter="url(#signature-shadow)" transform="rotate(-1.5 678 300)">
    <tspan>by</tspan><tspan dx="16.9">Terry</tspan>
  </text>
</svg>
`;
}

fs.writeFileSync(OUT_LIVE_TEXT_SVG, logoSvg(), "utf8");

const browser = await chromium.launch();
const page = await browser.newPage({
  viewport: { width: 2200, height: 1100 },
  deviceScaleFactor: 4,
});

await page.setContent(logoHtml(), { waitUntil: "networkidle" });
await page.evaluate(async () => {
  await document.fonts.ready;
});
await page.locator(".brand-bumper-logo").screenshot({
  path: OUT_PNG,
  omitBackground: true,
});
await browser.close();

const pngDataUri = `data:image/png;base64,${fs.readFileSync(OUT_PNG).toString("base64")}`;
fs.writeFileSync(
  OUT_SVG,
  `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="4280" height="1400" viewBox="0 0 4280 1400" role="img" aria-label="ColorFix by Terry">
  <title>ColorFix by Terry</title>
  <image href="${pngDataUri}" x="0" y="0" width="4280" height="1400" preserveAspectRatio="xMidYMid meet"/>
</svg>
`,
  "utf8",
);

console.log(OUT_SVG);
console.log(OUT_LIVE_TEXT_SVG);
console.log(OUT_PNG);
