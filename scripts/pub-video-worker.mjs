import fs from "node:fs";
import path from "node:path";
import os from "node:os";
import { spawn } from "node:child_process";

const ROOT = process.cwd();

const BASE_URL = String(
  process.env.COLORFIX_BASE_URL || "https://colorfix.terrymarr.com"
).replace(/\/+$/, "");

const POLL_MS = 5000;
const HEARTBEAT_MS = 5000;

/*
 * Existing secret name retained intentionally.
 * The PUB contract is now "video worker", but changing the
 * environment variable is not required for this refactor.
 */
const WORKER_SECRET = String(
  process.env.COLORFIX_RENDER_WORKER_SECRET || ""
).trim();

if (!WORKER_SECRET) {
  throw new Error(
    "COLORFIX_RENDER_WORKER_SECRET is required."
  );
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
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
      if (code === 0) {
        resolve();
      } else {
        reject(
          new Error(`${command} exited with code ${code}`)
        );
      }
    });
  });
}

async function postJson(url, payload = {}) {
  const response = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      ...payload,
      worker_secret: WORKER_SECRET,
    }),
  });

  const text = await response.text();

  let data;

  try {
    data = JSON.parse(text);
  } catch {
    throw new Error(
      `Expected JSON from ${url}, got: ${text.slice(0, 200)}`
    );
  }

  if (!response.ok || !data?.ok) {
    throw new Error(
      data?.error || `Request failed: ${response.status}`
    );
  }

  return data;
}

async function uploadVideo(jobId, outputPath) {
  const form = new FormData();

  form.append(
    "pub_video_job_id",
    String(jobId)
  );

  form.append(
    "worker_secret",
    WORKER_SECRET
  );

  const bytes = fs.readFileSync(outputPath);

  const blob = new Blob(
    [bytes],
    {
      type: "video/mp4",
    }
  );

  form.append(
    "file",
    blob,
    "output.mp4"
  );

  const response = await fetch(
    `${BASE_URL}/api/v2/admin/pub/video-jobs/upload.php`,
    {
      method: "POST",
      body: form,
    }
  );

  const text = await response.text();

  let data;

  try {
    data = JSON.parse(text);
  } catch {
    throw new Error(
      `Expected JSON from upload.php, got: ${text.slice(0, 200)}`
    );
  }

  if (!response.ok || !data?.ok) {
    throw new Error(
      data?.error ||
      `Video upload failed: ${response.status}`
    );
  }

  return data.file;
}

/*
 * GENERIC REMOTION EXECUTOR
 *
 * The worker does not select a product-specific renderer.
 * The Creator has already selected the composition and built the
 * complete render plan. The worker only transports that plan to
 * the generic Remotion executor.
 */
function remotionExecutorPath() {
  const executor = path.join(
    ROOT,
    "scripts",
    "render-remotion-video.mjs"
  );

  if (!fs.existsSync(executor)) {
    throw new Error(
      `Generic Remotion executor not found: ${executor}`
    );
  }

  return executor;
}

function outputPathFor(job) {
  const jobId = Number(
    job?.pub_video_job_id || 0
  );

  if (!jobId) {
    throw new Error("Video job ID missing.");
  }

  const dir = path.join(
    ROOT,
    "exports",
    "pub-video-jobs",
    String(jobId)
  );

  ensureDir(dir);

  return path.join(
    dir,
    "output.mp4"
  );
}

function recipePathFor(job) {
  const jobId = Number(
    job?.pub_video_job_id || 0
  );

  if (!jobId) {
    throw new Error("Video job ID missing.");
  }

  const dir = path.join(
    ROOT,
    "exports",
    "pub-video-jobs",
    String(jobId)
  );

  ensureDir(dir);

  return path.join(
    dir,
    "recipe.json"
  );
}

