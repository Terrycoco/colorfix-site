import fs from "node:fs";
import http from "node:http";
import https from "node:https";
import path from "node:path";
import { spawn } from "node:child_process";
import { URL } from "node:url";
import { YOUTUBE_VIDEO_TIMING } from "../src/remotion/youtubeVideoTiming.js";

const ROOT = process.cwd();
const BASE_URL = String(process.env.COLORFIX_BASE_URL || "https://colorfix.terrymarr.com").replace(/\/+$/, "");
const OUT_DIR = path.join(ROOT, "exports");
const PROPS_DIR = path.join(OUT_DIR, "youtube-video-props");

function readArg(name) {
  const prefix = `--${name}=`;
  const match = process.argv.find((arg) => arg.startsWith(prefix));
  return match ? match.slice(prefix.length) : "";
}

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
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

function hasYoutubeVenue(item) {
  const analyzerRole = String(item?.analyzer_role || "").trim().toLowerCase();
  const ignored = analyzerRole === "ignore";
  const youtubeEnabled = item?.yt === true || Number(item?.yt) === 1;
  return !ignored && youtubeEnabled;
}

function flagLabel(value, fallback = "missing") {
  if (value == null) return fallback;
  return Number(value) === 0 ? "0" : "1";
}

function describeItem(item, index) {
  const id = item?.playlist_item_id || item?.id || "?";
  const type = item?.type || item?.item_type || "normal";
  const title = String(item?.title || "").trim() || "(untitled)";
  return [
    `#${index + 1}`,
    `id=${id}`,
    `type=${type}`,
    `site=${flagLabel(item?.site)}`,
    `yt=${flagLabel(item?.yt)}`,
    `ignore=${String(item?.analyzer_role || "").trim().toLowerCase() === "ignore" ? "1" : "0"}`,
    `"${title}"`,
  ].join(" ");
}

function normalizeImageUrl(value) {
  const raw = String(value || "").trim();
  if (!raw) return "";
  if (raw.includes("|")) {
    const [prefix, resolved] = raw.split("|", 2);
    if (String(resolved || "").trim()) {
      const url = String(resolved).trim();
      if (/^https?:\/\//i.test(url)) return `${prefix}|${url}`;
      if (url.startsWith("/")) return `${prefix}|${BASE_URL}${url}`;
      return `${prefix}|${BASE_URL}/${url}`;
    }
  }
  if (/^https?:\/\//i.test(raw)) return raw;
  if (raw.startsWith("/")) return `${BASE_URL}${raw}`;
  if (raw.startsWith("asset:") || raw.startsWith("photo:")) return raw;
  return `${BASE_URL}/${raw}`;
}

function slideDuration(item) {
  const explicit = Number(item?.duration_ms || 0);
  const type = String(item?.type || item?.item_type || "normal").toLowerCase().trim();
  if (type === "brand-bumper") {
    return Math.max(YOUTUBE_VIDEO_TIMING.defaultBrandBumperDurationMs, explicit || 0);
  }
  if (explicit > 0) return explicit;
  if (type === "intro") return YOUTUBE_VIDEO_TIMING.defaultIntroDurationMs;
  if (type === "text") return YOUTUBE_VIDEO_TIMING.defaultTextDurationMs;
  if (type === "hue-wheel") return YOUTUBE_VIDEO_TIMING.defaultHueWheelDurationMs;
  return YOUTUBE_VIDEO_TIMING.defaultSlideDurationMs;
}

function buildTimeline(items) {
  let cursor = 0;
  return items.map((item, index) => {
    const duration = slideDuration(item);
    const mode = String(item?.transition || "animation").toLowerCase().trim();
    const transitionMs = mode === "cut" ? YOUTUBE_VIDEO_TIMING.cutMs : YOUTUBE_VIDEO_TIMING.dissolveMs;
    const entry = {
      index,
      start_ms: cursor,
      duration_ms: duration,
      end_ms: cursor + duration,
      transition: mode === "cut" ? "cut" : "dissolve",
      transition_ms: transitionMs,
    };
    cursor += duration;
    return entry;
  });
}

async function buildPlan(playlistId) {
  const payload = await fetchJson(`${BASE_URL}/api/v2/admin/playlists/get.php?playlist_id=${playlistId}&_=${Date.now()}`);
  if (!payload?.ok) {
    throw new Error(payload?.error || `Failed to load playlist ${playlistId}`);
  }
  const rawItems = payload.items || [];
  const items = rawItems.filter(hasYoutubeVenue)
    .map((item) => ({
      ...item,
      type: item.type || item.item_type || "normal",
      body: item.body || "",
      image_url: normalizeImageUrl(item.image_url),
    }));

  if (!items.length) {
    throw new Error(`Playlist ${playlistId} does not have non-ignored YouTube-enabled slides.`);
  }

  console.log(`Fetched ${rawItems.length} active playlist items from ${BASE_URL}.`);
  console.log(`Rendering ${items.length} YouTube-enabled playlist items.`);
  items.forEach((item, index) => {
    console.log(`  ${describeItem(item, index)}`);
  });

  return {
    playlist_id: Number(payload.playlist?.playlist_id || playlistId),
    title: payload.playlist?.title || `Playlist ${playlistId}`,
    type: payload.playlist?.type || "playlist",
    total_items: items.length,
    items,
    video: {
      width: YOUTUBE_VIDEO_TIMING.width,
      height: YOUTUBE_VIDEO_TIMING.height,
      fps: YOUTUBE_VIDEO_TIMING.fps,
      default_slide_duration_ms: YOUTUBE_VIDEO_TIMING.defaultSlideDurationMs,
      default_intro_duration_ms: YOUTUBE_VIDEO_TIMING.defaultIntroDurationMs,
      default_text_duration_ms: YOUTUBE_VIDEO_TIMING.defaultTextDurationMs,
      default_hue_wheel_duration_ms: YOUTUBE_VIDEO_TIMING.defaultHueWheelDurationMs,
      default_brand_bumper_duration_ms: YOUTUBE_VIDEO_TIMING.defaultBrandBumperDurationMs,
      dissolve_ms: YOUTUBE_VIDEO_TIMING.dissolveMs,
      cut_ms: YOUTUBE_VIDEO_TIMING.cutMs,
      caption_delay_after_photo_ms: YOUTUBE_VIDEO_TIMING.captionDelayAfterPhotoMs,
      caption_fade_ms: YOUTUBE_VIDEO_TIMING.captionFadeMs,
      signature_reveal_delay_ms: YOUTUBE_VIDEO_TIMING.signatureRevealDelayMs,
      signature_reveal_duration_ms: YOUTUBE_VIDEO_TIMING.signatureRevealDurationMs,
      final_fade_ms: YOUTUBE_VIDEO_TIMING.finalFadeMs,
      timeline: buildTimeline(items),
    },
  };
}

async function musicFromAsset(assetId, volume) {
  const id = Number(assetId || 0);
  if (!Number.isFinite(id) || id <= 0) return null;
  const payload = await fetchJson(`${BASE_URL}/api/v2/admin/asset-library/list.php?q=${encodeURIComponent(String(id))}&asset_kind=audio&include_inactive=1&limit=20&_=${Date.now()}`);
  if (!payload?.ok) {
    throw new Error(payload?.error || `Failed to load music asset ${id}`);
  }
  const items = Array.isArray(payload.items) ? payload.items : [];
  const asset = items.find((item) => Number(item?.asset_library_id || 0) === id);
  if (!asset) {
    throw new Error(`Music asset not found: ${id}`);
  }
  const relPath = String(asset.rel_path || "").trim();
  const src = String(asset.public_url || normalizeImageUrl(relPath)).trim();
  if (!src) {
    throw new Error(`Music asset ${id} has no public URL/path.`);
  }
  const normalizedVolume = Number.isFinite(Number(volume))
    ? Math.max(0, Math.min(1, Number(volume)))
    : 0.35;
  return {
    asset_library_id: id,
    title: String(asset.title || ""),
    src,
    public_url: src,
    rel_path: relPath,
    mime_type: String(asset.mime_type || ""),
    volume: normalizedVolume,
  };
}

function readRecipePlan(recipePath) {
  const raw = fs.readFileSync(recipePath, "utf8");
  const data = JSON.parse(raw);
  const plan = data?.plan || data;
  if (!plan || typeof plan !== "object") {
    throw new Error(`Recipe file does not contain a render plan: ${recipePath}`);
  }
  if (!Array.isArray(plan.items) || !plan.items.length) {
    throw new Error(`Recipe render plan has no items: ${recipePath}`);
  }
  return plan;
}

function run(command, args) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, {
      cwd: ROOT,
      stdio: "inherit",
      env: process.env,
    });
    child.on("error", reject);
    child.on("exit", (code) => {
      if (code === 0) resolve();
      else reject(new Error(`${command} exited with code ${code}`));
    });
  });
}

