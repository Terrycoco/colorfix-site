import fs from "node:fs";
import path from "node:path";
import process from "node:process";

const root = process.cwd();
const distIndex = path.join(root, "dist", "index.html");
const payloadPath = path.join(root, "api", "cache", "front-page-public.json");
const adminDistIndex = path.join(root, "dist", "admin", "index.html");
const adminPayloadPath = path.join(root, "api", "cache", "front-page-admin.json");

if (!fs.existsSync(distIndex)) {
  fail("dist/index.html not found. Run npm run build first.");
}
if (!fs.existsSync(payloadPath)) {
  fail("api/cache/front-page-public.json not found. Run npm run prebuild-front-page first.");
}

const payload = JSON.parse(fs.readFileSync(payloadPath, "utf8"));
const html = fs.readFileSync(distIndex, "utf8");
const staticHtml = `<!-- cf-static-home:start -->${renderStaticHome(payload)}<!-- cf-static-home:end -->`;
const preloadLinks = renderPreloadLinks(payload);
const styleBlock = `<style id="cf-static-home-style">${getStaticCss()}</style>`;

let next = stripExistingStaticHome(html);
next = applyBuildContentToHead(next, normalizeBuildContent(payload, "public_home"));
next = replaceRoot(next, staticHtml);
next = next.replace("</head>", `${preloadLinks}${styleBlock}\n  </head>`);

fs.writeFileSync(distIndex, next);
console.log(`Prebuilt static homepage HTML into ${distIndex}`);

if (fs.existsSync(adminDistIndex) && fs.existsSync(adminPayloadPath)) {
  const adminPayload = JSON.parse(fs.readFileSync(adminPayloadPath, "utf8"));
  const adminHtml = fs.readFileSync(adminDistIndex, "utf8");
  const adminNext = applyBuildContentToHead(adminHtml, normalizeBuildContent(adminPayload, "admin_home"));
  fs.writeFileSync(adminDistIndex, adminNext);
  console.log(`Applied admin homepage metadata into ${adminDistIndex}`);
}

function renderStaticHome(payload) {
  const buildContent = normalizeBuildContent(payload, "public_home");
  const items = mergeWithInserts(payload.results || [], [
    ...(payload.inserts || []),
    payload.frontPageRailItem,
  ].filter(Boolean)).filter((item) => !isLoginButton(item));

  const cards = items.map(renderItem).filter(Boolean).join("\n");
  const footer = renderStaticFooter(buildContent);
  return `
    <div class="cf-static-home" aria-hidden="true">
      <main class="cf-static-home__grid">
        ${cards}
      </main>
      ${footer}
    </div>
  `;
}

function renderItem(item) {
  const type = String(item?.item_type || "").toLowerCase();
  if (type === "front-blurb") return renderFrontBlurb(item);
  if (type === "featured-article") return renderFeaturedArticle(item);
  if (type === "front-page-playlist-set") return renderPlaylistSet(item);
  if (type === "name-search") return renderTextCard(item.display || item.title, "", "cf-static-card--name", item.target_url || "");
  if (type === "search" || type === "brand" || type === "button") {
    return renderTextCard(item.display || item.title, item.description || "", "", item.target_url || "");
  }
  return "";
}

function renderFrontBlurb(item) {
  return `
    <section class="cf-static-card cf-static-blurb">
      ${renderFrontPageTitle(item.title || "ColorFix by Terry")}
      ${item.subtitle ? `<div class="cf-static-blurb__subtitle">${escapeHtml(item.subtitle)}</div>` : ""}
      <p>${escapeHtml(item.body || "")}</p>
    </section>
  `;
}

function renderFrontPageTitle(title) {
  const text = String(title || "").trim();
  if (!["colorfix", "colorfix by terry"].includes(text.toLowerCase())) {
    return `<h1>${escapeHtml(text || "ColorFix by Terry")}</h1>`;
  }

  return `
      <h1 class="cf-static-blurb__seo-logo" aria-label="ColorFix by Terry">
        <span class="cf-static-blurb__seo-logo-main">
          <span class="cf-static-blurb__seo-logo-color">Color</span><span class="cf-static-blurb__seo-logo-fix">Fix</span>
        </span>
        <span class="cf-static-blurb__seo-logo-by" aria-label="by Terry"><span>by</span><span>Terry</span></span>
      </h1>`;
}

