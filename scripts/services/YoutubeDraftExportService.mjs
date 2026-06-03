import { createRequire } from "node:module";
import fs from "node:fs";
import http from "node:http";
import https from "node:https";
import path from "node:path";
import { URL } from "node:url";

const require = createRequire(import.meta.url);
const PptxGenJS = require("pptxgenjs");
const { imageSize } = require("image-size");
const JSZip = require("jszip");

const SLIDE_W = 13.333;
const SLIDE_H = 7.5;
const COLORFIX_TEAL = "00A6A6";
const ORANGE = "F47C00";
const DARK = "111111";
const BLACK = "000000";
const WHITE = "FFFFFF";
const PLAYER_DISSOLVE_MS = 2000;
const PLAYER_CUT_MS = 200;
const PLAYER_TEXT_FADE_MS = 1200;

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function recreateDir(dir) {
  fs.rmSync(dir, { recursive: true, force: true });
  ensureDir(dir);
}

function imageDimensions(filePath) {
  const dimensions = imageSize(filePath);
  return {
    width: dimensions.width,
    height: dimensions.height,
    aspect: dimensions.width / dimensions.height,
  };
}

function containRect(img, box) {
  const imageAspect = img.width / img.height;
  const boxAspect = box.w / box.h;
  if (imageAspect > boxAspect) {
    const h = box.w / imageAspect;
    return { x: box.x, y: box.y + (box.h - h) / 2, w: box.w, h };
  }
  const w = box.h * imageAspect;
  return { x: box.x + (box.w - w) / 2, y: box.y, w, h: box.h };
}

function sanitizeFilenamePart(value) {
  return String(value || "")
    .trim()
    .replace(/[^a-z0-9_-]+/gi, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 80);
}

function normalizeBaseUrl(baseUrl) {
  return String(baseUrl || "https://colorfix.terrymarr.com").replace(/\/+$/, "");
}

