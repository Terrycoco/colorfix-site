import fs from "node:fs/promises";
import path from "node:path";
import { spawn } from "node:child_process";
import { parseMedia } from "@remotion/media-parser";
import { nodeReader } from "@remotion/media-parser/node";
import { YOUTUBE_VIDEO_TIMING } from "../src/remotion/youtubeVideoTiming.js";

const ROOT = process.cwd();
const BASE_URL = String(process.env.COLORFIX_BASE_URL || "https://colorfix.terrymarr.com").replace(/\/+$/, "");
const PREVIEW_DIR = path.join(ROOT, "exports", "youtube-preview", "current");
const RECIPE_PATH = path.join(PREVIEW_DIR, "recipe.json");
const OUTPUT_PATH = path.join(PREVIEW_DIR, "preview.mp4");

function arg(name, fallback = "") {
  const prefix = `--${name}=`;
  const found = process.argv.find((value) => value.startsWith(prefix));
  return found ? found.slice(prefix.length) : fallback;
}

function jobIdArg() {
  return Number(process.argv[2] || arg("job-id") || arg("job") || 0) || 0;
}

function shouldOpenPreview() {
  return !process.argv.includes("--no-open") && process.env.COLORFIX_OPEN_PREVIEW !== "0";
}

function openAppArg() {
  return arg("open-app", process.env.COLORFIX_PREVIEW_APP || "Google Chrome");
}

async function fetchJson(pathname) {
  const response = await fetch(`${BASE_URL}${pathname}`);
  const text = await response.text();
  let data = null;
  try {
    data = text ? JSON.parse(text) : {};
  } catch {
    throw new Error(`Expected JSON from ${pathname}, got: ${text.slice(0, 160)}`);
  }
  if (!response.ok || data?.ok === false) {
    throw new Error(data?.error || `HTTP ${response.status} from ${pathname}`);
  }
  return data;
}

async function latestYoutubeJobId() {
  const params = new URLSearchParams({
    creator_key: "youtube.playlist_video",
    _: String(Date.now()),
  });
  const data = await fetchJson(`/api/v2/admin/asset-creators/list.php?${params.toString()}`);
  const jobs = Array.isArray(data.items) ? data.items : [];
  const youtubeJobs = jobs.filter((item) => String(item.creator_key || "") === "youtube.playlist_video");
  const job = youtubeJobs.find((item) => Number(item.output_count || 0) > 0) || youtubeJobs[0];
  const id = Number(job?.asset_creator_job_id || 0);
  if (!id) {
    throw new Error("No saved YouTube analyzer jobs found. Click Save Recipe in the live admin first.");
  }
  return id;
}

async function loadJob(jobId) {
  const params = new URLSearchParams({
    id: String(jobId),
    _: String(Date.now()),
  });
  const data = await fetchJson(`/api/v2/admin/asset-creators/detail.php?${params.toString()}`);
  const job = data.item || {};
  if (String(job.creator_key || "") !== "youtube.playlist_video") {
    throw new Error(`Job #${jobId} is not a YouTube video analyzer job.`);
  }
  return job;
}

function parseInstructions(job) {
  const raw = job.instructions_json || job.instructions || {};
  if (raw && typeof raw === "object") return raw;
  if (typeof raw === "string" && raw.trim()) return JSON.parse(raw);
  throw new Error(`Job #${job.asset_creator_job_id} has no saved recipe. Click Save Recipe first.`);
}

function absoluteColorFixUrl(value) {
  const raw = String(value || "").trim();
  if (!raw) return "";
  if (/^https?:\/\//i.test(raw)) return raw;
  return `${BASE_URL}/${raw.replace(/^\/+/, "")}`;
}

function clampVolume(value) {
  const number = Number(value);
  if (!Number.isFinite(number)) return 0.35;
  return Math.max(0, Math.min(1, number));
}

function slideDuration(item) {
  const explicit = Number(item?.duration_ms || 0);
  const type = String(item?.type || item?.item_type || "normal").toLowerCase().trim();
  if (type === "brand-bumper") return Math.max(YOUTUBE_VIDEO_TIMING.defaultBrandBumperDurationMs, explicit || 0);
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

function planFromRecipe(recipe, job) {
  const rows = Array.isArray(recipe?.video_rows) ? recipe.video_rows : (Array.isArray(recipe?.pairs) ? recipe.pairs : []);
  const row = rows.find((candidate) => candidate && typeof candidate === "object" && candidate.include !== false);
  if (!row) throw new Error("Saved YouTube recipe has no included video row.");

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
  if (!items.length) throw new Error("Saved YouTube recipe has no renderable slides.");

  const title = String(row.search_title || row.title || recipe?.source?.title || job?.title || "ColorFix YouTube Preview").trim();
  return {
    playlist_id: recipe?.source?.playlist_id ? Number(recipe.source.playlist_id) : Number(job?.source_id || 0) || null,
    title: title || "ColorFix YouTube Preview",
    type: "youtube_preview",
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

function run(command, args) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, { cwd: ROOT, stdio: "inherit", env: process.env });
    child.on("error", reject);
    child.on("exit", (code) => {
      if (code === 0) resolve();
      else reject(new Error(`${command} exited with code ${code}`));
    });
  });
}

async function validatePreview(outputPath) {
  const metadata = await parseMedia({
    src: outputPath,
    reader: nodeReader,
    acknowledgeRemotionLicense: true,
    fields: {
      durationInSeconds: true,
      tracks: true,
      dimensions: true,
    },
  });
  const tracks = Array.isArray(metadata.tracks) ? metadata.tracks : [];
  const hasVideo = tracks.some((track) => track.type === "video");
  const hasAudio = tracks.some((track) => track.type === "audio");
  const duration = Number(metadata.durationInSeconds || 0);
  if (!hasVideo || duration <= 0) {
    throw new Error(`Preview render failed validation: no playable video track in ${outputPath}`);
  }
  console.log(`Preview OK: ${duration.toFixed(1)}s, video ${hasVideo ? "yes" : "no"}, audio ${hasAudio ? "yes" : "no"}`);
}

async function main() {
  const requestedJobId = jobIdArg();
  const jobId = requestedJobId || await latestYoutubeJobId();
  const job = await loadJob(jobId);
  const recipe = parseInstructions(job);
  const plan = planFromRecipe(recipe, job);

  await fs.mkdir(PREVIEW_DIR, { recursive: true });
  await fs.writeFile(RECIPE_PATH, JSON.stringify({ plan }, null, 2));

  console.log(`Rendering local YouTube preview for job #${jobId}: ${plan.title}`);
  console.log(`Preview output: ${OUTPUT_PATH}`);
  await run("node", [
    "scripts/render-youtube-video.mjs",
    `--recipe=${RECIPE_PATH}`,
    `--output=${OUTPUT_PATH}`,
  ]);
  await validatePreview(OUTPUT_PATH);
  if (shouldOpenPreview() && process.platform === "darwin") {
    const openApp = openAppArg();
    const openArgs = openApp ? ["-a", openApp, OUTPUT_PATH] : [OUTPUT_PATH];
    await run("open", openArgs);
  }
}

main().catch((error) => {
  console.error(error?.message || error);
  process.exit(1);
});