function renderFeaturedArticle(item) {
  const payload = item.featured_payload || {};
  const article = payload.article || {};
  const hero = payload.hero_mobile || payload.hero || {};
  const heroId = Number(hero.photo_library_id || article.hero_mobile_asset_id || article.hero_asset_id || 0);
  const image = heroId ? thumbUrl(heroId, 720, 72) : hero.rel_path || "";
  const body = `
    <article class="cf-static-card cf-static-featured">
      <div class="cf-static-kicker">${escapeHtml(item.display || "Featured Article")}</div>
      ${image ? `<img src="${escapeAttr(image)}" alt="${escapeAttr(hero.alt_text || article.title || "")}" class="cf-static-featured__image">` : `<div class="cf-static-placeholder">No image</div>`}
      <div class="cf-static-featured__body">
        <h2>${escapeHtml(article.title || item.title || "Featured article")}</h2>
        <p>${escapeHtml(article.dek || "Tap to read")}</p>
        <span>Read Full Article →</span>
      </div>
    </article>
  `;
  const url = article.slug ? `/articles/${encodeURIComponent(String(article.slug))}` : "";
  return wrapStaticLink(body, url);
}

function renderPlaylistSet(item) {
  const tiles = Array.isArray(item.items) ? item.items : [];
  if (!tiles.length) return "";
  return `
    <section class="cf-static-playlist-set">
      ${tiles.map((tile, index) => {
        const image = tile.photo_library_id ? thumbUrl(tile.photo_library_id, 480, 72) : tile.photo_url || "";
        const body = `
          <article class="cf-static-playlist-tile">
            ${image ? `<img src="${escapeAttr(image)}" alt="${escapeAttr(tile.title || "Playlist")}" loading="${index === 0 ? "eager" : "lazy"}">` : ""}
            <h3>${escapeHtml(tile.title || "")}</h3>
            ${tile.subtitle ? `<p>${escapeHtml(tile.subtitle)}</p>` : ""}
          </article>
        `;
        return wrapStaticLink(body, toFastPlayerPath(tile.player_url || ""));
      }).join("")}
    </section>
  `;
}

function renderTextCard(title, description, className, url = "") {
  if (!title && !description) return "";
  const body = `
    <section class="cf-static-card cf-static-text ${className}">
      <h2>${escapeHtml(title || "")}</h2>
      ${description ? `<p>${escapeHtml(description)}</p>` : ""}
    </section>
  `;
  return wrapStaticLink(body, url);
}

function renderStaticFooter(content) {
  const text = String(content.footer_brand_text || "")
    .trim()
    .replace(
      " — home color transformations",
      " — Home color transformations"
    );
  if (!text) return "";

  return `
      <footer class="cf-static-home__footer">
        <span>${escapeHtml(text)}</span>

        <span class="cf-static-home__footer-links">
          <a href="/privacy">Privacy Policy</a>
          <span aria-hidden="true">·</span>
          <a href="/terms">Terms of Service</a>
          <span aria-hidden="true">·</span>
          <a
            class="cf-static-home__youtube"
            href="https://www.youtube.com/@ColorFixByTerry"
            target="_blank"
            rel="noreferrer"
            aria-label="ColorFix by Terry on YouTube"
          >
            <img
              src="/images/youtube-logo.png"
              alt="YouTube"
            >
          </a>
        </span>
      </footer>
  `;
}

function wrapStaticLink(html, url) {
  const target = String(url || "").trim();
  if (!target) return html;
  return `<a class="cf-static-link" href="${escapeAttr(target)}">${html}</a>`;
}

function renderPreloadLinks(payload) {
  const urls = Array.isArray(payload.image_preloads) ? payload.image_preloads.slice(0, 5) : [];
  return urls.map((url, index) => `\n    <link rel="preload" as="image" href="${escapeAttr(url)}"${index === 0 ? ' fetchpriority="high"' : ""}>`).join("");
}

function stripExistingStaticHome(html) {
  return html
    .replace(/\s*<link rel="preload" as="image" href="\/api\/v2\/image-thumb\.php\?id=[^"]*"(?: fetchpriority="high")?>/g, "")
    .replace(/\s*<style id="cf-static-home-style">[\s\S]*?<\/style>/g, "");
}