function imageUrlFromPlayerValue(value, baseUrl) {
  const raw = String(value || "").trim();
  if (!raw) return "";
  const withoutPhotoPrefix = raw.includes("|") ? raw.split("|").pop() : raw;
  if (/^https?:\/\//i.test(withoutPhotoPrefix)) return withoutPhotoPrefix;
  if (withoutPhotoPrefix.startsWith("/")) return `${baseUrl}${withoutPhotoPrefix}`;
  return `${baseUrl}/${withoutPhotoPrefix}`;
}

function httpGetBuffer(url) {
  return new Promise((resolve, reject) => {
    const client = url.startsWith("https://") ? https : http;
    const request = client.get(url, (response) => {
      if ([301, 302, 303, 307, 308].includes(response.statusCode || 0) && response.headers.location) {
        response.resume();
        const redirected = new URL(response.headers.location, url).toString();
        httpGetBuffer(redirected).then(resolve, reject);
        return;
      }
      if ((response.statusCode || 0) < 200 || (response.statusCode || 0) >= 300) {
        response.resume();
        reject(new Error(`Failed to fetch ${url}: HTTP ${response.statusCode}`));
        return;
      }
      const chunks = [];
      response.on("data", (chunk) => chunks.push(chunk));
      response.on("end", () => resolve(Buffer.concat(chunks)));
    });
    request.on("error", reject);
    request.setTimeout(30000, () => {
      request.destroy(new Error(`Timed out fetching ${url}`));
    });
  });
}

async function fetchJson(url) {
  const buffer = await httpGetBuffer(url);
  return JSON.parse(buffer.toString("utf8"));
}

async function downloadImage(url, destinationDir, fallbackName) {
  const parsed = new URL(url);
  const ext = path.extname(parsed.pathname) || ".jpg";
  const basename = sanitizeFilenamePart(path.basename(parsed.pathname, ext)) || fallbackName;
  const filePath = path.join(destinationDir, `${basename}${ext}`);
  const buffer = await httpGetBuffer(url);
  fs.writeFileSync(filePath, buffer);
  return filePath;
}

function labelForItem(item, index) {
  const photoType = String(item?.saved_palette_photo_type || "").toLowerCase();
  const title = String(item?.title || "").toLowerCase();
  if (photoType === "before" || title.includes("before")) return "BEFORE";
  if (photoType === "after" || photoType === "full" || title.includes("after")) return "AFTER";
  return index === 0 ? "BEFORE" : "AFTER";
}

function captionForItem(item, label) {
  const title = String(item?.title || "").trim();
  const subtitle = String(item?.subtitle || "").trim();
  if (title && subtitle && title.toUpperCase() !== label) return `${title}: ${subtitle}`;
  if (subtitle) return subtitle;
  if (title) return title;
  return label === "BEFORE" ? "Before" : "After";
}

function hasYoutubeVenue(item) {
  return item?.yt == null || item.yt === true || Number(item.yt) !== 0;
}

function textForItem(item) {
  return [
    String(item?.title || "").trim(),
    String(item?.subtitle || "").trim(),
    String(item?.body || "").trim(),
  ].filter(Boolean);
}

function transitionSpecForItem(item, hasImage) {
  const mode = String(item?.transition || "animation").toLowerCase();
  if (mode === "cut") {
    return { type: "fade", speed: "fast", durationMs: PLAYER_CUT_MS };
  }
  return {
    type: "fade",
    speed: hasImage ? "slow" : "med",
    durationMs: hasImage ? PLAYER_DISSOLVE_MS : PLAYER_TEXT_FADE_MS,
  };
}

function transitionXml(spec) {
  if (!spec) return "";
  return `<p:transition spd="${spec.speed || "slow"}" advClick="1"><p:fade/></p:transition>`;
}

function withSlideTransitionXml(xml, spec) {
  const cleanXml = xml.replace(/<p:transition\b[\s\S]*?<\/p:transition>/g, "");
  const transition = transitionXml(spec);
  if (!transition) return cleanXml;
  const clrMapEnd = "</p:clrMapOvr>";
  if (cleanXml.includes(clrMapEnd)) {
    return cleanXml.replace(clrMapEnd, `${clrMapEnd}${transition}`);
  }
  return cleanXml.replace("</p:sld>", `${transition}</p:sld>`);
}

async function applySlideTransitions(pptxPath, transitionSpecs) {
  const zip = await JSZip.loadAsync(fs.readFileSync(pptxPath));
  await Promise.all(
    transitionSpecs.map(async (spec, index) => {
      const slidePath = `ppt/slides/slide${index + 1}.xml`;
      const file = zip.file(slidePath);
      if (!file) return;
      const xml = await file.async("string");
      zip.file(slidePath, withSlideTransitionXml(xml, spec));
    })
  );
  const buffer = await zip.generateAsync({
    type: "nodebuffer",
    compression: "DEFLATE",
  });
  fs.writeFileSync(pptxPath, buffer);
}

function addBrand(slide) {
  slide.addText("Color", {
    x: 11.35,
    y: 0.18,
    w: 0.68,
    h: 0.28,
    color: WHITE,
    bold: true,
    fontFace: "Helvetica",
    fontSize: 14,
    margin: 0,
    breakLine: false,
  });
  slide.addText("Fix", {
    x: 12.02,
    y: 0.18,
    w: 0.4,
    h: 0.28,
    color: COLORFIX_TEAL,
    bold: true,
    fontFace: "Helvetica",
    fontSize: 14,
    margin: 0,
  });
}

function addCaption(slide, caption) {
  slide.addShape("roundRect", {
    x: 0.38,
    y: 6.72,
    w: 8.25,
    h: 0.56,
    fill: { color: "000000", transparency: 55 },
    line: { color: "000000", transparency: 100 },
  });
  slide.addText(caption, {
    x: 0.55,
    y: 6.81,
    w: 7.9,
    h: 0.34,
    color: WHITE,
    fontFace: "Helvetica",
    fontSize: 13,
    bold: false,
    fit: "shrink",
    margin: 0.02,
  });
}

function addLabel(slide, label) {
  slide.addText(label, {
    x: 0.36,
    y: 0.34,
    w: 1.12,
    h: 0.32,
    color: WHITE,
    fill: { color: label === "BEFORE" ? DARK : ORANGE, transparency: 0 },
    fontFace: "Helvetica",
    fontSize: 10.5,
    bold: true,
    align: "center",
    valign: "mid",
    margin: 0.04,
    breakLine: false,
  });
}

function addImageSlide(pptx, { imagePath, label, caption }) {
  const slide = pptx.addSlide();
  slide.background = { color: BLACK };
  slide.addShape("rect", {
    x: 0,
    y: 0,
    w: SLIDE_W,
    h: SLIDE_H,
    fill: { color: BLACK },
    line: { color: BLACK, transparency: 100 },
  });
  const dims = imageDimensions(imagePath);
  const slideAspect = SLIDE_W / SLIDE_H;
  const aspectDiff = Math.abs(dims.aspect - slideAspect) / slideAspect;
  const useBlurFiller = aspectDiff > 0.11;

  if (useBlurFiller) {
    const fitted = containRect(dims, { x: 0, y: 0, w: SLIDE_W, h: SLIDE_H });
    slide.addImage({
      path: imagePath,
      x: fitted.x,
      y: fitted.y,
      w: fitted.w,
      h: fitted.h,
    });
  } else {
    slide.addImage({
      path: imagePath,
      x: 0,
      y: 0,
      w: SLIDE_W,
      h: SLIDE_H,
      sizing: { type: "cover", x: 0, y: 0, w: SLIDE_W, h: SLIDE_H },
    });
  }

  addLabel(slide, label);
  addCaption(slide, caption);
}

function addTitleSlide(pptx, title) {
  const slide = pptx.addSlide();
  slide.background = { color: DARK };
  slide.addShape("rect", {
    x: 0,
    y: 0,
    w: SLIDE_W,
    h: SLIDE_H,
    fill: { color: DARK },
    line: { color: DARK, transparency: 100 },
  });
  slide.addText("Color", {
    x: 0.82,
    y: 0.82,
    w: 1.1,
    h: 0.35,
    color: WHITE,
    bold: true,
    fontFace: "Helvetica",
    fontSize: 18,
    margin: 0,
  });
  slide.addText("Fix", {
    x: 1.85,
    y: 0.82,
    w: 0.6,
    h: 0.35,
    color: COLORFIX_TEAL,
    bold: true,
    fontFace: "Helvetica",
    fontSize: 18,
    margin: 0,
  });
  slide.addShape("rect", {
    x: 0.86,
    y: 1.68,
    w: 0.84,
    h: 0.08,
    fill: { color: ORANGE },
    line: { color: ORANGE, transparency: 100 },
  });
  slide.addText(title, {
    x: 0.78,
    y: 2.18,
    w: 11.8,
    h: 1.32,
    color: WHITE,
    fontFace: "Helvetica",
    fontSize: 44,
    bold: true,
    fit: "shrink",
    margin: 0,
  });
  slide.addText("YouTube draft export", {
    x: 0.82,
    y: 3.7,
    w: 7.2,
    h: 0.42,
    color: "D7D7D7",
    fontFace: "Helvetica",
    fontSize: 18,
    margin: 0,
  });
}

function addTextSlide(pptx, item) {
  const lines = textForItem(item);
  const slide = pptx.addSlide();
  slide.background = { color: DARK };
  addBrand(slide);
  slide.addText(lines[0] || "ColorFix", {
    x: 1.1,
    y: 2.5,
    w: 11.1,
    h: 0.62,
    color: WHITE,
    fontFace: "Helvetica",
    fontSize: 34,
    bold: true,
    fit: "shrink",
    align: "center",
    margin: 0,
  });
  if (lines[1]) {
    slide.addText(lines[1], {
      x: 1.2,
      y: 3.22,
      w: 10.9,
      h: 0.45,
      color: "D7D7D7",
      fontFace: "Helvetica",
      fontSize: 21,
      fit: "shrink",
      align: "center",
      margin: 0,
    });
  }
  if (lines[2]) {
    slide.addText(lines[2], {
      x: 1.55,
      y: 4.05,
      w: 10.25,
      h: 1.18,
      color: WHITE,
      fontFace: "Helvetica",
      fontSize: 18,
      fit: "shrink",
      align: "center",
      margin: 0.02,
    });
  }
}

function createDeck(title) {
  const pptx = new PptxGenJS();
  pptx.layout = "LAYOUT_WIDE";
  pptx.author = "ColorFix";
  pptx.subject = "ColorFix YouTube Draft";
  pptx.title = title;
  pptx.company = "ColorFix";
  pptx.lang = "en-US";
  pptx.theme = {
    headFontFace: "Helvetica",
    bodyFontFace: "Helvetica",
    lang: "en-US",
  };
  return pptx;
}

export class YoutubeDraftExportService {
  constructor({ rootDir, baseUrl = "https://colorfix.terrymarr.com", outDir, assetDir } = {}) {
    this.rootDir = rootDir || process.cwd();
    this.baseUrl = normalizeBaseUrl(baseUrl);
    this.outDir = outDir || path.join(this.rootDir, "exports");
    this.assetDir = assetDir || path.join(this.outDir, "youtube-draft-assets");
  }

  async loadAdminPlaylist(playlistId) {
    const id = Number(playlistId);
    if (!Number.isFinite(id) || id <= 0) {
      throw new Error("A playlist id is required.");
    }
    const url = `${this.baseUrl}/api/v2/admin/playlists/get.php?playlist_id=${id}`;
    const payload = await fetchJson(url);
    if (!payload?.ok) {
      throw new Error(payload?.error || `Failed to load playlist ${id}`);
    }
    return payload;
  }

  async exportPlaylist(playlistId) {
    ensureDir(this.outDir);
    const adminPlaylist = await this.loadAdminPlaylist(playlistId);
    const id = Number(adminPlaylist.playlist?.playlist_id || playlistId);
    const title = adminPlaylist.playlist?.title || `Playlist ${id}`;
    const deckAssetDir = path.join(this.assetDir, String(id));
    recreateDir(deckAssetDir);

    const pptx = createDeck(title);

    let slideCount = 0;
    const transitionSpecs = [];
    for (const [index, item] of (adminPlaylist.items || []).filter(hasYoutubeVenue).entries()) {
      const imageUrl = imageUrlFromPlayerValue(item?.image_url, this.baseUrl);
      if (!imageUrl) {
        if (textForItem(item).length) {
          addTextSlide(pptx, item);
          transitionSpecs.push(transitionSpecForItem(item, false));
          slideCount += 1;
        }
        continue;
      }
      const label = labelForItem(item, index);
      const caption = captionForItem(item, label);
      const localImage = await downloadImage(imageUrl, deckAssetDir, `slide-${index + 1}`);
      addImageSlide(pptx, { imagePath: localImage, label, caption });
      transitionSpecs.push(transitionSpecForItem(item, true));
      slideCount += 1;
    }

    if (slideCount === 0) {
      throw new Error(`Playlist ${id} does not have YouTube-enabled exportable slides.`);
    }

    const outputPath = path.join(this.outDir, `colorfix-youtube-draft-${id}.pptx`);
    await pptx.writeFile({ fileName: outputPath });
    await applySlideTransitions(outputPath, transitionSpecs);

    return {
      outputPath,
      playlistId: id,
      title,
      slideCount,
      transitionSpecs,
    };
  }
}
