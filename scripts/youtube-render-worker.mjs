import fs from "node:fs/promises";
import path from "node:path";
import { spawn } from "node:child_process";
import { YOUTUBE_VIDEO_TIMING } from "../src/remotion/youtubeVideoTiming.js";

const ROOT = process.cwd();
const BASE_URL = String(process.env.COLORFIX_BASE_URL || "https://colorfix.terrymarr.com").replace(/\/+$/, "");
const WORK_DIR = path.join(ROOT, "exports", "youtube-worker");
let TOKEN = "";

async function loadEnvFile(filePath) {
  try {
    const raw = await fs.readFile(filePath, "utf8");
    for (const line of raw.split(/\r?\n/)) {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith("#")) continue;
      const index = trimmed.indexOf("=");
      if (index <= 0) continue;
      const key = trimmed.slice(0, index).trim();
      const value = trimmed.slice(index + 1).trim().replace(/^["']|["']$/g, "");
      if (key && process.env[key] == null) {
        process.env[key] = value;
      }
    }
  } catch (error) {
    if (error?.code !== "ENOENT") throw error;
  }
}

function arg(name, fallback = "") {
  const prefix = `--${name}=`;
  const found = process.argv.find((value) => value.startsWith(prefix));
  return found ? found.slice(prefix.length) : fallback;
}

function hasFlag(name) {
  return process.argv.includes(`--${name}`);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function clampVolume(value) {
  const number = Number(value);
  if (!Number.isFinite(number)) return 0.35;
  return Math.max(0, Math.min(1, number));
}

function absoluteColorFixUrl(value) {
  const raw = String(value || "").trim();
  if (!raw) return "";
  if (/^https?:\/\//i.test(raw)) return raw;
  return `${BASE_URL}/${raw.replace(/^\/+/, "")}`;
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
    const transition = String(item?.transition || "animation").toLowerCase().trim() === "cut" ? "cut" : "dissolve";
    const entry = {
      index,
      start_ms: cursor,
      duration_ms: duration,
      end_ms: cursor + duration,
      transition,
      transition_ms: transition === "cut" ? YOUTUBE_VIDEO_TIMING.cutMs : YOUTUBE_VIDEO_TIMING.dissolveMs,
    };
    cursor += duration;
    return entry;
  });
}

function musicFromRecipe(recipe) {
  const music = recipe?.music && typeof recipe.music === "object"
    ? recipe.music
    : (recipe?.source?.music && typeof recipe.source.music === "object" ? recipe.source.music : null);
  if (!music) return null;
  const src = absoluteColorFixUrl(music.public_url || music.src || music.rel_path || "");
  if (!src) return null;
  return {
    asset_library_id: Number(music.asset_library_id || 0) || null,
    title: String(music.title || ""),
    src,
    public_url: src,
    rel_path: String(music.rel_path || ""),
    mime_type: String(music.mime_type || ""),
    volume: clampVolume(music.volume),
  };
}

function planFromRecipe(recipe) {
  const rows = Array.isArray(recipe?.video_rows) ? recipe.video_rows : (Array.isArray(recipe?.pairs) ? recipe.pairs : []);
  const row = rows.find((candidate) => candidate && typeof candidate === "object" && candidate.include !== false);
  if (!row) throw new Error("YouTube recipe has no included video row.");

  const slides = Array.isArray(row.slides) ? row.slides : [];
  const items = slides.flatMap((slide, index) => {
    if (!slide || typeof slide !== "object") return [];
    const asset = slide.asset && typeof slide.asset === "object" ? slide.asset : {};
    const itemType = String(slide.item_type || slide.type || "normal");
    const imageUrl = absoluteColorFixUrl(asset.public_url || slide.image_url || slide.public_url || "");
    if (!imageUrl && itemType.toLowerCase().trim() !== "brand-bumper") return [];
    return [{
      playlist_item_id: slide.playlist_item_id ? Number(slide.playlist_item_id) : null,
      type: itemType,
      item_type: itemType,
      title: String(slide.title || asset.title || ""),
      subtitle: String(slide.subtitle || ""),
      body: String(slide.body || ""),
      image_url: imageUrl,
      duration_ms: slide.duration_ms ? Number(slide.duration_ms) : null,
      sort_order: index + 1,
    }];
  });
  if (!items.length) throw new Error("YouTube recipe has no renderable slides.");

  const title = String(row.search_title || row.title || recipe?.source?.title || "ColorFix YouTube Video").trim();
  return {
    playlist_id: recipe?.source?.playlist_id ? Number(recipe.source.playlist_id) : null,
    title: title || "ColorFix YouTube Video",
    type: "youtube_worker",
    total_items: items.length,
    items,
    music: musicFromRecipe(recipe),
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

async function api(pathname, options = {}) {
  const res = await fetch(`${BASE_URL}${pathname}`, {
    ...options,
    headers: {
      "X-ColorFix-Worker-Token": TOKEN,
      ...(options.headers || {}),
    },
  });
  const text = await res.text();
  const data = text ? JSON.parse(text) : {};
  if (!res.ok || data?.ok === false) {
    throw new Error(data?.error || `HTTP ${res.status} from ${pathname}`);
  }
  return data;
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

async function claimJob() {
  const jobId = Number(arg("job-id", "0")) || 0;
  return api("/api/v2/admin/asset-creators/worker-claim.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(jobId > 0 ? { asset_creator_job_id: jobId } : {}),
  });
}

async function failJob(jobId, error) {
  await api("/api/v2/admin/asset-creators/worker-complete.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ asset_creator_job_id: jobId, error: String(error?.message || error || "Render failed") }),
  });
}

async function completeJob(jobId, outputPath) {
  const form = new FormData();
  form.append("asset_creator_job_id", String(jobId));
  const buffer = await fs.readFile(outputPath);
  form.append("file", new Blob([buffer], { type: "video/mp4" }), path.basename(outputPath));
  return api("/api/v2/admin/asset-creators/worker-complete.php", {
    method: "POST",
    body: form,
  });
}

async function renderJob(job) {
  const jobId = Number(job?.asset_creator_job_id || 0);
  if (!jobId) throw new Error("Claimed job is missing asset_creator_job_id.");
  const recipe = typeof job.instructions_json === "string" ? JSON.parse(job.instructions_json || "{}") : (job.instructions_json || {});
  const plan = planFromRecipe(recipe);
  await fs.mkdir(WORK_DIR, { recursive: true });
  const recipePath = path.join(WORK_DIR, `job-${jobId}.json`);
  const outputPath = path.join(WORK_DIR, `job-${jobId}.mp4`);
  await fs.writeFile(recipePath, JSON.stringify({ plan }, null, 2));
  console.log(`Rendering YouTube job #${jobId}: ${plan.title}`);
  if (plan.music?.src) {
    console.log(`Music: ${plan.music.title || plan.music.src} @ ${plan.music.volume}`);
  }
  await run("node", ["scripts/render-youtube-video.mjs", `--recipe=${recipePath}`, `--output=${outputPath}`]);
  return outputPath;
}

async function workOnce() {
  const claim = await claimJob();
  if (!claim.claimed) {
    console.log("No queued YouTube video jobs.");
    return false;
  }

  const job = claim.job;
  const jobId = Number(job.asset_creator_job_id);
  try {
    const outputPath = await renderJob(job);
    const result = await completeJob(jobId, outputPath);
    const outputs = result.item?.outputs || [];
    console.log(`Completed YouTube job #${jobId}. Uploaded ${outputs.length} video asset.`);
  } catch (error) {
    console.error(error?.stack || error?.message || error);
    await failJob(jobId, error);
  }
  return true;
}

async function main() {
  await loadEnvFile(path.join(ROOT, ".env.youtube-worker"));
  TOKEN = String(process.env.COLORFIX_WORKER_TOKEN || "").trim();
  if (!TOKEN) {
    throw new Error("Set COLORFIX_WORKER_TOKEN before running the YouTube render worker.");
  }
  const once = hasFlag("once") || Number(arg("job-id", "0")) > 0;
  const intervalMs = Math.max(5000, Number(arg("interval", "15000")) || 15000);
  do {
    const worked = await workOnce();
    if (once) break;
    await sleep(worked ? 1000 : intervalMs);
  } while (true);
}

main().catch((error) => {
  console.error(error?.message || error);
  process.exit(1);
});