function normalizeBuildContent(payload, buildKey) {
  const content = payload && typeof payload.build_content === "object" && payload.build_content !== null
    ? payload.build_content
    : {};
  return { ...buildContentFallbacks(buildKey), ...content };
}

function buildContentFallbacks(buildKey) {
  if (buildKey === "admin_home") {
    return {
      title: "ColorFix Admin",
      robots: "noindex,nofollow",
    };
  }
  return {
    title: "ColorFix by Terry | Home Color Transformations & Paint Palettes",
    meta_description: "ColorFix by Terry helps homeowners explore color transformations with before-and-ColorFixed makeovers, real examples, paint palettes, and color ideas by Terry Marr.",
    robots: "index,follow",
    canonical_url: "https://colorfix.terrymarr.com/",
    footer_brand_text: "ColorFix by Terry — Home color transformations by Terry Marr",
    footer_url: "https://colorfix.terrymarr.com/",
    footer_url_text: "colorfix.terrymarr.com",
  };
}

function applyBuildContentToHead(html, content) {
  let next = html;
  const title = String(content.title || "").trim();
  const description = String(content.meta_description || "").trim();
  const robots = String(content.robots || "").trim();
  const canonicalUrl = String(content.canonical_url || "").trim();
  const ogTitle = String(content.og_title || title || "").trim();
  const ogDescription = String(content.og_description || description || "").trim();
  const ogImage = String(content.og_image || "").trim();

  if (title) {
    next = upsertTitle(next, title);
  }
  next = upsertMetaName(next, "description", description);
  next = upsertMetaName(next, "robots", robots);
  next = upsertCanonical(next, canonicalUrl);
  next = upsertMetaProperty(next, "og:title", ogTitle);
  next = upsertMetaProperty(next, "og:description", ogDescription);
  next = upsertMetaProperty(next, "og:image", ogImage);
  return next;
}

function upsertTitle(html, value) {
  const tag = `<title>${escapeHtml(value)}</title>`;
  if (/<title>[\s\S]*?<\/title>/i.test(html)) {
    return html.replace(/<title>[\s\S]*?<\/title>/i, tag);
  }
  return html.replace("</head>", `    ${tag}\n  </head>`);
}

function upsertMetaName(html, name, value) {
  const pattern = new RegExp(`\\s*<meta\\s+name=["']${escapeRegExp(name)}["'][^>]*>`, "i");
  if (!value) {
    return html.replace(pattern, "");
  }
  const tag = `<meta name="${escapeAttr(name)}" content="${escapeAttr(value)}">`;
  if (pattern.test(html)) {
    return html.replace(pattern, `\n${tag}`);
  }
  return html.replace("</head>", `    ${tag}\n  </head>`);
}

function upsertMetaProperty(html, property, value) {
  const pattern = new RegExp(`\\s*<meta\\s+property=["']${escapeRegExp(property)}["'][^>]*>`, "i");
  if (!value) {
    return html.replace(pattern, "");
  }
  const tag = `<meta property="${escapeAttr(property)}" content="${escapeAttr(value)}">`;
  if (pattern.test(html)) {
    return html.replace(pattern, `\n${tag}`);
  }
  return html.replace("</head>", `    ${tag}\n  </head>`);
}