async function main() {
  const recipePath = readArg("recipe");
  const explicitOutput = readArg("output");
  const musicAssetId = Number(readArg("music-asset-id") || readArg("music") || 0);
  const musicVolume = readArg("music-volume") || readArg("volume") || "0.35";
  const playlistId = Number(process.argv[2] || readArg("playlist-id") || readArg("playlist") || 37);
  if (!recipePath && (!Number.isFinite(playlistId) || playlistId <= 0)) {
    throw new Error("Usage: npm run render-youtube-video -- 37");
  }

  ensureDir(OUT_DIR);
  ensureDir(PROPS_DIR);

  const plan = recipePath ? readRecipePlan(recipePath) : await buildPlan(playlistId);
  if (musicAssetId > 0) {
    plan.music = await musicFromAsset(musicAssetId, musicVolume);
    console.log(`Music: #${plan.music.asset_library_id} ${plan.music.title || plan.music.src} @ volume ${plan.music.volume}`);
  }
  const planId = Number(plan.playlist_id || playlistId || 0) || "recipe";
  const propsPath = recipePath || path.join(PROPS_DIR, `playlist-${planId}.json`);
  const outputPath = explicitOutput || path.join(OUT_DIR, `colorfix-youtube-video-${planId}.mp4`);
  ensureDir(path.dirname(outputPath));
  fs.writeFileSync(propsPath, JSON.stringify({ plan }, null, 2));

  await run("npx", [
    "remotion",
    "render",
    "src/remotion/index.jsx",
    "colorfix-youtube-video",
    outputPath,
    `--props=${propsPath}`,
    "--overwrite",
    "--codec=h264",
  ]);

  console.log(`Rendered ${plan.title}`);
  console.log(`Playlist: ${plan.playlist_id}`);
  console.log(`Slides: ${plan.total_items}`);
  console.log(outputPath);
}

main().catch((err) => {
  console.error(err?.message || err);
  process.exit(1);
});