async function processVideoJob(job) {
  const jobId = Number(
    job.pub_video_job_id
  );

  const assetId = Number(
    job.pub_asset_id
  );

  if (!jobId) {
    throw new Error(
      "Video job ID missing."
    );
  }

  if (!assetId) {
    throw new Error(
      "PUB asset ID missing."
    );
  }

  console.log(
    `\nClaimed PUB asset #${assetId}: ${job.creator_key}`
  );

  await postJson(
    `${BASE_URL}/api/v2/admin/pub/video-jobs/rendering.php`,
    {
      pub_video_job_id: jobId,
    }
  );

  const recipePath =
    recipePathFor(job);

  const outputPath =
    outputPathFor(job);

  const compositionId = String(
    job?.video_recipe_key || ""
  ).trim();

  if (!compositionId) {
    throw new Error(
      "Video job is missing video_recipe_key / composition id."
    );
  }

  if (
    !job?.props ||
    typeof job.props !== "object" ||
    Array.isArray(job.props)
  ) {
    throw new Error(
      "Video job is missing its Creator-owned props."
    );
  }

  if (
    !job.props.render ||
    typeof job.props.render !== "object" ||
    Array.isArray(job.props.render)
  ) {
    throw new Error(
      "Video job is missing Creator-owned render instructions."
    );
  }

  const codec = String(
    job.props.render.codec || ""
  ).trim();

  if (!codec) {
    throw new Error(
      "Video job is missing Creator-owned render.codec."
    );
  }

  /*
   * Per-job Remotion recipe.
   *
   * The Creator owns every value below.
   * The worker adds no product defaults and makes no render choices.
   */
  fs.writeFileSync(
    recipePath,
    JSON.stringify(
      {
        composition_id:
          compositionId,

        plan:
          job.props,

        render:
          job.props.render,
      },
      null,
      2
    )
  );

  const rendererScript =
    remotionExecutorPath();

  try {
    await run(
      "node",
      [
        rendererScript,
        `--recipe=${recipePath}`,
        `--output=${outputPath}`,
      ]
    );

    const stat =
      fs.statSync(
        outputPath
      );

    if (!stat.isFile() || stat.size <= 0) {
      throw new Error(
        "Video renderer did not produce a valid output file."
      );
    }

    console.log(
      `Uploading PUB asset #${assetId}...`
    );

    const uploaded =
      await uploadVideo(
        jobId,
        outputPath
      );

    if (!uploaded?.rel_path) {
      throw new Error(
        "Upload did not return a stored video path."
      );
    }

    await postJson(
      `${BASE_URL}/api/v2/admin/pub/video-jobs/complete.php`,
      {
        pub_video_job_id:
          jobId,

        status:
          "complete",

        output_rel_path:
          uploaded.rel_path,

        output_file_size_bytes:
          uploaded.file_size_bytes,
      }
    );

    console.log(
      `Completed PUB asset #${assetId}`
    );

    console.log(
      outputPath
    );

  } catch (error) {
    const message =
      error?.message ||
      "Video rendering failed.";

    console.error(
      `PUB asset #${assetId} failed: ${message}`
    );

    try {
      await postJson(
        `${BASE_URL}/api/v2/admin/pub/video-jobs/complete.php`,
        {
          pub_video_job_id:
            jobId,

          status:
            "failed",

          error_message:
            message,
        }
      );

    } catch (reportError) {
      console.error(
        `Could not report failure for PUB asset #${assetId}:`,
        reportError?.message ||
        reportError
      );
    }
  }
}

async function claimNextVideoJob() {
  const data =
    await postJson(
      `${BASE_URL}/api/v2/admin/pub/video-jobs/claim.php`
    );

  return data?.job || null;
}

let heartbeatInFlight = false;

async function sendHeartbeat() {
  if (heartbeatInFlight) {
    return;
  }

  heartbeatInFlight = true;

  try {
    await postJson(
      `${BASE_URL}/api/v2/admin/pub/video-jobs/heartbeat.php`,
      {
        worker_name:
          os.hostname(),

        pid:
          process.pid,

        implementation:
          "local-remotion",
      }
    );

  } finally {
    heartbeatInFlight = false;
  }
}

async function main() {
  await sendHeartbeat();

  const heartbeatTimer =
    setInterval(
      () => {
        sendHeartbeat()
          .catch((error) => {
            console.error(
              "PUB video worker heartbeat failed:",
              error?.message ||
              error
            );
          });
      },
      HEARTBEAT_MS
    );

  heartbeatTimer.unref();

  console.log(
    "PUB local video worker started."
  );

  console.log(
    `Server: ${BASE_URL}`
  );

  console.log(
    `Heartbeat every ${HEARTBEAT_MS / 1000} seconds.`
  );

  console.log(
    `Polling every ${POLL_MS / 1000} seconds.`
  );

  while (true) {
    try {
      const job =
        await claimNextVideoJob();

      if (job) {
        await processVideoJob(
          job
        );
      }

    } catch (error) {
      console.error(
        "PUB video worker error:",
        error?.message ||
        error
      );
    }

    await sleep(
      POLL_MS
    );
  }
}

main().catch((error) => {
  console.error(
    error?.message ||
    error
  );

  process.exit(1);
});