function upsertCanonical(html, value) {
  const pattern = /\s*<link\s+rel=["']canonical["'][^>]*>/i;
  if (!value) {
    return html.replace(pattern, "");
  }
  const tag = `<link rel="canonical" href="${escapeAttr(value)}">`;
  if (pattern.test(html)) {
    return html.replace(pattern, `\n${tag}`);
  }
  return html.replace("</head>", `    ${tag}\n  </head>`);
}

function replaceRoot(html, staticHtml) {
  if (html.includes("<!-- cf-static-home:start -->")) {
    return html.replace(
      /<!-- cf-static-home:start -->[\s\S]*?<!-- cf-static-home:end -->/,
      staticHtml
    );
  }
  if (html.includes('<div id="root"></div>')) {
    return html.replace('<div id="root"></div>', `<div id="root">${staticHtml}</div>`);
  }
  return html.replace(
    /<div id="root">[\s\S]*<\/div>\s*<\/body>/,
    `<div id="root">${staticHtml}</div>\n  </body>`
  );
}

function mergeWithInserts(results = [], inserts = []) {
  const toPos = (value) => {
    if (value === "" || value == null) return Infinity;
    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : Infinity;
  };
  return [...results, ...inserts]
    .map((item, index) => {
      const resultSort = toPos(item?.sort_order);
      const insertSort = toPos(item?.insert_position);
      return {
        item,
        index,
        sortValue: Number.isFinite(resultSort) ? resultSort : insertSort,
      };
    })
    .sort((a, b) => a.sortValue === b.sortValue ? a.index - b.index : a.sortValue - b.sortValue)
    .map((entry) => entry.item);
}

function isLoginButton(item) {
  return String(item?.target_url || "").replace(/\/+$/, "") === "/login";
}

function thumbUrl(id, width, quality) {
  return `/api/v2/image-thumb.php?id=${encodeURIComponent(String(id))}&w=${width}&q=${quality}`;
}

function toFastPlayerPath(url) {
  const rawUrl = String(url || "").trim();
  if (!rawUrl) return rawUrl;
  try {
    const parsed = new URL(rawUrl, "https://colorfix.terrymarr.com");
    if (parsed.pathname.startsWith("/playlist/")) {
      parsed.pathname = parsed.pathname.replace(/^\/playlist\//, "/p/");
    }
    return `${parsed.pathname}${parsed.search}${parsed.hash}`;
  } catch {
    return rawUrl.replace(/^\/playlist\//, "/p/");
  }
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function escapeAttr(value) {
  return escapeHtml(value);
}

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

function fail(message) {
  console.error(message);
  process.exit(1);
}

function getStaticCss() {
  return `
@import url('https://fonts.googleapis.com/css2?family=Nothing+You+Could+Do&display=swap');

.cf-static-home {
  background: #f5f5f5;
  color: #1f2937;
  font-family: Lato, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  min-height: 100vh;
  padding: 16px max(16px, env(safe-area-inset-right)) 48px max(16px, env(safe-area-inset-left));
}
.cf-static-home__grid {
  box-sizing: border-box;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  max-width: 1320px;
  margin: 0 auto;
}
.cf-static-home__footer {
  color: #4b5563;
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 1rem;
  justify-content: center;
  margin: 2rem auto 0;
  max-width: 1320px;
  text-align: center;
  font-size: 0.9rem;
}
.cf-static-home__footer a {
  color: inherit;
}
.cf-static-home__footer-links {
  align-items: center;
  display: inline-flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.cf-static-home__youtube {
  align-items: center;
  display: inline-flex;
}
.cf-static-home__youtube img {
  display: block;
  height: auto;
  width: 78px;
}
.cf-static-card,
.cf-static-playlist-set {
  box-sizing: border-box;
  border-radius: 14px;
  overflow: hidden;
}
.cf-static-link {
  color: inherit;
  display: block;
  text-decoration: none;
}
.cf-static-link:focus-visible {
  outline: 3px solid #ff8c00;
  outline-offset: 3px;
}
.cf-static-card {
  background: #dfe5e5;
  padding: 16px;
}
.cf-static-blurb {
  container-type: inline-size;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 22px;
}
.cf-static-blurb h1 {
  color: #1f2937;
  font-size: 28px;
  line-height: 1.12;
  margin: 0 0 12px;
}
.cf-static-blurb__seo-logo {
  --cf-static-logo-size: clamp(32px, 13cqw, 44px);
  position: relative;
  display: inline-block;
  width: min-content;
  max-width: 100%;
  padding: 0 0 calc(var(--cf-static-logo-size) * .46);
  margin: 0 0 12px;
  line-height: 1;
}
.cf-static-blurb__seo-logo-main {
  display: inline-block;
  font-family: Poppins, Lato, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  font-size: var(--cf-static-logo-size);
  font-weight: 900;
  letter-spacing: 0;
  line-height: .95;
  white-space: nowrap;
  animation: cf-static-wordmark-settle 360ms ease-out both;
}
.cf-static-blurb__seo-logo-color {
  color: #111;
}
.cf-static-blurb__seo-logo-fix {
  color: #009ca6;
}
.cf-static-blurb__seo-logo-by {
  position: absolute;
  left: 66%;
  top: calc(var(--cf-static-logo-size) * .92);
  display: inline-flex;
  gap: .16em;
  color: rgba(71, 112, 111, .85);
  font-family: "Nothing You Could Do", cursive;
  font-size: clamp(17px, calc(var(--cf-static-logo-size) * .48), 22px);
  font-weight: 400;
  letter-spacing: 0;
  word-spacing: 0;
  line-height: 1;
  text-transform: none;
  transform: rotate(-1.5deg);
  white-space: nowrap;
  clip-path: inset(0 100% 0 0);
  animation: cf-static-signature-reveal 800ms ease-out 350ms both;
  transform-origin: left center;
}
@media (max-width: 700px) {
  .cf-static-blurb__seo-logo {
    --cf-static-logo-size: clamp(28px, 12cqw, 34px);
  }
  .cf-static-blurb__seo-logo-by {
    font-size: clamp(15px, calc(var(--cf-static-logo-size) * .42), 19px);
  }
}
@keyframes cf-static-wordmark-settle {
  from {
    opacity: .94;
    transform: translateY(2px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
@keyframes cf-static-signature-reveal {
  from {
    clip-path: inset(0 100% 0 0);
  }
  to {
    clip-path: inset(0 0 0 0);
  }
}
@media (prefers-reduced-motion: reduce) {
  .cf-static-blurb__seo-logo-main,
  .cf-static-blurb__seo-logo-by {
    animation: none;
  }
  .cf-static-blurb__seo-logo-by {
    clip-path: inset(0 0 0 0);
  }
}
.cf-static-blurb__subtitle,
.cf-static-kicker {
  color: #47706f;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: .12em;
  line-height: 1.15;
  text-transform: uppercase;
}
.cf-static-blurb p,
.cf-static-text p,
.cf-static-featured p,
.cf-static-playlist-tile p {
  margin: 6px 0 0;
  line-height: 1.35;
}
.cf-static-blurb p {
  font-family: Quicksand, Lato, sans-serif;
  font-weight: 600;
}
.cf-static-featured {
  background: #fff;
  border: 1px solid #e6e7eb;
  padding: 0;
}
.cf-static-featured .cf-static-kicker {
  padding: 12px 10px 8px;
  border-bottom: 1px solid #eef0f4;
}
.cf-static-featured__image,
.cf-static-placeholder {
  display: block;
  width: calc(100% - 20px);
  height: 190px;
  margin: 0 10px;
  object-fit: cover;
  background: #eef0f3;
}
.cf-static-featured__body {
  padding: 12px 10px 14px;
}
.cf-static-featured h2,
.cf-static-text h2,
.cf-static-playlist-tile h3 {
  font-family: Poppins, Lato, sans-serif;
  font-size: 19px;
  line-height: 1.14;
  margin: 0;
}
.cf-static-featured span {
  display: inline-block;
  color: #4b6b8a;
  font-size: 13px;
  font-weight: 700;
  margin-top: 10px;
}
.cf-static-text {
  min-height: 98px;
}
.cf-static-text h2 {
  font-size: 20px;
}
.cf-static-playlist-set {
  grid-column: span 2;
  border: 3px solid #1e8a8a;
  background: #f6f3ec;
  padding: 10px;
}
.cf-static-playlist-tile {
  background: #fff;
  border-radius: 14px;
  margin-bottom: 8px;
  padding: 10px;
}
.cf-static-playlist-tile:last-child {
  margin-bottom: 0;
}
.cf-static-playlist-tile img {
  display: block;
  width: 100%;
  aspect-ratio: 4 / 3;
  object-fit: cover;
  border-radius: 12px;
  margin-bottom: 8px;
}
.cf-static-playlist-tile h3 {
  font-size: 16px;
}
@media (min-width: 760px) {
  .cf-static-home {
    padding: 24px 32px 64px;
  }
  .cf-static-home__grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
  }
  .cf-static-playlist-set {
    grid-column: span 1;
    grid-row: span 4;
  }
  .cf-static-featured__image {
    height: 160px;
  }
}
`;
}